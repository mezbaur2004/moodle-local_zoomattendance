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
 * Teacher attendance for one Zoom activity.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

use local_zoomattendance\local\source\zoom_source;

/**
 * Evaluates teachers against the site-level teacher thresholds.
 *
 * Results are the rows the sync already stores for every matched user. Teachers are expected
 * like students (enrolment, access, enrolment window) but through betrackedteacher. A scheduled
 * class without a Zoom session counts as not held, and so absent, once mod_zoom has fetched
 * reports far enough past it.
 */
class teacher_attendance {
    /** @var string Ended without a Zoom session, and mod_zoom has fetched reports past it. */
    public const STATE_NOTHELD = 'notheld';
    /** @var string Ended without a Zoom session; mod_zoom may still import one. */
    public const STATE_AWAITING = 'awaiting';
    /**
     * @var string Ended without a Zoom session here, but another activity using the same Zoom
     * meeting has one at that time: the Zoom plugin filed it there.
     */
    public const STATE_ELSEWHERE = 'elsewhere';

    /** @var attendance */
    public $attendance;
    /** @var settings Teacher thresholds. */
    public $settings;
    /** @var int mod_zoom's report watermark (zoom/last_call_made_at). */
    protected $watermark;
    /** @var int Seconds the watermark must be past an occurrence's end. */
    protected $delay;
    /** @var int Occurrences ending before this are never marked not held. */
    protected $since;
    /** @var int|null Start of the host's latest Zoom session in any activity, see host_reported(). */
    protected $hostreported;
    /** @var int[][]|null [start, end] of the sessions of other activities using the same Zoom meeting. */
    protected $siblingsessions;

    /**
     * Constructor.
     *
     * @param attendance $attendance
     */
    public function __construct(attendance $attendance) {
        $this->attendance = $attendance;
        $this->settings = settings::teacher();
        $this->watermark = (int) get_config('zoom', 'last_call_made_at');
        $this->delay = settings::teacher_notheld_delay();
        $this->since = settings::teacher_tracking_since();
    }

    /**
     * State of an occurrence for teachers: the student states, with "no session data" split
     * into not held and awaiting.
     *
     * @param \stdClass $occurrence
     * @return string An attendance::STATE_* constant or one of this class's.
     */
    public function state(\stdClass $occurrence): string {
        $state = $this->attendance->state($occurrence);
        if ($state !== attendance::STATE_NODATA) {
            return $state;
        }
        if ($this->held_elsewhere($occurrence)) {
            return self::STATE_ELSEWHERE;
        }
        return $this->is_not_held($occurrence) ? self::STATE_NOTHELD : self::STATE_AWAITING;
    }

    /**
     * Whether an ended occurrence without sessions counts as not held.
     *
     * Only scheduled occurrences have an expected slot, and only those known before they ended.
     *
     * The Zoom plugin's report watermark alone is not proof: it also moves past meetings it
     * skipped (hosts it does not fetch, hosts left out when Zoom's rate limit is hit, reports
     * not ready yet). So the host must also have a later session on record, which shows their
     * reports were fetched beyond this class.
     *
     * @param \stdClass $occurrence
     * @return bool
     */
    protected function is_not_held(\stdClass $occurrence): bool {
        $end = (int) $occurrence->timeend;
        // A class first seen after it ended (a course copied without its Zoom data, or a meeting
        // added afterwards) may have been held anyway: only one known in advance can be not held.
        return $occurrence->source === sync::SOURCE_SCHEDULE
            && $end >= $this->since
            && (int) $occurrence->timecreated < $end
            && $this->watermark >= $end + $this->delay
            && settings::teacher_tracked_at($end)
            && $this->host_reported() >= $end;
    }

    /**
     * Start of the latest Zoom session the host of this activity has in any activity.
     *
     * @return int 0 when none.
     */
    protected function host_reported(): int {
        if ($this->hostreported === null) {
            $this->hostreported = zoom_source::host_latest_session((string) ($this->attendance->instance->host_id ?? ''));
        }
        return $this->hostreported;
    }

    /**
     * Whether another activity using the same Zoom meeting has a session during the class.
     *
     * @param \stdClass $occurrence
     * @return bool
     */
    public function held_elsewhere(\stdClass $occurrence): bool {
        if ($this->siblingsessions === null) {
            $this->siblingsessions = zoom_source::sibling_session_spans($this->attendance->instance);
        }
        [$early, $late] = settings::margins();
        $from = (int) $occurrence->timestart - $early;
        $to = (int) $occurrence->timeend + $late;
        foreach ($this->siblingsessions as [$start, $end]) {
            if ($start < $to && $end > $from) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an occurrence gets teacher statuses and counts.
     *
     * @param string $state
     * @return bool
     */
    public static function counts(string $state): bool {
        return $state === attendance::STATE_EVALUATED || $state === self::STATE_NOTHELD;
    }

    /**
     * Enrolled users holding betrackedteacher who can access the activity, limited to its
     * responsible teachers when some are chosen, and the teachers on its frozen lists.
     *
     * @param int[]|null $userids Limit to these users.
     * @return \stdClass[] As attendance::get_candidates().
     */
    public function get_candidates(?array $userids = null): array {
        $live = $this->live_candidates($userids);
        return $live + $this->attendance->roster_candidates(roster::KIND_TEACHER, 0, $userids, $live);
    }

    /**
     * The teachers expected now: see get_candidates(), without the frozen lists.
     *
     * @param int[]|null $userids Limit to these users.
     * @return \stdClass[] As attendance::get_candidates().
     */
    public function live_candidates(?array $userids = null): array {
        $responsible = responsible::get((int) $this->attendance->cm->id);
        if ($responsible) {
            $userids = $userids === null ? $responsible : array_values(array_intersect($userids, $responsible));
        }
        return $this->attendance->get_candidates(0, $userids, 'local/zoomattendance:betrackedteacher', false);
    }

    /**
     * Evaluate the expected teachers of one occurrence.
     *
     * @param \stdClass $occurrence
     * @param \stdClass[] $candidates From get_candidates().
     * @param \stdClass[] $results identity key => result row for this occurrence.
     * @return \stdClass With occurrence, state, denominator and rows (userid => row).
     */
    public function evaluate(\stdClass $occurrence, array $candidates, array $results): \stdClass {
        $state = $this->state($occurrence);
        $start = (int) $occurrence->timestart;
        $end = (int) $occurrence->timeend;
        $grace = $this->settings->lategracemins * MINSECS;
        $evaluation = (object) [
            'occurrence' => $occurrence,
            'state' => $state,
            'denominator' => $end - $start,
            'rows' => [],
        ];
        foreach ($candidates as $userid => $candidate) {
            if (!$this->attendance->is_expected($candidate, $occurrence, roster::KIND_TEACHER)) {
                continue;
            }
            $result = $state === attendance::STATE_EVALUATED ? ($results['u:' . $userid] ?? null) : null;
            $attended = $result ? (int) $result->attendedsecs : 0;
            $firstjoin = ($result && $result->firstjoin !== null) ? (int) $result->firstjoin : null;
            $lastleave = ($result && $result->lastleave !== null) ? (int) $result->lastleave : null;
            $status = null;
            if ($state === attendance::STATE_EVALUATED) {
                $status = status::evaluate($attended, $firstjoin, $start, $end - $start, $this->settings);
            } else if ($state === self::STATE_NOTHELD) {
                $status = status::ABSENT;
            }
            $evaluation->rows[$userid] = (object) [
                'user' => $candidate,
                'result' => $result,
                'attendedsecs' => $attended,
                'firstjoin' => $firstjoin,
                'lastleave' => $lastleave,
                'percentage' => $status === null ? null : (calculator::percentage($attended, $end - $start) ?? 0.0),
                'status' => $status,
                'latesecs' => $firstjoin === null ? 0 : max(0, $firstjoin - $start),
                'earlysecs' => $lastleave === null ? 0 : max(0, $end - $lastleave),
                'latestart' => $firstjoin !== null && $firstjoin > $start + $grace,
                'earlyleave' => $lastleave !== null && $lastleave < $end - $grace,
                'manualmatch' => $result && (int) $result->matchstrength === sync::MATCH_MANUAL,
            ];
        }
        return $evaluation;
    }
}
