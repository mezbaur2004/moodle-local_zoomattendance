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

    /** @var string Config name holding the incremental sync state. */
    protected const STATE_CONFIG = 'syncstate';
    /** @var int Ids below the last highest one the incremental sync looks at again. */
    protected const ID_OVERLAP = 2000;

    /**
     * Sync every enabled instance, then remove data whose activity no longer exists.
     *
     * While teacher attendance is tracked every instance is synced, whatever its per-activity
     * switch says, so a teacher cannot hide a missed class by switching tracking off.
     *
     * Without a course, only the instances whose mod_zoom data, schedule or settings changed since
     * the last sync are synced, plus those with classes to recompute or freeze. A full pass runs
     * once a day, after a settings change, and on the first sync.
     *
     * @param int|null $courseid Limit to one course (always a full pass of it).
     * @param bool $full Sync every instance.
     * @return int Number of instances synced.
     */
    public static function sync_all(?int $courseid = null, bool $full = false): int {
        $all = settings::teacher_tracking();
        $only = null;
        $marks = null;
        if ($courseid === null) {
            [$full, $marks, $only] = self::changed_instances($full);
        }
        roster::start_run();
        try {
            $count = self::sync_instances($courseid, $only, $all);
        } finally {
            roster::end_run();
        }
        self::delete_orphans($full);
        if ($courseid === null) {
            if ($full && self::purge_expired()) {
                data_version::bump();
            }
            self::save_state($marks, $full);
        }
        return $count;
    }

    /**
     * Sync the instances of a pass.
     *
     * @param int|null $courseid
     * @param bool[]|null $only zoom id => true, null for all.
     * @param bool $all Sync instances whose tracking is switched off too.
     * @return int Number of instances synced.
     */
    protected static function sync_instances(?int $courseid, ?array $only, bool $all): int {
        $count = 0;
        foreach (zoom_source::get_instances($courseid) as $instance) {
            if ($only !== null && !isset($only[(int) $instance->id])) {
                continue;
            }
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
        return $count;
    }

    /**
     * Which instances an incremental sync needs.
     *
     * New participant and session rows are found from their ids, which only grow; schedule,
     * activity and settings changes from their modification times. Deleted mod_zoom rows are
     * only noticed by the daily full pass.
     *
     * @param bool $full Whether a full pass was asked for.
     * @return array [bool full, \stdClass marks to save, bool[]|null zoom id => true, null for all]
     */
    protected static function changed_instances(bool $full): array {
        global $DB;
        $now = time();
        $marks = (object) [
            'pid' => (int) $DB->get_field_sql('SELECT MAX(id) FROM {zoom_meeting_participants}'),
            'did' => (int) $DB->get_field_sql('SELECT MAX(id) FROM {zoom_meeting_details}'),
            'time' => $now,
            'hash' => self::settings_hash(),
        ];
        $state = json_decode((string) get_config('local_zoomattendance', self::STATE_CONFIG));
        $stale = !is_object($state) || ($state->hash ?? '') !== $marks->hash || $now - (int) ($state->full ?? 0) >= DAYSECS;
        $full = $full || $stale;
        $marks->full = $full ? $now : (int) $state->full;
        if ($full) {
            return [true, $marks, null];
        }

        // Some overlap, for rows written while the last sync ran. Ids are handed out before the
        // rows are committed, so a row can appear below the highest id seen last time.
        $since = (int) $state->time - 5 * MINSECS;
        $state->pid = max(0, (int) $state->pid - self::ID_OVERLAP);
        $state->did = max(0, (int) $state->did - self::ID_OVERLAP);
        $queries = [
            ["SELECT DISTINCT d.zoomid
                FROM {zoom_meeting_participants} p
                JOIN {zoom_meeting_details} d ON d.id = p.detailsid
               WHERE p.id > :pid", ['pid' => (int) $state->pid]],
            ["SELECT DISTINCT zoomid FROM {zoom_meeting_details} WHERE id > :did", ['did' => (int) $state->did]],
            ["SELECT DISTINCT instance FROM {event} WHERE modulename = :modname AND timemodified > :since",
                ['modname' => 'zoom', 'since' => $since]],
            ["SELECT id FROM {zoom} WHERE timemodified > :since", ['since' => $since]],
            ["SELECT DISTINCT cm.instance
                FROM {local_zoomattendance_setting} s
                JOIN {course_modules} cm ON cm.id = s.cmid
               WHERE s.timemodified > :since", ['since' => $since]],
            ["SELECT DISTINCT cm.instance
                FROM {local_zoomattendance_teacher} t
                JOIN {course_modules} cm ON cm.id = t.cmid
               WHERE t.timecreated > :since", ['since' => $since]],
            // Classes a teacher change left for recompute, and classes now due to be frozen.
            ["SELECT DISTINCT zoomid FROM {local_zoomattendance_occ} WHERE timecomputed = 0 AND restored = 0", []],
            ["SELECT DISTINCT zoomid
                FROM {local_zoomattendance_occ}
               WHERE rosterfrozen = 0 AND restored = 0 AND status <> :cancelled AND timeend <= :now",
                ['cancelled' => self::STATUS_CANCELLED, 'now' => $now]],
            // Instances never synced.
            ["SELECT z.id
                FROM {zoom} z
               WHERE NOT EXISTS (SELECT 1 FROM {local_zoomattendance_occ} o WHERE o.zoomid = z.id)
                 AND NOT EXISTS (SELECT 1 FROM {local_zoomattendance_session} s WHERE s.zoomid = z.id)", []],
        ];
        $only = [];
        foreach ($queries as [$sql, $params]) {
            foreach ($DB->get_fieldset_sql($sql, $params) as $zoomid) {
                $only[(int) $zoomid] = true;
            }
        }
        // A session can belong to another activity using the same Zoom meeting (share_sessions()).
        foreach (array_chunk(array_keys($only), 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $shared = $DB->get_fieldset_sql(
                "SELECT DISTINCT z2.id
                   FROM {zoom} z1
                   JOIN {zoom} z2 ON z2.meeting_id = z1.meeting_id AND z2.id <> z1.id
                  WHERE z1.id $insql AND z1.meeting_id > 0",
                $params
            );
            foreach ($shared as $zoomid) {
                $only[(int) $zoomid] = true;
            }
        }
        return [false, $marks, $only];
    }

    /**
     * Remember how far the sync got.
     *
     * @param \stdClass $marks From changed_instances().
     * @param bool $full Whether this was a full pass.
     */
    protected static function save_state(\stdClass $marks, bool $full): void {
        set_config(self::STATE_CONFIG, json_encode($marks), 'local_zoomattendance');
        set_config('lastsync', $marks->time, 'local_zoomattendance');
        if ($full) {
            set_config('lastfullsync', $marks->time, 'local_zoomattendance');
        }
    }

    /**
     * The settings that change how sessions map to classes: a change asks for a full pass.
     *
     * @return string
     */
    protected static function settings_hash(): string {
        $config = get_config('local_zoomattendance');
        return sha1(json_encode([
            settings::margins(),
            settings::teacher_tracking(),
            (int) ($config->defaultenabled ?? 0),
            (int) ($config->retentiondays ?? 0),
            (int) ($config->teachernotheldhours ?? 24),
        ]));
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
        global $DB;
        $writes = $DB->perf_get_writes();
        try {
            self::do_sync($instance, $recomputeall);
        } finally {
            $lock->release();
            // Cached summaries of the course may show the old figures, if anything changed.
            if ($DB->perf_get_writes() !== $writes) {
                data_version::bump_course((int) $instance->course);
            }
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

        // Restored occurrences keep the results they came with: their Zoom sessions are not in the backup.
        $occurrences = array_filter($DB->get_records('local_zoomattendance_occ', ['zoomid' => $zoomid]), function ($o) {
            return !(int) $o->restored;
        });
        // Teacher-set and regular-time windows are fixed like scheduled ones: sessions map to them first.
        $fixed = [self::SOURCE_SCHEDULE, self::SOURCE_MANUAL, self::SOURCE_PATTERN];
        $scheduled = array_filter($occurrences, function ($o) use ($fixed) {
            return in_array($o->source, $fixed, true);
        });
        $sessions = zoom_source::get_sessions($zoomid);
        if ($cutoff = settings::retention_cutoff()) {
            // Classes that started before the retention period are no longer kept (see
            // purge_expired()). Sessions a little older still map to the classes not purged yet.
            $sessions = array_filter($sessions, function ($session) use ($cutoff) {
                return (int) $session->end_time >= $cutoff - DAYSECS;
            });
        }

        // Activities sharing one Zoom meeting (course copies): the Zoom plugin files every session
        // under one of them. Each session goes to the activity whose schedule it matches.
        if ($siblings = zoom_source::sibling_ids($instance)) {
            $sessions = self::share_sessions($instance, $sessions, $siblings, $occurrences, $earlymargin, $latemargin);
        }

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
            if ($cutoff && $cluster->timestart < $cutoff) {
                // Before the retention period: not made into a class again.
                continue;
            }
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
            if ($previous === $target && $row->fingerprint === ($session->legacyfingerprint ?? null)) {
                // Unchanged since an older version stored it: only the fingerprint is new.
                $DB->set_field('local_zoomattendance_session', 'fingerprint', $session->fingerprint, ['id' => $row->id]);
                continue;
            }
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
            roster::delete_for_occurrences([$occurrence->id]);
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
            if ((int) $occurrence->restored) {
                // Never recomputed: a restored class marked for recompute keeps its figures.
                if ((int) $occurrence->timecomputed === 0) {
                    $DB->set_field('local_zoomattendance_occ', 'timecomputed', $now, ['id' => $occurrence->id]);
                }
                continue;
            }
            // A zero timecomputed marks an occurrence a teacher change left for recompute.
            if ($recomputeall || isset($dirty[$occurrence->id]) || (int) $occurrence->timecomputed === 0) {
                self::recompute_occurrence($occurrence, $bydetails[$occurrence->id] ?? [], $idmap);
            }
        }

        // Classes that are over keep the users expected at them.
        roster::freeze_due($instance, $now);
    }

    /**
     * The sessions an activity that shares its Zoom meeting with others should count.
     *
     * A session that matches the schedule of exactly one of the activities belongs to that one,
     * whichever the Zoom plugin filed it under. Any other session stays where it was filed.
     * Taking over a session another activity has recorded marks that activity's class for
     * recompute, so its figures drop the session on its next sync.
     *
     * @param \stdClass $instance
     * @param \stdClass[] $sessions This activity's own sessions, keyed by details id.
     * @param int[] $siblings Ids of the other activities using the same meeting.
     * @param \stdClass[] $occurrences This activity's occurrences (not restored).
     * @param int $earlymargin
     * @param int $latemargin
     * @return \stdClass[] The sessions to count, keyed by details id.
     */
    protected static function share_sessions(
        \stdClass $instance,
        array $sessions,
        array $siblings,
        array $occurrences,
        int $earlymargin,
        int $latemargin
    ): array {
        global $DB;
        $zoomid = (int) $instance->id;
        $isfixed = function ($o) {
            return in_array($o->source, [self::SOURCE_SCHEDULE, self::SOURCE_MANUAL], true)
                && (int) $o->status !== self::STATUS_CANCELLED && !(int) ($o->restored ?? 0);
        };
        $fixed = [$zoomid => array_filter($occurrences, $isfixed)];
        [$insql, $params] = $DB->get_in_or_equal($siblings, SQL_PARAMS_NAMED);
        foreach ($DB->get_records_select('local_zoomattendance_occ', "zoomid $insql", $params) as $o) {
            if ($isfixed($o)) {
                $fixed[(int) $o->zoomid][$o->id] = $o;
            }
        }
        // The schedules as the Zoom plugin has them now, for activities not synced yet.
        foreach (zoom_source::get_instances_by_ids($siblings) as $sibling) {
            foreach (self::scheduled_windows($sibling) as $key => [$start, $end]) {
                $id = 'w:' . $key;
                $fixed[(int) $sibling->id][$id] = (object) ['id' => $id, 'timestart' => $start, 'timeend' => $end];
            }
        }
        // The activity a session belongs to, or null when it matches no schedule or several.
        $owner = function ($detailsid, $session) use ($fixed, $earlymargin, $latemargin) {
            $matches = [];
            foreach ($fixed as $id => $list) {
                if ($list && occurrence_mapper::map_to_scheduled([$detailsid => $session], $list, $earlymargin, $latemargin)) {
                    $matches[] = $id;
                }
            }
            return count($matches) === 1 ? $matches[0] : null;
        };

        $result = [];
        foreach ($sessions as $detailsid => $session) {
            $belongs = $owner($detailsid, $session);
            if ($belongs === null || $belongs === $zoomid) {
                $result[$detailsid] = $session;
            }
        }
        $cutoff = settings::retention_cutoff();
        $adopted = [];
        foreach ($siblings as $sibling) {
            foreach (zoom_source::get_sessions($sibling) as $detailsid => $session) {
                if ($cutoff && (int) $session->end_time < $cutoff - DAYSECS) {
                    continue;
                }
                if ($owner($detailsid, $session) === $zoomid) {
                    $result[$detailsid] = $session;
                    $adopted[] = (int) $detailsid;
                }
            }
        }
        if ($adopted) {
            // The activity that recorded these sessions recomputes the classes they leave.
            foreach (array_chunk($adopted, 500) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
                $params['zoomid'] = $zoomid;
                $rows = $DB->get_records_select(
                    'local_zoomattendance_session',
                    "detailsid $insql AND zoomid <> :zoomid",
                    $params
                );
                foreach ($rows as $row) {
                    if ($row->occurrenceid !== null) {
                        $DB->set_field('local_zoomattendance_occ', 'timecomputed', 0, ['id' => $row->occurrenceid]);
                    }
                    $DB->delete_records('local_zoomattendance_session', ['id' => $row->id]);
                }
            }
        }
        return $result;
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
        if ($cutoff = settings::retention_cutoff()) {
            $windows = array_filter($windows, function ($window) use ($cutoff) {
                return $window[0] >= $cutoff;
            });
        }
        $existing = $DB->get_records(
            'local_zoomattendance_occ',
            ['zoomid' => $zoomid, 'source' => self::SOURCE_SCHEDULE, 'restored' => 0]
        );
        // A class restored from a backup stands for a scheduled slot at the same time.
        $restored = $DB->get_records_select(
            'local_zoomattendance_occ',
            'zoomid = :zoomid AND restored > 0',
            ['zoomid' => $zoomid],
            '',
            'id, timestart, timeend'
        );
        if ($restored) {
            $windows = array_filter($windows, function ($window) use ($restored) {
                foreach ($restored as $r) {
                    if ($window[0] < (int) $r->timeend && $window[1] > (int) $r->timestart) {
                        return false;
                    }
                }
                return true;
            });
        }
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
            $cutoff = settings::retention_cutoff();
            if (!$window || ($cutoff && $window[0] < $cutoff)) {
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
            'local_zoomattendance_roster',
            "occurrenceid IN (SELECT id FROM {local_zoomattendance_occ} WHERE zoomid $insql)",
            $params
        );
        $DB->delete_records_select(
            'local_zoomattendance_result',
            "occurrenceid IN (SELECT id FROM {local_zoomattendance_occ} WHERE zoomid $insql)",
            $params
        );
        $DB->delete_records_select('local_zoomattendance_session', "zoomid $insql", $params);
        $DB->delete_records_select('local_zoomattendance_occ', "zoomid $insql", $params);
    }

    /**
     * Delete the classes that started before the retention period, with their results and frozen
     * lists. The sync then leaves their Zoom sessions out, so they are not created again.
     *
     * @return int Number of classes deleted.
     */
    public static function purge_expired(): int {
        global $DB;
        $cutoff = settings::retention_cutoff();
        if (!$cutoff) {
            return 0;
        }
        $ids = $DB->get_fieldset_select('local_zoomattendance_occ', 'id', 'timestart < :cutoff', ['cutoff' => $cutoff]);
        foreach (array_chunk($ids, 500) as $chunk) {
            roster::delete_for_occurrences($chunk);
            $DB->delete_records_list('local_zoomattendance_result', 'occurrenceid', $chunk);
            $DB->set_field_select(
                'local_zoomattendance_session',
                'occurrenceid',
                null,
                'occurrenceid IN (' . implode(',', array_map('intval', $chunk)) . ')'
            );
            $DB->delete_records_list('local_zoomattendance_occ', 'id', $chunk);
        }
        return count($ids);
    }

    /**
     * Remove data whose zoom instance or course module no longer exists.
     *
     * Classes a restore is still writing have no activity yet (zoom id 0, see
     * restore_local_zoomattendance_plugin): they are left alone for two days, so a restore that
     * overlaps a sync keeps them, and only a restore that failed half way leaves them to clean up.
     *
     * @param bool $full Also remove results and frozen lists whose class is gone.
     */
    public static function delete_orphans(bool $full = false): void {
        global $DB;
        $params = ['restoring' => time() - 2 * DAYSECS];
        $zoomids = $DB->get_fieldset_sql("SELECT DISTINCT o.zoomid
                                            FROM {local_zoomattendance_occ} o
                                       LEFT JOIN {zoom} z ON z.id = o.zoomid
                                           WHERE z.id IS NULL AND (o.zoomid <> 0 OR o.restored < :restoring)
                                           UNION
                                          SELECT DISTINCT s.zoomid
                                            FROM {local_zoomattendance_session} s
                                       LEFT JOIN {zoom} z ON z.id = s.zoomid
                                           WHERE z.id IS NULL", $params);
        if (in_array(0, array_map('intval', $zoomids), true)) {
            // Only the stale part of an unfinished restore.
            $zoomids = array_diff(array_map('intval', $zoomids), [0]);
            $stale = $DB->get_fieldset_select(
                'local_zoomattendance_occ',
                'id',
                'zoomid = 0 AND restored < :restoring',
                $params
            );
            foreach (array_chunk($stale, 500) as $chunk) {
                roster::delete_for_occurrences($chunk);
                $DB->delete_records_list('local_zoomattendance_result', 'occurrenceid', $chunk);
                $DB->delete_records_list('local_zoomattendance_occ', 'id', $chunk);
            }
        }
        self::delete_for_zoomids(array_values($zoomids));
        $DB->delete_records_select(
            'local_zoomattendance_setting',
            'cmid NOT IN (SELECT id FROM {course_modules})'
        );
        $DB->delete_records_select(
            'local_zoomattendance_teacher',
            'cmid NOT IN (SELECT id FROM {course_modules})'
        );
        $DB->delete_records_select(
            'local_zoomattendance_idmap',
            'courseid NOT IN (SELECT id FROM {course})'
        );
        if ($full) {
            foreach (['local_zoomattendance_result', 'local_zoomattendance_roster'] as $table) {
                $DB->delete_records_select($table, 'occurrenceid NOT IN (SELECT id FROM {local_zoomattendance_occ})');
            }
        }
    }
}
