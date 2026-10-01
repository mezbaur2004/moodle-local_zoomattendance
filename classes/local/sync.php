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
 * Synchronises attendance results from mod_zoom data.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

use local_zoomattendance\local\source\zoom_source;

/**
 * Snapshots occurrences, maps sessions to them and recomputes attended time.
 *
 * Every step is idempotent: running it again without new mod_zoom data changes nothing.
 */
class sync {
    /** @var string Occurrence from the mod_zoom schedule. */
    public const SOURCE_SCHEDULE = 'schedule';
    /** @var string Occurrence inferred from actual sessions. */
    public const SOURCE_INFERRED = 'inferred';
    /** @var string Inferred occurrence whose window a teacher set. */
    public const SOURCE_MANUAL = 'manual';
    /**
     * @var string Held class of a fixed-time recurring meeting whose calendar event is gone: the
     * meeting's regular time and length on that day.
     */
    public const SOURCE_PATTERN = 'pattern';

    /** @var int Matched by mod_zoom without an email match. */
    public const MATCH_WEAK = 1;
    /** @var int Matched by mod_zoom with an email or API identifier match. */
    public const MATCH_EMAIL = 2;
    /** @var int Linked to the user by a teacher (local_zoomattendance_idmap). */
    public const MATCH_MANUAL = 3;

    /** @var int Occurrence counts normally. */
    public const STATUS_ACTIVE = 0;
    /** @var int A future occurrence disappeared from the mod_zoom schedule. */
    public const STATUS_CANCELLED = 1;
    /** @var int A teacher excluded the occurrence. */
    public const STATUS_EXCLUDED = 2;
    /** @var int The course's Zoom data was reset after the occurrence; it is not counted. */
    public const STATUS_RESET = 3;

    /** @var string Key of the only occurrence of a non-recurring meeting. */
    public const KEY_SINGLE = 'single';

    /**
     * Sync every enabled instance, then remove data whose activity no longer exists.
     *
     * While teacher attendance is tracked every instance is synced, whatever its per-activity
     * switch says, so a teacher cannot hide a missed class by switching tracking off.
     *
     * @param int|null $courseid Limit to one course.
     * @return int Number of instances synced.
     */
    public static function sync_all(?int $courseid = null): int {
        $count = 0;
        $all = settings::teacher_tracking();
        foreach (zoom_source::get_instances($courseid) as $instance) {
            if (!$all && !settings::from_override(zoom_source::override_from_instance($instance))->enabled) {
                continue;
            }
            try {
                if (self::sync_instance($instance)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                mtrace("local_zoomattendance: zoom {$instance->id} failed: " . $e->getMessage());
            }
        }
        self::delete_orphans();
        return $count;
    }

    /**
     * Recompute every activity of a course that is synced or already has data. Used after a
     * change that affects all of them, such as a new identity link or a course reset.
     *
     * @param int $courseid
     */
    public static function resync_course(int $courseid): void {
        global $DB;
        $all = settings::teacher_tracking();
        foreach (zoom_source::get_instances($courseid) as $instance) {
            $enabled = $all || settings::from_override(zoom_source::override_from_instance($instance))->enabled;
            if (!$enabled && !$DB->record_exists('local_zoomattendance_occ', ['zoomid' => $instance->id])) {
                continue;
            }
            self::sync_instance($instance, true);
        }
    }

    /**
     * Sync one instance.
     *
     * @param \stdClass $instance A zoom_source::get_instances() record.
     * @param bool $recomputeall Recompute every occurrence, not only changed ones.
     * @return bool False when another process holds the instance lock.
     */
    public static function sync_instance(\stdClass $instance, bool $recomputeall = false): bool {
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_zoomattendance');
        $lock = $lockfactory->get_lock('zoom' . $instance->id, 10);
        if (!$lock) {
            return false;
        }
        try {
            self::do_sync($instance, $recomputeall);
        } finally {
            $lock->release();
        }
        return true;
    }

    /**
     * The sync steps for one instance. Caller holds the lock.
     *
     * @param \stdClass $instance
     * @param bool $recomputeall
     */
    protected static function do_sync(\stdClass $instance, bool $recomputeall): void {
        global $DB;
        $zoomid = (int) $instance->id;
        [$earlymargin, $latemargin, $gap] = settings::margins();

        $dirty = self::snapshot_schedule($instance);

        $occurrences = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $zoomid]);
        // Teacher-set and regular-time windows are fixed like scheduled ones: sessions map to them first.
        $fixed = [self::SOURCE_SCHEDULE, self::SOURCE_MANUAL, self::SOURCE_PATTERN];
        $scheduled = array_filter($occurrences, function ($o) use ($fixed) {
            return in_array($o->source, $fixed, true);
        });
        $sessions = zoom_source::get_sessions($zoomid);

        // Map sessions: fixed occurrences first, then the meeting's regular time for held classes
        // whose calendar event mod_zoom dropped, and clusters of the rest become inferred ones.
        $map = occurrence_mapper::map_to_scheduled($sessions, $scheduled, $earlymargin, $latemargin);
        $unmapped = array_diff_key($sessions, $map);
        $patterns = self::add_pattern_occurrences($instance, $unmapped, $occurrences, $earlymargin, $latemargin, $dirty);
        if ($patterns) {
            $map += occurrence_mapper::map_to_scheduled($unmapped, $patterns, $earlymargin, $latemargin);
            $unmapped = array_diff_key($sessions, $map);
        }
        $inferredbykey = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence->source === self::SOURCE_INFERRED) {
                $inferredbykey[$occurrence->occurrencekey] = $occurrence;
            }
        }
        $now = time();
        foreach (occurrence_mapper::cluster($unmapped, $gap) as $key => $cluster) {
            if (isset($inferredbykey[$key])) {
                $occurrence = $inferredbykey[$key];
                unset($inferredbykey[$key]);
                if ((int) $occurrence->timestart !== $cluster->timestart || (int) $occurrence->timeend !== $cluster->timeend) {
                    $occurrence->timestart = $cluster->timestart;
                    $occurrence->timeend = $cluster->timeend;
                    $occurrence->timemodified = $now;
                    $DB->update_record('local_zoomattendance_occ', $occurrence);
                    $dirty[$occurrence->id] = true;
                }
            } else {
                $occurrence = self::insert_occurrence(
                    $zoomid,
                    $key,
                    self::SOURCE_INFERRED,
                    $cluster->timestart,
                    $cluster->timeend
                );
                $dirty[$occurrence->id] = true;
            }
            foreach ($cluster->detailsids as $detailsid) {
                $map[$detailsid] = (int) $occurrence->id;
            }
        }

        // Update session bookkeeping and collect occurrences whose inputs changed.
        $rows = $DB->get_records('local_zoomattendance_session', ['zoomid' => $zoomid], '', '*');
        $rowsbydetails = [];
        foreach ($rows as $row) {
            $rowsbydetails[$row->detailsid] = $row;
        }
        foreach ($sessions as $detailsid => $session) {
            $target = $map[$detailsid] ?? null;
            $row = $rowsbydetails[$detailsid] ?? null;
            unset($rowsbydetails[$detailsid]);
            if (!$row) {
                $DB->insert_record('local_zoomattendance_session', (object) [
                    'detailsid' => $detailsid,
                    'zoomid' => $zoomid,
                    'occurrenceid' => $target,
                    'fingerprint' => $session->fingerprint,
                    'timesynced' => $now,
                ]);
                $dirty[$target] = true;
                continue;
            }
            $previous = $row->occurrenceid === null ? null : (int) $row->occurrenceid;
            if ($row->fingerprint !== $session->fingerprint || $previous !== $target) {
                $dirty[$previous] = true;
                $dirty[$target] = true;
                $row->occurrenceid = $target;
                $row->fingerprint = $session->fingerprint;
                $row->timesynced = $now;
                $DB->update_record('local_zoomattendance_session', $row);
            }
        }
        foreach ($rowsbydetails as $row) {
            // The mod_zoom session is gone (instance delete, privacy delete).
            $dirty[$row->occurrenceid] = true;
            $DB->delete_records('local_zoomattendance_session', ['id' => $row->id]);
        }

        // Inferred and regular-time occurrences that no longer have sessions are derived data: drop them.
        $withsessions = array_flip(array_values($map));
        foreach ($occurrences as $occurrence) {
            if ($occurrence->source === self::SOURCE_PATTERN && !isset($withsessions[$occurrence->id])) {
                $inferredbykey[] = $occurrence;
            }
        }
        foreach ($inferredbykey as $occurrence) {
            $DB->delete_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]);
            $DB->delete_records('local_zoomattendance_occ', ['id' => $occurrence->id]);
            unset($dirty[$occurrence->id]);
        }

        unset($dirty['']);
        $occurrences = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $zoomid]);
        $bydetails = [];
        foreach ($map as $detailsid => $occurrenceid) {
            $bydetails[$occurrenceid][$detailsid] = $sessions[$detailsid];
        }
        $idmap = self::get_idmap((int) $instance->course);
        foreach ($occurrences as $occurrence) {
            // A zero timecomputed marks an occurrence a teacher change left for recompute.
            if ($recomputeall || isset($dirty[$occurrence->id]) || (int) $occurrence->timecomputed === 0) {
                self::recompute_occurrence($occurrence, $bydetails[$occurrence->id] ?? [], $idmap);
            }
        }
    }

    /**
     * Snapshot the schedule into our occurrence table.
     *
     * Past occurrences are never changed or removed, because mod_zoom may delete their calendar
     * events. A future occurrence that disappears is marked cancelled.
     *
     * @param \stdClass $instance
     * @return array Occurrence ids whose window changed, as keys.
     */
    protected static function snapshot_schedule(\stdClass $instance): array {
        global $DB;
        $now = time();
        $zoomid = (int) $instance->id;
        $windows = self::scheduled_windows($instance);
        $existing = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $zoomid, 'source' => self::SOURCE_SCHEDULE]);
        $dirty = [];

        foreach ($existing as $occurrence) {
            $key = $occurrence->occurrencekey;
            if (!isset($windows[$key])) {
                if ((int) $occurrence->timestart > $now && (int) $occurrence->status === self::STATUS_ACTIVE) {
                    $occurrence->status = self::STATUS_CANCELLED;
                    $occurrence->timemodified = $now;
                    $DB->update_record('local_zoomattendance_occ', $occurrence);
                }
                continue;
            }
            [$start, $end] = $windows[$key];
            unset($windows[$key]);
            $changed = false;
            if ((int) $occurrence->status === self::STATUS_CANCELLED) {
                $occurrence->status = self::STATUS_ACTIVE;
                $changed = true;
            }
            $timeschanged = (int) $occurrence->timestart !== $start || (int) $occurrence->timeend !== $end;
            if ($timeschanged && (int) $occurrence->timestart > $now) {
                $occurrence->timestart = $start;
                $occurrence->timeend = $end;
                $changed = true;
                $dirty[$occurrence->id] = true;
            }
            if ($changed) {
                $occurrence->timemodified = $now;
                $DB->update_record('local_zoomattendance_occ', $occurrence);
            }
        }

        foreach ($windows as $key => [$start, $end]) {
            $occurrence = self::insert_occurrence($zoomid, $key, self::SOURCE_SCHEDULE, $start, $end);
            $dirty[$occurrence->id] = true;
        }
        return $dirty;
    }

    /**
     * The scheduled windows mod_zoom currently knows about.
     *
     * @param \stdClass $instance
     * @return array occurrence key => [start, end]
     */
    public static function scheduled_windows(\stdClass $instance): array {
        if (empty($instance->recurring)) {
            $start = (int) $instance->start_time;
            $duration = (int) $instance->duration;
            return ($start > 0 && $duration > 0) ? [self::KEY_SINGLE => [$start, $start + $duration]] : [];
        }
        if ((int) $instance->recurrence_type === 0) {
            // Recurring meeting with no fixed time: sessions become inferred occurrences.
            return [];
        }
        $windows = [];
        foreach (zoom_source::get_calendar_occurrences((int) $instance->id) as $event) {
            $start = (int) $event->timestart;
            $duration = (int) $event->timeduration;
            if ($duration > 0) {
                $windows[substr($event->uuid, 0, 64)] = [$start, $start + $duration];
            }
        }
        return $windows;
    }

    /**
     * The regular window of a fixed-time recurring meeting around a moment: its start time of day,
     * in the meeting's time zone, and its length, on that day or the day before or after,
     * whichever overlaps the given span most within the margins.
     *
     * @param \stdClass $instance A zoom_source::get_instances() record.
     * @param int $start Span start.
     * @param int $end Span end.
     * @param int $earlymargin
     * @param int $latemargin
     * @return int[]|null [start, end], or null when the meeting has no fixed time or the span is
     *     not near its regular time.
     */
    public static function pattern_window(\stdClass $instance, int $start, int $end, int $earlymargin, int $latemargin): ?array {
        $first = (int) $instance->start_time;
        $duration = (int) $instance->duration;
        if (empty($instance->recurring) || (int) $instance->recurrence_type === 0 || $first <= 0 || $duration <= 0) {
            return null;
        }
        $zone = new \DateTimeZone(\core_date::normalise_timezone($instance->timezone ?? ''));
        $timeofday = (new \DateTime('@' . $first))->setTimezone($zone);
        $best = null;
        $bestoverlap = -1;
        foreach ([-1, 0, 1] as $shift) {
            $day = (new \DateTime('@' . $start))->setTimezone($zone)->modify("$shift day");
            $day->setTime((int) $timeofday->format('G'), (int) $timeofday->format('i'), (int) $timeofday->format('s'));
            $from = $day->getTimestamp();
            $to = $from + $duration;
            if ($end < $from - $earlymargin || $start > $to + $latemargin) {
                continue;
            }
            $overlap = max(0, min($end, $to + $latemargin) - max($start, $from - $earlymargin));
            if ($overlap > $bestoverlap) {
                $best = [$from, $to];
                $bestoverlap = $overlap;
            }
        }
        return $best;
    }

    /**
     * Create regular-time occurrences for held classes of a fixed-time recurring meeting that no
     * scheduled occurrence covers.
     *
     * mod_zoom keeps calendar events only for the occurrences Zoom still lists, which are the
     * upcoming ones, so classes held before this plugin snapshotted the schedule have no event.
     * Measuring them against the session span would count waiting time and overruns; the
     * meeting's regular time is the schedule they followed.
     *
     * @param \stdClass $instance
     * @param \stdClass[] $sessions Sessions not mapped to a fixed occurrence, keyed by details id.
     * @param \stdClass[] $occurrences The instance's existing occurrences.
     * @param int $earlymargin
     * @param int $latemargin
     * @param array $dirty Occurrence ids to recompute, as keys; new occurrences are added.
     * @return \stdClass[] The occurrences for these sessions, keyed by id.
     */
    protected static function add_pattern_occurrences(
        \stdClass $instance,
        array $sessions,
        array $occurrences,
        int $earlymargin,
        int $latemargin,
        array &$dirty
    ): array {
        $added = [];
        $existing = [];
        foreach ($occurrences as $occurrence) {
            $existing[$occurrence->occurrencekey] = $occurrence;
        }
        foreach ($sessions as $session) {
            [$start, $end] = occurrence_mapper::span($session);
            $window = self::pattern_window($instance, $start, $end, $earlymargin, $latemargin);
            if (!$window) {
                continue;
            }
            $key = 'p:' . $window[0];
            if (isset($added[$key])) {
                continue;
            }
            if (isset($existing[$key])) {
                $added[$key] = $existing[$key];
                continue;
            }
            $added[$key] = self::insert_occurrence((int) $instance->id, $key, self::SOURCE_PATTERN, $window[0], $window[1]);
            $dirty[$added[$key]->id] = true;
        }
        $byid = [];
        foreach ($added as $occurrence) {
            $byid[$occurrence->id] = $occurrence;
        }
        return $byid;
    }

    /**
     * Insert an occurrence.
     *
     * @param int $zoomid
     * @param string $key
     * @param string $source
     * @param int $start
     * @param int $end
     * @return \stdClass The inserted row.
     */
    protected static function insert_occurrence(int $zoomid, string $key, string $source, int $start, int $end): \stdClass {
        global $DB;
        $now = time();
        $occurrence = (object) [
            'zoomid' => $zoomid,
            'occurrencekey' => $key,
            'source' => $source,
            'timestart' => $start,
            'timeend' => $end,
            'status' => self::STATUS_ACTIVE,
            'actualsecs' => 0,
            'timecomputed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $occurrence->id = $DB->insert_record('local_zoomattendance_occ', $occurrence);
        return $occurrence;
    }

    /**
     * Recompute every participant's attended time for one occurrence.
     *
     * @param \stdClass $occurrence
     * @param \stdClass[] $sessions Sessions mapped to it, keyed by details id.
     * @param int[] $idmap Teacher-confirmed links, identity key => userid (see get_idmap()).
     */
    public static function recompute_occurrence(\stdClass $occurrence, array $sessions, array $idmap = []): void {
        global $DB;
        $start = (int) $occurrence->timestart;
        $end = (int) $occurrence->timeend;
        $now = time();

        $spans = array_map([occurrence_mapper::class, 'span'], $sessions);
        $actualsecs = calculator::summarise($spans, $start, $end)->attendedsecs;

        $identities = self::group_by_identity(zoom_source::get_participants(array_keys($sessions)), $idmap);
        $strength = self::match_strengths($identities);

        $computed = [];
        foreach ($identities as $key => $identity) {
            $summary = calculator::summarise($identity->intervals, $start, $end);
            if ($summary->attendedsecs <= 0) {
                continue;
            }
            $computed[$key] = (object) [
                'occurrenceid' => $occurrence->id,
                'userid' => $identity->userid,
                'identitykey' => $key,
                'displayname' => $identity->userid ? null : \core_text::substr($identity->name, 0, 255),
                'attendedsecs' => $summary->attendedsecs,
                'firstjoin' => $summary->firstjoin,
                'lastleave' => $summary->lastleave,
                'segments' => $summary->segments,
                'matchstrength' => $identity->userid ? $strength[$identity->userid] : 0,
                'timemodified' => $now,
            ];
        }

        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]);
        foreach ($existing as $row) {
            if (!isset($computed[$row->identitykey])) {
                $DB->delete_records('local_zoomattendance_result', ['id' => $row->id]);
                continue;
            }
            $new = $computed[$row->identitykey];
            unset($computed[$row->identitykey]);
            $new->id = $row->id;
            $DB->update_record('local_zoomattendance_result', $new);
        }
        foreach ($computed as $new) {
            $DB->insert_record('local_zoomattendance_result', $new);
        }
        $DB->update_record('local_zoomattendance_occ', (object) [
            'id' => $occurrence->id,
            'actualsecs' => $actualsecs,
            'timecomputed' => $now,
        ]);
        $transaction->allow_commit();
    }

    /**
     * Group participant segments by identity.
     *
     * A segment that mod_zoom stored both with and without a userid (a later re-match inserts
     * a second row) is kept only in its matched form. An unmatched participant whose identity a
     * teacher linked to a user counts as that user; their segments merge with the user's own.
     *
     * @param \stdClass[] $participants zoom_meeting_participants rows.
     * @param int[] $idmap identity key => userid.
     * @return \stdClass[] identity key => {userid, manual, name, emails[], intervals[]}.
     */
    public static function group_by_identity(array $participants, array $idmap = []): array {
        $matchedsegments = [];
        foreach ($participants as $p) {
            if (!empty($p->userid)) {
                $matchedsegments[self::segment_key($p)] = true;
            }
        }

        $identities = [];
        foreach ($participants as $p) {
            $userid = empty($p->userid) ? null : (int) $p->userid;
            if ($userid === null && isset($matchedsegments[self::segment_key($p)])) {
                continue;
            }
            $manual = false;
            if ($userid === null) {
                $key = self::unmatched_key($p);
                if (isset($idmap[$key])) {
                    $userid = (int) $idmap[$key];
                    $manual = true;
                }
            }
            if ($userid) {
                $key = 'u:' . $userid;
            }
            if (!isset($identities[$key])) {
                $identities[$key] = (object) [
                    'userid' => $userid,
                    'manual' => false,
                    'name' => '',
                    'emails' => [],
                    'intervals' => [],
                ];
            }
            $identities[$key]->manual = $identities[$key]->manual || $manual;
            $identities[$key]->intervals[] = [(int) $p->join_time, (int) $p->leave_time];
            if ((string) $p->name !== '') {
                $identities[$key]->name = (string) $p->name;
            }
            $email = \core_text::strtolower(trim((string) $p->user_email));
            if ($email !== '') {
                $identities[$key]->emails[$email] = true;
            }
        }
        return $identities;
    }

    /**
     * Key of one segment, independent of the matched user.
     *
     * @param \stdClass $p
     * @return string
     */
    protected static function segment_key(\stdClass $p): string {
        return implode('|', [$p->detailsid, $p->zoomuserid, $p->join_time, $p->leave_time]);
    }

    /**
     * Stable, hashed identity key for an unmatched participant: email, else participant uuid,
     * else Zoom user id and name.
     *
     * @param \stdClass $p
     * @return string
     */
    public static function unmatched_key(\stdClass $p): string {
        $email = \core_text::strtolower(trim((string) $p->user_email));
        if ($email !== '') {
            return 'z:' . sha1('e:' . $email);
        }
        if (!empty($p->uuid)) {
            return 'z:' . sha1('p:' . $p->uuid);
        }
        return 'z:' . sha1('n:' . $p->zoomuserid . '|' . $p->name);
    }

    /**
     * Match strength per matched user: MATCH_MANUAL when a teacher linked any of the segments,
     * MATCH_EMAIL when a segment's email equals the user's email or mod_zoom API identifier,
     * MATCH_WEAK otherwise (name, "(id)Name" prefix or fuzzy match).
     *
     * @param \stdClass[] $identities From group_by_identity().
     * @return int[] userid => strength.
     */
    protected static function match_strengths(array $identities): array {
        global $DB;
        $userids = [];
        foreach ($identities as $identity) {
            if ($identity->userid) {
                $userids[] = $identity->userid;
            }
        }
        if (!$userids) {
            return [];
        }
        $field = get_config('zoom', 'apiidentifier');
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, email, username, idnumber');
        $strength = [];
        foreach ($identities as $identity) {
            if (!$identity->userid) {
                continue;
            }
            $known = [];
            if ($user = $users[$identity->userid] ?? null) {
                $known[] = \core_text::strtolower(trim((string) $user->email));
                if (in_array($field, ['username', 'idnumber'], true)) {
                    $known[] = \core_text::strtolower(trim((string) $user->$field));
                }
            }
            if ($identity->manual) {
                $strength[$identity->userid] = self::MATCH_MANUAL;
            } else if (array_intersect_key(array_flip(array_filter($known)), $identity->emails)) {
                $strength[$identity->userid] = self::MATCH_EMAIL;
            } else {
                $strength[$identity->userid] = self::MATCH_WEAK;
            }
        }
        return $strength;
    }

    /**
     * Teacher-confirmed identity links of a course.
     *
     * @param int $courseid
     * @return int[] identity key => userid.
     */
    public static function get_idmap(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu(
            'local_zoomattendance_idmap',
            ['courseid' => $courseid],
            '',
            'identitykey, userid'
        ));
    }

    /**
     * Delete all data for the given zoom instances.
     *
     * @param int[] $zoomids
     */
    public static function delete_for_zoomids(array $zoomids): void {
        global $DB;
        if (!$zoomids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($zoomids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'local_zoomattendance_result',
            "occurrenceid IN (SELECT id FROM {local_zoomattendance_occ} WHERE zoomid $insql)",
            $params
        );
        $DB->delete_records_select('local_zoomattendance_session', "zoomid $insql", $params);
        $DB->delete_records_select('local_zoomattendance_occ', "zoomid $insql", $params);
    }

    /**
     * Remove data whose zoom instance or course module no longer exists.
     */
    public static function delete_orphans(): void {
        global $DB;
        $zoomids = $DB->get_fieldset_sql("SELECT DISTINCT o.zoomid
                                            FROM {local_zoomattendance_occ} o
                                       LEFT JOIN {zoom} z ON z.id = o.zoomid
                                           WHERE z.id IS NULL
                                           UNION
                                          SELECT DISTINCT s.zoomid
                                            FROM {local_zoomattendance_session} s
                                       LEFT JOIN {zoom} z ON z.id = s.zoomid
                                           WHERE z.id IS NULL");
        self::delete_for_zoomids($zoomids);
        $DB->delete_records_select(
            'local_zoomattendance_setting',
            'cmid NOT IN (SELECT id FROM {course_modules})'
        );
        $DB->delete_records_select(
            'local_zoomattendance_idmap',
            'courseid NOT IN (SELECT id FROM {course})'
        );
    }
}
