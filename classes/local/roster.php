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
              WHERE o.zoomid = :zoomid AND r.kind = :kind",
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
