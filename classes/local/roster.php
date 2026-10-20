<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Frozen lists of the students and teachers expected at each class.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Freezes who was expected at a class once it is over.
 *
 * Until then the expected users come from the live enrolments and capabilities. Afterwards
 * the frozen list is used, so unenrolling or suspending a user, or changing their role, no
 * longer rewrites past attendance. A class is frozen by the first sync after it ends: who was
 * expected is a fact of the class time, not of when the Zoom report arrives.
 *
 * A class the sync only reaches long after it ended (the first sync after an upgrade, or after
 * cron was down) whose activity is hidden is not frozen: hiding an activity afterwards is
 * common, and freezing then would drop every student from the class for good. It stays live
 * (DEFERRED), as before the lists were frozen.
 *
 * A frozen list can still change in two ways, each kept on the row with who did it:
 * - someone who joined a class before they were enrolled in the course is added to that class
 *   once they are enrolled with the student or teacher capability (SOURCE_JOINED), so the
 *   class counts for them. Only classes they joined: they are never absent from classes held
 *   before they arrived;
 * - users who may edit a class list add or remove people by hand (SOURCE_ADDED, removed), with
 *   a reason. A removed row stays, marked, so the automatic rule never adds that person back.
 */
class roster {
    /** @var string Expected as a student (local/zoomattendance:betracked). */
    public const KIND_STUDENT = 'student';
    /** @var string Expected as a teacher (local/zoomattendance:betrackedteacher). */
    public const KIND_TEACHER = 'teacher';
    /** @var int rosterfrozen value of a class left live, see the class comment. */
    public const DEFERRED = -1;
    /** @var int A class frozen more than this long after its end is frozen late. */
    public const LATE = 6 * HOURSECS;
    /** @var int Classes frozen at most per sync run; the rest wait for the next run. */
    public const BATCH = 2000;
    /** @var string On the list since the class ended. */
    public const SOURCE_FROZEN = 'frozen';
    /** @var string Added automatically: joined the class and was enrolled after it. */
    public const SOURCE_JOINED = 'joined';
    /** @var string Added by hand. */
    public const SOURCE_ADDED = 'added';

    /** @var int|null Classes the current sync run may still freeze, see start_run(). */
    protected static $budget = null;

    /**
     * Start a sync run: limit how many classes it freezes, so a first run after an upgrade
     * spreads the work over several runs.
     *
     * @param int $budget
     */
    public static function start_run(int $budget = self::BATCH): void {
        self::$budget = $budget;
    }

    /**
     * End a sync run: no limit outside one.
     */
    public static function end_run(): void {
        self::$budget = null;
    }

    /**
     * Freeze the expected users of the instance's classes that have ended.
     *
     * @param \stdClass $instance A zoom_source::get_instances() record.
     * @param int|null $now Defaults to the current time.
     * @return int Number of classes frozen or deferred.
     */
    public static function freeze_due(\stdClass $instance, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        if (self::$budget !== null && self::$budget <= 0) {
            return 0;
        }
        $occurrences = $DB->get_records_select(
            'local_zoomattendance_occ',
            'zoomid = :zoomid AND rosterfrozen = 0 AND restored = 0 AND status <> :cancelled AND timeend <= :now',
            ['zoomid' => $instance->id, 'cancelled' => sync::STATUS_CANCELLED, 'now' => $now],
            'timeend',
            '*',
            0,
            self::$budget ?? 0
        );
        if (!$occurrences) {
            return 0;
        }
        try {
            $cm = get_fast_modinfo($instance->course)->get_cm($instance->cmid);
        } catch (\moodle_exception $e) {
            return 0;
        }
        $attendance = new attendance($cm);
        $kinds = [
            self::KIND_STUDENT => $attendance->get_candidates(0, null, 'local/zoomattendance:betracked', false),
            self::KIND_TEACHER => (new teacher_attendance($attendance))->live_candidates(),
        ];
        $groups = self::user_groups((int) $instance->course, array_keys($kinds[self::KIND_STUDENT] + $kinds[self::KIND_TEACHER]));

        foreach (array_chunk($occurrences, 100, true) as $chunk) {
            $transaction = $DB->start_delegated_transaction();
            foreach ($chunk as $occurrence) {
                if (!$cm->visible && $now - (int) $occurrence->timeend > self::LATE) {
                    $DB->set_field('local_zoomattendance_occ', 'rosterfrozen', self::DEFERRED, ['id' => $occurrence->id]);
                    continue;
                }
                $rows = [];
                foreach ($kinds as $kind => $candidates) {
                    foreach ($candidates as $userid => $candidate) {
                        if (attendance::covers($candidate, $occurrence)) {
                            $rows[] = [
                                'occurrenceid' => $occurrence->id,
                                'userid' => $userid,
                                'kind' => $kind,
                                'groupids' => $groups[$userid] ?? '',
                                'source' => self::SOURCE_FROZEN,
                                'timecreated' => $now,
                            ];
                        }
                    }
                }
                if ($rows) {
                    $DB->insert_records('local_zoomattendance_roster', $rows);
                }
                $DB->set_field('local_zoomattendance_occ', 'rosterfrozen', $now, ['id' => $occurrence->id]);
            }
            $transaction->allow_commit();
        }
        if (self::$budget !== null) {
            self::$budget -= count($occurrences);
        }
        return count($occurrences);
    }

    /**
     * The groups of some users in a course, as stored with a frozen list.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return string[] userid => comma-separated group ids, wrapped in commas (",3,7,").
     */
    protected static function user_groups(int $courseid, array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        $groups = [];
        foreach (array_chunk($userids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params['courseid'] = $courseid;
            $rs = $DB->get_recordset_sql(
                "SELECT gm.id, gm.userid, gm.groupid
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE g.courseid = :courseid AND gm.userid $insql
               ORDER BY gm.groupid",
                $params
            );
            foreach ($rs as $row) {
                $groups[(int) $row->userid][] = (int) $row->groupid;
            }
            $rs->close();
        }
        return array_map(function ($ids) {
            return ',' . implode(',', $ids) . ',';
        }, $groups);
    }

    /**
     * Add to the frozen lists of an activity's classes the people who joined a class before
     * they were enrolled in the course: they are added to each class they joined (Zoom matched
     * them to their account, with some time attended) whose list they are not on, when they are
     * now enrolled with the student or teacher capability and were enrolled only after that
     * class ended. Someone enrolled at the time but left off the list (the activity was hidden
     * from them, or restricted to other groups) is not added; nor is anyone removed by hand, nor
     * a teacher outside the activity's responsible teachers.
     *
     * @param \cm_info $cm A Zoom course module.
     * @param int[]|null $userids Limit to these users.
     * @return int Rows added.
     */
    public static function add_joiners(\cm_info $cm, ?array $userids = null): int {
        global $DB;
        if ($userids === []) {
            return 0;
        }
        $params = ['zoomid' => $cm->instance];
        $users = '';
        if ($userids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lzaj');
            $users = "AND r.userid $insql";
            $params += $inparams;
        }
        // Any row, removed ones too, keeps a person from being added again.
        $joined = $DB->get_records_sql(
            "SELECT r.id, r.occurrenceid, r.userid, o.timeend
               FROM {local_zoomattendance_result} r
               JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
              WHERE o.zoomid = :zoomid AND o.rosterfrozen > 0 AND o.restored = 0
                    AND r.userid IS NOT NULL AND r.attendedsecs > 0 $users
                    AND NOT EXISTS (SELECT 1
                                      FROM {local_zoomattendance_roster} ro
                                     WHERE ro.occurrenceid = r.occurrenceid AND ro.userid = r.userid)",
            $params
        );
        if (!$joined) {
            return 0;
        }
        $ids = array_values(array_unique(array_map(function ($row) {
            return (int) $row->userid;
        }, $joined)));
        $enrolled = self::enrolled_since((int) $cm->course, $ids);
        $context = \context_module::instance($cm->id);
        $kinds = [
            self::KIND_STUDENT => self::with_capability($context, 'local/zoomattendance:betracked', $ids),
            self::KIND_TEACHER => self::with_capability($context, 'local/zoomattendance:betrackedteacher', $ids),
        ];
        $responsible = responsible::get((int) $cm->id);
        if ($responsible) {
            $kinds[self::KIND_TEACHER] = array_intersect_key($kinds[self::KIND_TEACHER], array_flip($responsible));
        }
        $groups = self::user_groups((int) $cm->course, $ids);
        $now = time();
        $rows = [];
        foreach ($joined as $row) {
            $userid = (int) $row->userid;
            // Enrolled at the time of the class: left off its list for a reason.
            if (!isset($enrolled[$userid]) || $enrolled[$userid] <= (int) $row->timeend) {
                continue;
            }
            foreach ($kinds as $kind => $holders) {
                if (isset($holders[$userid])) {
                    $rows[] = [
                        'occurrenceid' => (int) $row->occurrenceid,
                        'userid' => $userid,
                        'kind' => $kind,
                        'groupids' => $groups[$userid] ?? '',
                        'source' => self::SOURCE_JOINED,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ];
                }
            }
        }
        if ($rows) {
            $DB->insert_records('local_zoomattendance_roster', $rows);
            data_version::bump_course((int) $cm->course);
        }
        return count($rows);
    }

    /**
     * add_joiners() for every Zoom activity of a course.
     *
     * @param int $courseid
     * @param int[]|null $userids
     * @return int Rows added.
     */
    public static function add_course_joiners(int $courseid, ?array $userids = null): int {
        $added = 0;
        foreach (get_fast_modinfo($courseid)->get_instances_of('zoom') as $cm) {
            if (!$cm->deletioninprogress) {
                $added += self::add_joiners($cm, $userids);
            }
        }
        return $added;
    }

    /**
     * When users were first enrolled in a course, by their current active enrolments.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return int[] userid => time the earliest enrolment was created; users not enrolled are left out.
     */
    protected static function enrolled_since(int $courseid, array $userids): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lzae');
        $params += ['courseid' => $courseid, 'enabled' => ENROL_INSTANCE_ENABLED, 'active' => ENROL_USER_ACTIVE];
        return array_map('intval', $DB->get_records_sql_menu(
            "SELECT ue.userid, MIN(ue.timecreated)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.status = :enabled AND ue.status = :active AND ue.userid $insql
           GROUP BY ue.userid",
            $params
        ));
    }

    /**
     * Which users are enrolled with a capability in an activity, whatever its visibility.
     *
     * @param \context_module $context
     * @param string $capability
     * @param int[] $userids
     * @return bool[] userid => true
     */
    protected static function with_capability(\context_module $context, string $capability, array $userids): array {
        global $DB;
        $join = get_enrolled_with_capabilities_join($context, '', $capability);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lzac');
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT u.id FROM {user} u {$join->joins} WHERE {$join->wheres} AND u.suspended = 0 AND u.id $insql",
            $join->params + $params
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Whether the current user may change a class's list of a kind by hand: students' lists with
     * manage; teachers' lists, which decide teachers' attendance, also with excludetracked while
     * teacher attendance is tracked, as for excluding classes.
     *
     * @param \context_module $context
     * @param string $kind
     * @return bool
     */
    public static function can_edit(\context_module $context, string $kind): bool {
        return $kind === self::KIND_TEACHER ? manual::can_exclude($context)
            : has_capability('local/zoomattendance:manage', $context);
    }

    /**
     * Add someone to a class's list by hand, or back after they were removed.
     *
     * @param \stdClass $occurrence A frozen class.
     * @param \cm_info $cm Its Zoom course module.
     * @param int $userid
     * @param string $kind
     * @param string $reason Required.
     */
    public static function add_by_hand(\stdClass $occurrence, \cm_info $cm, int $userid, string $kind, string $reason): void {
        self::edit($occurrence, $cm, $userid, $kind, $reason, false);
    }

    /**
     * Remove someone from a class's list by hand. The row stays, marked removed, so they are not
     * added back automatically.
     *
     * @param \stdClass $occurrence A frozen class.
     * @param \cm_info $cm Its Zoom course module.
     * @param int $userid
     * @param string $kind
     * @param string $reason Required.
     */
    public static function remove_by_hand(\stdClass $occurrence, \cm_info $cm, int $userid, string $kind, string $reason): void {
        self::edit($occurrence, $cm, $userid, $kind, $reason, true);
    }

    /**
     * Change a class's list by hand.
     *
     * @param \stdClass $occurrence
     * @param \cm_info $cm
     * @param int $userid
     * @param string $kind
     * @param string $reason
     * @param bool $remove
     */
    protected static function edit(
        \stdClass $occurrence,
        \cm_info $cm,
        int $userid,
        string $kind,
        string $reason,
        bool $remove
    ): void {
        global $DB, $USER;
        $reason = \core_text::substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new \coding_exception('A reason is required to change a class list.');
        }
        if (
            (int) $occurrence->rosterfrozen <= 0 || (int) $occurrence->zoomid !== (int) $cm->instance
                || !in_array($kind, [self::KIND_STUDENT, self::KIND_TEACHER], true)
        ) {
            throw new \coding_exception('Only the lists of ended classes can be changed.');
        }
        $now = time();
        $existing = $DB->get_record('local_zoomattendance_roster', [
            'occurrenceid' => $occurrence->id,
            'kind' => $kind,
            'userid' => $userid,
        ]);
        if ($existing) {
            $DB->update_record('local_zoomattendance_roster', (object) [
                'id' => $existing->id,
                'removed' => $remove ? 1 : 0,
                'source' => $remove ? $existing->source : self::SOURCE_ADDED,
                'reason' => $reason,
                'usermodified' => (int) $USER->id,
                'timemodified' => $now,
            ]);
        } else if (!$remove) {
            $groups = self::user_groups((int) $cm->course, [$userid]);
            $DB->insert_record('local_zoomattendance_roster', (object) [
                'occurrenceid' => $occurrence->id,
                'userid' => $userid,
                'kind' => $kind,
                'groupids' => $groups[$userid] ?? '',
                'source' => self::SOURCE_ADDED,
                'reason' => $reason,
                'usermodified' => (int) $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } else {
            return;
        }
        data_version::bump_course((int) $cm->course);
        $class = $remove ? \local_zoomattendance\event\classlist_removed::class
            : \local_zoomattendance\event\classlist_added::class;
        $class::create_for_user($occurrence, $cm, $userid, $kind, $reason)->trigger();
    }

    /**
     * The list rows of some classes, removed ones too.
     *
     * @param int[] $occurrenceids
     * @return \stdClass[][][] occurrence id => kind => userid => row
     */
    public static function entries(array $occurrenceids): array {
        global $DB;
        if (!$occurrenceids) {
            return [];
        }
        $entries = [];
        foreach (array_chunk($occurrenceids, 1000) as $chunk) {
            $rs = $DB->get_recordset_list('local_zoomattendance_roster', 'occurrenceid', $chunk);
            foreach ($rs as $row) {
                $entries[(int) $row->occurrenceid][$row->kind][(int) $row->userid] = $row;
            }
            $rs->close();
        }
        return $entries;
    }

    /**
     * Frozen expected users of an instance.
     *
     * @param int $zoomid
     * @param string $kind One of the KIND_* constants.
     * @return array occurrence id => [userid => groups the user was in then, as ",3,7,"]
     */
    public static function load(int $zoomid, string $kind): array {
        global $DB;
        $rs = $DB->get_recordset_sql(
            "SELECT r.id, r.occurrenceid, r.userid, r.groupids
               FROM {local_zoomattendance_roster} r
               JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
              WHERE o.zoomid = :zoomid AND r.kind = :kind AND r.removed = 0",
            ['zoomid' => $zoomid, 'kind' => $kind]
        );
        $roster = [];
        foreach ($rs as $row) {
            $roster[(int) $row->occurrenceid][(int) $row->userid] = (string) $row->groupids;
        }
        $rs->close();
        return $roster;
    }

    /**
     * Whether a user is on a frozen list of one of a course's classes.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function in_course(int $courseid, int $userid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_zoomattendance_roster} r
               JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
               JOIN {zoom} z ON z.id = o.zoomid
              WHERE z.course = :courseid AND r.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );
    }

    /**
     * Delete the frozen lists of some occurrences, before the occurrences themselves go.
     *
     * @param int[] $occurrenceids
     */
    public static function delete_for_occurrences(array $occurrenceids): void {
        global $DB;
        if ($occurrenceids) {
            $DB->delete_records_list('local_zoomattendance_roster', 'occurrenceid', $occurrenceids);
        }
    }
}
