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
 * Teacher attendance for one course.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Collects teacher attendance over a course's Zoom activities.
 *
 * Every Zoom activity counts, whatever its per-activity tracking switch says. An occurrence is
 * listed when it counts for teachers (held, or not held), and also, without counting, once it
 * has ended but was excluded, is awaiting its Zoom report, or lost its data in a Zoom data reset.
 * Excluded occurrences name who excluded them.
 */
class teacher_summary {
    /** @var \stdClass[] cmid => {cm, columns: occurrence id => occurrence, states: occurrence id => state}. */
    public $activities = [];
    /** @var \stdClass[] Every listed occurrence as {cm, occurrence, state}, in start order. */
    public $classes = [];
    /** @var \stdClass[] userid => teacher, expected in at least one column, sorted by name. */
    public $users = [];
    /** @var \stdClass[][] userid => occurrence id => row from teacher_attendance::evaluate(). */
    public $cells = [];
    /** @var summary[] userid => overall percentage over the counted columns. */
    public $overall = [];
    /**
     * @var summary[] userid => percentage over only the counted columns the teacher joined
     * ("when joined"): classes they missed, or that were not held, are left out.
     */
    public $joined = [];
    /** @var array[] userid => counts, see empty_stats(). */
    public $stats = [];
    /** @var \stdClass[] occurrence id => user who excluded it, for excluded columns. */
    public $excludedby = [];
    /** @var string[] occurrence id => why it was excluded, for excluded columns. */
    public $excludereasons = [];
    /** @var bool[] userid => true when the teacher linked an identity to themself in the course. */
    public $selflinkers = [];
    /** @var bool[][] userid => occurrence id => true when the teacher's time there includes an identity they linked to themself. */
    public $selflinked = [];
    /** @var string[] userid => the teacher's roles in the course, as the course names them. */
    public $roles = [];

    /**
     * Counts kept per teacher.
     *
     * @return int[]
     */
    public static function empty_stats(): array {
        return [
            'expected' => 0,
            'joined' => 0,
            status::PRESENT => 0,
            status::PARTIAL => 0,
            status::ABSENT => 0,
            'notheld' => 0,
            'latestarts' => 0,
            'earlyleaves' => 0,
            'excluded' => 0,
            'excludedbyself' => 0,
            'selflinked' => 0,
        ];
    }

    /**
     * Build the summary.
     *
     * @param \stdClass $course
     * @param int[]|null $userids Only these teachers (null for every teacher).
     * @param int $from Only occurrences starting at or after this time.
     * @param int|null $to Only occurrences starting before this time.
     * @return self
     */
    public static function build(\stdClass $course, ?array $userids = null, int $from = 0, ?int $to = null): self {
        $ids = $userids;
        if ($ids !== null) {
            $ids = array_map('intval', $ids);
            sort($ids);
        }
        $key = [
            'teachers',
            (int) $course->id,
            $ids,
            $from,
            $to,
            has_capability('moodle/site:viewuseridentity', \context_course::instance($course->id)),
            // Not held depends on the Zoom plugin's report watermark and on time, not on our data.
            (int) get_config('zoom', 'last_call_made_at'),
            intdiv(time(), HOURSECS),
        ];
        $summary = data_version::cached($key, function () use ($course, $userids, $from, $to) {
            $summary = self::compute($course, $userids, $from, $to);
            // Course modules are rebuilt from the course cache; only their ids are stored.
            foreach ($summary->activities as $activity) {
                $activity->cm = (int) $activity->cm->id;
            }
            foreach ($summary->classes as $class) {
                $class->cm = (int) $class->cm->id;
            }
            return $summary;
        });
        $modinfo = get_fast_modinfo($course);
        foreach ($summary->activities as $activity) {
            $activity->cm = $modinfo->get_cm($activity->cm);
        }
        foreach ($summary->classes as $class) {
            $class->cm = $modinfo->get_cm($class->cm);
        }
        return $summary;
    }

    /**
     * Build the summary, see build().
     *
     * @param \stdClass $course
     * @param int[]|null $userids
     * @param int $from
     * @param int|null $to
     * @return self
     */
    protected static function compute(\stdClass $course, ?array $userids, int $from, ?int $to): self {
        global $DB;
        $summary = new self();
        // Identity keys each teacher linked to themself.
        $selfkeys = [];
        $links = $DB->get_records_select(
            'local_zoomattendance_idmap',
            'courseid = :courseid AND userid = usermodified',
            ['courseid' => $course->id],
            '',
            'id, userid, identitykey'
        );
        foreach ($links as $link) {
            $selfkeys[(int) $link->userid][$link->identitykey] = true;
            $summary->selflinkers[(int) $link->userid] = true;
        }
        $excluders = [];
        $now = time();

        foreach (get_fast_modinfo($course)->get_instances_of('zoom') as $cm) {
            $attendance = new attendance($cm);
            $teachers = new teacher_attendance($attendance);
            $occurrences = array_filter($attendance->get_occurrences(), function ($occurrence) use ($from, $to) {
                $start = (int) $occurrence->timestart;
                return $start >= $from && ($to === null || $start < $to);
            });
            if (!$occurrences) {
                continue;
            }
            $candidates = $userids === [] ? [] : $teachers->get_candidates($userids);
            if (!$candidates) {
                continue;
            }
            $onlyuser = $userids !== null && count($userids) === 1 ? (int) reset($userids) : null;
            $results = $attendance->get_results(array_keys($occurrences), $onlyuser);
            $activity = (object) ['cm' => $cm, 'columns' => [], 'states' => []];
            foreach ($occurrences as $occurrence) {
                $state = $teachers->state($occurrence);
                $excluded = $state === attendance::STATE_EXCLUDED;
                if (!teacher_attendance::counts($state) && !(self::shown($state) && (int) $occurrence->timeend <= $now)) {
                    continue;
                }
                $evaluation = $teachers->evaluate($occurrence, $candidates, $results[$occurrence->id] ?? []);
                if (!$evaluation->rows) {
                    continue;
                }
                $selfpresent = self::self_linked_in($occurrence, $evaluation->rows, $selfkeys);
                $activity->columns[$occurrence->id] = $occurrence;
                $activity->states[$occurrence->id] = $state;
                $summary->classes[] = (object) ['cm' => $cm, 'occurrence' => $occurrence, 'state' => $state];
                if ($excluded && (int) $occurrence->usermodified) {
                    $excluders[$occurrence->id] = (int) $occurrence->usermodified;
                }
                if ($excluded && !empty($occurrence->excludereason)) {
                    $summary->excludereasons[$occurrence->id] = (string) $occurrence->excludereason;
                }
                foreach ($evaluation->rows as $id => $row) {
                    $summary->users[$id] = $row->user;
                    $summary->cells[$id][$occurrence->id] = $row;
                    $stats = $summary->stats[$id] ?? self::empty_stats();
                    if ($excluded) {
                        $stats['excluded']++;
                        if ((int) $occurrence->usermodified === (int) $id) {
                            $stats['excludedbyself']++;
                        }
                    } else if (in_array($row->status, [status::PRESENT, status::PARTIAL, status::ABSENT], true)) {
                        $stats['expected']++;
                        $stats[$row->status]++;
                        $stats['notheld'] += $state === teacher_attendance::STATE_NOTHELD ? 1 : 0;
                        $stats['latestarts'] += $row->latestart ? 1 : 0;
                        $stats['earlyleaves'] += $row->earlyleave ? 1 : 0;
                        if (isset($selfpresent[$id])) {
                            $stats['selflinked']++;
                            $summary->selflinked[$id][$occurrence->id] = true;
                        }
                        $summary->overall[$id] = $summary->overall[$id] ?? new summary();
                        $summary->overall[$id]->add($row, $evaluation);
                        // Joined: any time inside the class window.
                        if ((int) $row->attendedsecs > 0) {
                            $stats['joined']++;
                            $summary->joined[$id] = $summary->joined[$id] ?? new summary();
                            $summary->joined[$id]->add($row, $evaluation);
                        }
                    }
                    $summary->stats[$id] = $stats;
                }
            }
            if ($activity->columns) {
                $summary->activities[$cm->id] = $activity;
            }
        }

        if ($excluders) {
            $users = $DB->get_records_list(
                'user',
                'id',
                array_unique($excluders),
                '',
                'id, ' . \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects
            );
            foreach ($excluders as $occurrenceid => $id) {
                $summary->excludedby[$occurrenceid] = $users[$id] ?? null;
            }
        }
        uasort($summary->users, function ($a, $b) {
            return strcmp(fullname($a), fullname($b));
        });
        $summary->roles = self::role_names(\context_course::instance($course->id), array_keys($summary->users));
        usort($summary->classes, function ($a, $b) {
            return ((int) $a->occurrence->timestart <=> (int) $b->occurrence->timestart)
                ?: ((int) $a->occurrence->id <=> (int) $b->occurrence->id);
        });
        return $summary;
    }

    /**
     * Teachers whose time in an occurrence includes an identity they linked to themself: a
     * linked row, and one of their self-linked identities in the occurrence's own Zoom data.
     *
     * @param \stdClass $occurrence
     * @param \stdClass[] $rows userid => row from teacher_attendance::evaluate().
     * @param bool[][] $selfkeys userid => identity key => true.
     * @return bool[] userid => true.
     */
    protected static function self_linked_in(\stdClass $occurrence, array $rows, array $selfkeys): array {
        global $DB;
        $suspects = [];
        foreach ($rows as $userid => $row) {
            if ($row->manualmatch && isset($selfkeys[$userid])) {
                $suspects[] = $userid;
            }
        }
        if (!$suspects) {
            return [];
        }
        $detailsids = $DB->get_fieldset_select('local_zoomattendance_session', 'detailsid', 'occurrenceid = ?', [$occurrence->id]);
        $present = [];
        foreach (source\zoom_source::get_participants($detailsids) as $participant) {
            $inwindow = (int) $participant->join_time < (int) $occurrence->timeend
                && (int) $participant->leave_time > (int) $occurrence->timestart;
            if (!empty($participant->userid) || !$inwindow) {
                continue;
            }
            $key = sync::unmatched_key($participant);
            foreach ($suspects as $userid) {
                if (isset($selfkeys[$userid][$key])) {
                    $present[$userid] = true;
                }
            }
        }
        return $present;
    }

    /**
     * Roles assigned in the course itself (not inherited ones such as a site manager's), with
     * the course's own role renaming applied.
     *
     * @param \context_course $context
     * @param int[] $userids
     * @return string[] userid => role names joined by commas.
     */
    public static function role_names(\context_course $context, array $userids): array {
        if (!$userids) {
            return [];
        }
        $names = role_get_names($context, ROLENAME_ALIAS, true);
        $roles = [];
        foreach (get_users_roles($context, $userids, false) as $userid => $assignments) {
            $list = [];
            foreach ($assignments as $assignment) {
                if (isset($names[$assignment->roleid])) {
                    $list[$assignment->roleid] = $names[$assignment->roleid];
                }
            }
            $roles[(int) $userid] = implode(', ', $list);
        }
        return $roles;
    }

    /**
     * Whether an ended occurrence in this state is listed without counting.
     *
     * @param string $state
     * @return bool
     */
    public static function shown(string $state): bool {
        return in_array($state, [
            attendance::STATE_EXCLUDED,
            attendance::STATE_RESET,
            teacher_attendance::STATE_AWAITING,
        ], true);
    }
}
