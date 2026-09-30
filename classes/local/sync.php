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
 * @copyright  2026 Pedago Academy
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

    /** @var int Occurrence counts normally. */
    public const STATUS_ACTIVE = 0;
    /** @var int A future occurrence disappeared from the mod_zoom schedule. */
    public const STATUS_CANCELLED = 1;
    /** @var int A teacher excluded the occurrence. */
    public const STATUS_EXCLUDED = 2;

    /** @var string Key of the only occurrence of a non-recurring meeting. */
    public const KEY_SINGLE = 'single';

    /**
     * Sync every enabled instance, then remove data whose activity no longer exists.
     *
     * @param int|null $courseid Limit to one course.
     * @return int Number of instances synced.
     */
    public static function sync_all(?int $courseid = null): int {
        $count = 0;
        foreach (zoom_source::get_instances($courseid) as $instance) {
            if (!settings::from_override(zoom_source::override_from_instance($instance))->enabled) {
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

        $occurrences = $DB->get_records('local_zoomatt_occurrence', ['zoomid' => $zoomid]);
        $scheduled = array_filter($occurrences, function ($o) {
            return $o->source === self::SOURCE_SCHEDULE;
        });
        $sessions = zoom_source::get_sessions($zoomid);

        // Map sessions: scheduled occurrences first, clusters of the rest become inferred ones.
        $map = occurrence_mapper::map_to_scheduled($sessions, $scheduled, $earlymargin, $latemargin);
        $unmapped = array_diff_key($sessions, $map);
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
                    $DB->update_record('local_zoomatt_occurrence', $occurrence);
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
        $rows = $DB->get_records('local_zoomatt_session', ['zoomid' => $zoomid], '', '*');
        $rowsbydetails = [];
        foreach ($rows as $row) {
            $rowsbydetails[$row->detailsid] = $row;
        }
        foreach ($sessions as $detailsid => $session) {
            $target = $map[$detailsid] ?? null;
            $row = $rowsbydetails[$detailsid] ?? null;
            unset($rowsbydetails[$detailsid]);
            if (!$row) {
                $DB->insert_record('local_zoomatt_session', (object) [
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
                $DB->update_record('local_zoomatt_session', $row);
            }
        }
        foreach ($rowsbydetails as $row) {
            // The mod_zoom session is gone (instance delete, privacy delete).
            $dirty[$row->occurrenceid] = true;
            $DB->delete_records('local_zoomatt_session', ['id' => $row->id]);
        }

        // Inferred occurrences that no longer have sessions are derived data: drop them.
        foreach ($inferredbykey as $occurrence) {
            $DB->delete_records('local_zoomatt_result', ['occurrenceid' => $occurrence->id]);
            $DB->delete_records('local_zoomatt_occurrence', ['id' => $occurrence->id]);
            unset($dirty[$occurrence->id]);
        }

        unset($dirty['']);
        $occurrences = $DB->get_records('local_zoomatt_occurrence', ['zoomid' => $zoomid]);
        $bydetails = [];
        foreach ($map as $detailsid => $occurrenceid) {
            $bydetails[$occurrenceid][$detailsid] = $sessions[$detailsid];
        }
        foreach ($occurrences as $occurrence) {
            if ($recomputeall || isset($dirty[$occurrence->id])) {
                self::recompute_occurrence($occurrence, $bydetails[$occurrence->id] ?? []);
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
        $existing = $DB->get_records('local_zoomatt_occurrence', ['zoomid' => $zoomid, 'source' => self::SOURCE_SCHEDULE]);
        $dirty = [];

        foreach ($existing as $occurrence) {
            $key = $occurrence->occurrencekey;
            if (!isset($windows[$key])) {
                if ((int) $occurrence->timestart > $now && (int) $occurrence->status === self::STATUS_ACTIVE) {
                    $occurrence->status = self::STATUS_CANCELLED;
                    $occurrence->timemodified = $now;
                    $DB->update_record('local_zoomatt_occurrence', $occurrence);
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
                $DB->update_record('local_zoomatt_occurrence', $occurrence);
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
        $occurrence->id = $DB->insert_record('local_zoomatt_occurrence', $occurrence);
        return $occurrence;
    }

    /**
     * Recompute every participant's attended time for one occurrence.
     *
     * @param \stdClass $occurrence
     * @param \stdClass[] $sessions Sessions mapped to it, keyed by details id.
     */
    public static function recompute_occurrence(\stdClass $occurrence, array $sessions): void {
        global $DB;
        $start = (int) $occurrence->timestart;
        $end = (int) $occurrence->timeend;
        $now = time();

        $spans = array_map([occurrence_mapper::class, 'span'], $sessions);
        $actualsecs = calculator::summarise($spans, $start, $end)->attendedsecs;

        $identities = self::group_by_identity(zoom_source::get_participants(array_keys($sessions)));
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
        $existing = $DB->get_records('local_zoomatt_result', ['occurrenceid' => $occurrence->id]);
        foreach ($existing as $row) {
            if (!isset($computed[$row->identitykey])) {
                $DB->delete_records('local_zoomatt_result', ['id' => $row->id]);
                continue;
            }
            $new = $computed[$row->identitykey];
            unset($computed[$row->identitykey]);
            $new->id = $row->id;
            $DB->update_record('local_zoomatt_result', $new);
        }
        foreach ($computed as $new) {
            $DB->insert_record('local_zoomatt_result', $new);
        }
        $DB->update_record('local_zoomatt_occurrence', (object) [
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
     * a second row) is kept only in its matched form.
     *
     * @param \stdClass[] $participants zoom_meeting_participants rows.
     * @return \stdClass[] identity key => {userid, name, emails[], intervals[]}.
     */
    public static function group_by_identity(array $participants): array {
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
            $key = $userid ? 'u:' . $userid : self::unmatched_key($p);
            if (!isset($identities[$key])) {
                $identities[$key] = (object) ['userid' => $userid, 'name' => '', 'emails' => [], 'intervals' => []];
            }
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
     * Match strength per matched user: 2 when a segment's email equals the user's email or
     * mod_zoom API identifier, 1 otherwise (name, "(id)Name" prefix or fuzzy match).
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
            $strength[$identity->userid] = array_intersect_key(array_flip(array_filter($known)), $identity->emails) ? 2 : 1;
        }
        return $strength;
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
            'local_zoomatt_result',
            "occurrenceid IN (SELECT id FROM {local_zoomatt_occurrence} WHERE zoomid $insql)",
            $params
        );
        $DB->delete_records_select('local_zoomatt_session', "zoomid $insql", $params);
        $DB->delete_records_select('local_zoomatt_occurrence', "zoomid $insql", $params);
    }

    /**
     * Remove data whose zoom instance or course module no longer exists.
     */
    public static function delete_orphans(): void {
        global $DB;
        $zoomids = $DB->get_fieldset_sql("SELECT DISTINCT o.zoomid
                                            FROM {local_zoomatt_occurrence} o
                                       LEFT JOIN {zoom} z ON z.id = o.zoomid
                                           WHERE z.id IS NULL
                                           UNION
                                          SELECT DISTINCT s.zoomid
                                            FROM {local_zoomatt_session} s
                                       LEFT JOIN {zoom} z ON z.id = s.zoomid
                                           WHERE z.id IS NULL");
        self::delete_for_zoomids($zoomids);
        $DB->delete_records_select(
            'local_zoomatt_settings',
            'cmid NOT IN (SELECT id FROM {course_modules})'
        );
    }
}
