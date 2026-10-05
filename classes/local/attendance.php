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
 * Read-side evaluation of attendance for one activity.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

use local_zoomattendance\local\source\zoom_source;

/**
 * Evaluates stored results against the expected users and the effective thresholds.
 *
 * Nothing here writes to the database: statuses and expected users are always computed when
 * a report is viewed, so threshold and enrolment changes apply immediately.
 */
class attendance {
    /** @var string Has mapped Zoom sessions; statuses are assigned. */
    public const STATE_EVALUATED = 'evaluated';
    /** @var string Has not ended yet. */
    public const STATE_UPCOMING = 'upcoming';
    /** @var string Ended but mod_zoom has no session for it (not held, or not synced yet). */
    public const STATE_NODATA = 'nodata';
    /** @var string Removed from the mod_zoom schedule before it happened. */
    public const STATE_CANCELLED = 'cancelled';
    /** @var string Excluded by a teacher. */
    public const STATE_EXCLUDED = 'excluded';
    /** @var string The course's Zoom data was reset after it. */
    public const STATE_RESET = 'reset';

    /** @var \cm_info */
    public $cm;
    /** @var \context_module */
    public $context;
    /** @var \stdClass zoom_source::get_instances() record. */
    public $instance;
    /** @var settings */
    public $settings;
    /** @var \stdClass[] Occurrences keyed by id, ordered by start. */
    protected $occurrences;
    /** @var int[] occurrence id => number of mapped sessions. */
    protected $sessioncounts;
    /** @var \stdClass[]|null Users holding betrackedteacher in the module, keyed by id. */
    protected $teacherids;
    /** @var array[] roster kind => occurrence id => [userid => true], see roster(). */
    protected $rosters = [];

    /**
     * Constructor.
     *
     * @param \cm_info $cm A zoom course module.
     */
    public function __construct(\cm_info $cm) {
        $this->cm = $cm;
        $this->context = \context_module::instance($cm->id);
        $instances = zoom_source::get_instances(null, (int) $cm->instance);
        $this->instance = reset($instances);
        $this->settings = settings::from_override(zoom_source::override_from_instance($this->instance));
    }

    /**
     * Occurrences of the activity, ordered by start.
     *
     * @return \stdClass[]
     */
    public function get_occurrences(): array {
        global $DB;
        if ($this->occurrences === null) {
            $this->occurrences = $DB->get_records(
                'local_zoomattendance_occ',
                ['zoomid' => $this->instance->id],
                'timestart, id'
            );
            $this->sessioncounts = $DB->get_records_sql_menu(
                "SELECT occurrenceid, COUNT(1)
                   FROM {local_zoomattendance_session}
                  WHERE zoomid = :zoomid AND occurrenceid IS NOT NULL
               GROUP BY occurrenceid",
                ['zoomid' => $this->instance->id]
            );
        }
        return $this->occurrences;
    }

    /**
     * Number of Zoom sessions mapped to an occurrence.
     *
     * @param \stdClass $occurrence
     * @return int
     */
    public function session_count(\stdClass $occurrence): int {
        $this->get_occurrences();
        return (int) ($this->sessioncounts[$occurrence->id] ?? 0);
    }

    /**
     * State of an occurrence. Only evaluated occurrences get statuses and count in totals.
     *
     * @param \stdClass $occurrence
     * @return string One of the STATE_* constants.
     */
    public function state(\stdClass $occurrence): string {
        if ((int) $occurrence->status === sync::STATUS_EXCLUDED) {
            return self::STATE_EXCLUDED;
        }
        if ((int) $occurrence->status === sync::STATUS_CANCELLED) {
            return self::STATE_CANCELLED;
        }
        if ((int) $occurrence->status === sync::STATUS_RESET) {
            return self::STATE_RESET;
        }
        if ((int) ($occurrence->restored ?? 0)) {
            // Restored from a backup without its Zoom sessions: held when it had session time.
            if ((int) $occurrence->actualsecs > 0) {
                return self::STATE_EVALUATED;
            }
        } else if ($this->session_count($occurrence) > 0) {
            return self::STATE_EVALUATED;
        }
        return (int) $occurrence->timeend > time() ? self::STATE_UPCOMING : self::STATE_NODATA;
    }

    /**
     * Stored results, grouped by occurrence and keyed by identity.
     *
     * @param int[] $occurrenceids
     * @param int|null $userid Limit to one user.
     * @return array occurrence id => [identity key => result row]
     */
    public function get_results(array $occurrenceids, ?int $userid = null): array {
        global $DB;
        if (!$occurrenceids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($occurrenceids, SQL_PARAMS_NAMED);
        $where = "occurrenceid $insql";
        if ($userid !== null) {
            $where .= ' AND userid = :userid';
            $params['userid'] = $userid;
        }
        $grouped = [];
        foreach ($DB->get_records_select('local_zoomattendance_result', $where, $params, 'attendedsecs DESC, id') as $row) {
            $grouped[$row->occurrenceid][$row->identitykey] = $row;
        }
        return $grouped;
    }

    /**
     * Users who can be expected: enrolled, holding local/zoomattendance:betracked (or another
     * capability, such as betrackedteacher), not suspended, and able to access the activity.
     * Each gets their active enrolment windows. Users on the frozen list of a past class are
     * added too, even when they have since left the course (see is_expected()).
     *
     * @param int $groupid Limit to a group (0 for all).
     * @param int[]|null $userids Limit to these users.
     * @param string $capability Capability that makes a user expected.
     * @param bool $withroster Add the users of frozen lists. False gives the live users only.
     * @return \stdClass[] userid => user record with a "windows" list of [timestart, timeend].
     */
    public function get_candidates(
        int $groupid = 0,
        ?array $userids = null,
        string $capability = 'local/zoomattendance:betracked',
        bool $withroster = true
    ): array {
        $users = $this->live_candidates($groupid, $userids, $capability);
        if ($withroster) {
            $kind = $capability === 'local/zoomattendance:betrackedteacher' ? roster::KIND_TEACHER : roster::KIND_STUDENT;
            $users += $this->roster_candidates($kind, $groupid, $userids, $users);
        }
        return $users;
    }

    /**
     * Users on a frozen list of this activity who are not among the given users.
     *
     * @param string $kind A roster::KIND_* constant.
     * @param int $groupid Limit to the current members of a group (0 for all).
     * @param int[]|null $userids Limit to these users.
     * @param \stdClass[] $have Users already known, keyed by id.
     * @return \stdClass[] userid => user record with no enrolment windows.
     */
    public function roster_candidates(string $kind, int $groupid = 0, ?array $userids = null, array $have = []): array {
        $ids = [];
        foreach ($this->roster($kind) as $users) {
            $ids += $users;
        }
        $ids = array_diff_key($ids, $have);
        if ($userids !== null) {
            $ids = array_intersect_key($ids, array_flip($userids));
        }
        if ($ids && $groupid) {
            $ids = array_intersect_key($ids, groups_get_members($groupid, 'u.id'));
        }
        $users = $this->load_users(array_keys($ids));
        foreach ($users as $user) {
            $user->windows = [];
        }
        return $users;
    }

    /**
     * Frozen expected users of this activity.
     *
     * @param string $kind A roster::KIND_* constant.
     * @return array occurrence id => [userid => true]
     */
    protected function roster(string $kind): array {
        if (!isset($this->rosters[$kind])) {
            $this->rosters[$kind] = roster::load((int) $this->instance->id, $kind);
        }
        return $this->rosters[$kind];
    }

    /**
     * Whether a candidate was expected at an occurrence: on its frozen list once it has one,
     * otherwise when their enrolment covered it.
     *
     * @param \stdClass $candidate From get_candidates().
     * @param \stdClass $occurrence
     * @param string $kind A roster::KIND_* constant.
     * @return bool
     */
    public function is_expected(\stdClass $candidate, \stdClass $occurrence, string $kind = roster::KIND_STUDENT): bool {
        if ((int) ($occurrence->rosterfrozen ?? 0) > 0) {
            return isset($this->roster($kind)[(int) $occurrence->id][(int) $candidate->id]);
        }
        return self::covers($candidate, $occurrence);
    }

    /**
     * The live expected users, see get_candidates().
     *
     * @param int $groupid
     * @param int[]|null $userids
     * @param string $capability
     * @return \stdClass[]
     */
    protected function live_candidates(int $groupid, ?array $userids, string $capability): array {
        global $DB;
        $join = get_enrolled_with_capabilities_join($this->context, '', $capability, $groupid);
        $sql = "SELECT DISTINCT u.id FROM {user} u {$join->joins} WHERE {$join->wheres} AND u.suspended = 0";
        $params = $join->params;
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lzau');
            $sql .= " AND u.id $insql";
            $params += $inparams;
        }
        $ids = $DB->get_fieldset_sql($sql, $params);
        if (!$ids) {
            return [];
        }

        $users = $this->load_users($ids);
        if (!$this->cm->visible) {
            $users = array_filter($users, function ($user) {
                return has_capability('moodle/course:viewhiddenactivities', $this->context, $user);
            });
        }
        $info = new \core_availability\info_module($this->cm);
        $users = $info->filter_user_list($users);
        if (!$users) {
            return [];
        }

        foreach ($users as $user) {
            $user->windows = [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED);
        $params += ['courseid' => $this->cm->course, 'enabled' => ENROL_INSTANCE_ENABLED, 'active' => ENROL_USER_ACTIVE];
        $windows = $DB->get_recordset_sql(
            "SELECT ue.id, ue.userid, ue.timestart, ue.timeend
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.status = :enabled AND ue.status = :active AND ue.userid $insql",
            $params
        );
        foreach ($windows as $window) {
            $users[$window->userid]->windows[] = [(int) $window->timestart, (int) $window->timeend];
        }
        $windows->close();
        return $users;
    }

    /**
     * Leave out users tracked as teachers, for viewers who may not see other teachers' figures.
     *
     * @param \stdClass[] $rows Rows keyed by user id, such as an evaluation's notexpected list.
     * @param int[] $keep Teachers the viewer may see.
     * @return \stdClass[]
     */
    public function without_teachers(array $rows, array $keep = []): array {
        if (!$rows) {
            return $rows;
        }
        return array_diff_key($rows, array_diff_key($this->teacher_ids(), array_flip($keep)));
    }

    /**
     * Whether a user is tracked as a teacher in this activity.
     *
     * @param int $userid
     * @return bool
     */
    public function is_teacher(int $userid): bool {
        return isset($this->teacher_ids()[$userid]);
    }

    /**
     * Users holding betrackedteacher in the module.
     *
     * @return \stdClass[] Keyed by user id.
     */
    protected function teacher_ids(): array {
        if ($this->teacherids === null) {
            $this->teacherids = get_users_by_capability($this->context, 'local/zoomattendance:betrackedteacher', 'u.id');
        }
        return $this->teacherids;
    }

    /**
     * Whether a candidate's enrolment covered the occurrence.
     *
     * @param \stdClass $candidate From get_candidates().
     * @param \stdClass $occurrence
     * @return bool
     */
    public static function covers(\stdClass $candidate, \stdClass $occurrence): bool {
        foreach ($candidate->windows as [$start, $end]) {
            if (
                ($start === 0 || $start <= (int) $occurrence->timeend)
                    && ($end === 0 || $end >= (int) $occurrence->timestart)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate one occurrence.
     *
     * @param \stdClass $occurrence
     * @param \stdClass[] $candidates From get_candidates().
     * @param \stdClass[] $results identity key => result row for this occurrence.
     * @param int $groupid Current group (0 for all). Unmatched participants are only listed for all groups.
     * @return \stdClass With state, denominator, expected, notexpected, unmatched and counts.
     */
    public function evaluate(\stdClass $occurrence, array $candidates, array $results, int $groupid = 0): \stdClass {
        $state = $this->state($occurrence);
        $denominator = $this->settings->denominator_for($occurrence);
        $evaluation = (object) [
            'occurrence' => $occurrence,
            'state' => $state,
            'denominator' => $denominator,
            'expected' => [],
            'notexpected' => [],
            'unmatched' => [],
            'counts' => [status::PRESENT => 0, status::PARTIAL => 0, status::ABSENT => 0],
        ];

        $expectedids = [];
        foreach ($candidates as $userid => $candidate) {
            if (!$this->is_expected($candidate, $occurrence)) {
                continue;
            }
            $expectedids[$userid] = true;
            $result = $results['u:' . $userid] ?? null;
            $row = $this->make_row($result, $occurrence, $denominator, $state);
            $row->user = $candidate;
            $evaluation->expected[$userid] = $row;
            if (isset($evaluation->counts[$row->status])) {
                $evaluation->counts[$row->status]++;
            }
        }

        $others = [];
        foreach ($results as $result) {
            if ($result->userid === null) {
                if ($groupid === 0) {
                    $evaluation->unmatched[] = $this->make_row($result, $occurrence, $denominator, $state);
                }
            } else if (!isset($expectedids[$result->userid])) {
                $others[$result->userid] = $result;
            }
        }
        if ($others) {
            if ($groupid) {
                $members = groups_get_members($groupid, 'u.id');
                $others = array_intersect_key($others, $members);
            }
            $users = $this->load_users(array_keys($others));
            foreach ($others as $userid => $result) {
                $row = $this->make_row($result, $occurrence, $denominator, $state);
                $row->status = null;
                $row->user = $users[$userid] ?? null;
                if ($row->user) {
                    $evaluation->notexpected[$userid] = $row;
                }
            }
        }
        return $evaluation;
    }

    /**
     * One report row.
     *
     * @param \stdClass|null $result
     * @param \stdClass $occurrence
     * @param int $denominator
     * @param string $state
     * @return \stdClass
     */
    protected function make_row(?\stdClass $result, \stdClass $occurrence, int $denominator, string $state): \stdClass {
        $attended = $result ? (int) $result->attendedsecs : 0;
        $firstjoin = ($result && $result->firstjoin !== null) ? (int) $result->firstjoin : null;
        $status = null;
        if ($state === self::STATE_EVALUATED) {
            $status = status::evaluate($attended, $firstjoin, (int) $occurrence->timestart, $denominator, $this->settings);
        }
        return (object) [
            'result' => $result,
            'attendedsecs' => $attended,
            'firstjoin' => $firstjoin,
            'lastleave' => ($result && $result->lastleave !== null) ? (int) $result->lastleave : null,
            'percentage' => $result ? calculator::percentage($attended, $denominator) : ($status ? 0.0 : null),
            'status' => $status,
            'weakmatch' => $result && (int) $result->matchstrength === sync::MATCH_WEAK,
            'manualmatch' => $result && (int) $result->matchstrength === sync::MATCH_MANUAL,
            'displayname' => $result ? $result->displayname : null,
        ];
    }

    /**
     * Load users with name and identity fields.
     *
     * @param int[] $userids
     * @return \stdClass[]
     */
    public function load_users(array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        $fields = \core_user\fields::for_name()->with_identity($this->context, false)->get_sql('u', true, '', '', false);
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lzal');
        $sql = "SELECT u.id, {$fields->selects}
                  FROM {user} u {$fields->joins}
                 WHERE u.id $insql AND u.deleted = 0";
        return $DB->get_records_sql($sql, $params + $fields->params);
    }

    /**
     * Identity field names to display for this context.
     *
     * @return string[]
     */
    public function identity_fields(): array {
        return \core_user\fields::get_identity_fields($this->context, false);
    }
}
