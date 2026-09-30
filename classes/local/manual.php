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
 * Teacher corrections: identity links and occurrence windows.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

use local_zoomattendance\local\source\zoom_source;

/**
 * Teacher corrections that feed back into the sync: linking an unmatched Zoom participant to a
 * user (per course), and setting the window of an inferred occurrence.
 *
 * Each change marks the affected occurrences for recompute before syncing, so it still takes
 * effect on the next scheduled sync if another sync holds the lock right now.
 */
class manual {
    /**
     * Link an unmatched Zoom identity to a user enrolled in the course.
     *
     * @param int $courseid
     * @param string $identitykey A z: identity key from a result row.
     * @param int $userid
     * @param string|null $displayname The Zoom name, kept for reference.
     * @return bool False when the recompute could not run now (it will on the next sync).
     */
    public static function link_identity(int $courseid, string $identitykey, int $userid, ?string $displayname): bool {
        global $DB;
        if (!self::is_unmatched_key($identitykey)) {
            throw new \coding_exception('Only unmatched (z:) identities can be linked.');
        }
        if (!is_enrolled(\context_course::instance($courseid), $userid)) {
            throw new \moodle_exception('errornotenrolled', 'local_zoomattendance');
        }
        $record = $DB->get_record('local_zoomattendance_idmap', ['courseid' => $courseid, 'identitykey' => $identitykey]);
        if ($record) {
            $record->userid = $userid;
            $DB->update_record('local_zoomattendance_idmap', $record);
        } else {
            $DB->insert_record('local_zoomattendance_idmap', (object) [
                'courseid' => $courseid,
                'identitykey' => $identitykey,
                'userid' => $userid,
                'displayname' => $displayname === null ? null : \core_text::substr($displayname, 0, 255),
                'timecreated' => time(),
            ]);
        }
        return self::recompute_course($courseid);
    }

    /**
     * Remove an identity link.
     *
     * @param int $courseid
     * @param int $linkid local_zoomattendance_idmap id.
     * @return bool False when the recompute could not run now.
     */
    public static function unlink_identity(int $courseid, int $linkid): bool {
        global $DB;
        $DB->delete_records('local_zoomattendance_idmap', ['id' => $linkid, 'courseid' => $courseid]);
        return self::recompute_course($courseid);
    }

    /**
     * Whether an identity key belongs to an unmatched participant.
     *
     * @param string $identitykey
     * @return bool
     */
    public static function is_unmatched_key(string $identitykey): bool {
        return (bool) preg_match('/^z:[0-9a-f]{40}$/', $identitykey);
    }

    /**
     * Whether a teacher may set the window of an occurrence: only inferred or already manual
     * ones. Scheduled windows come from mod_zoom and are refreshed from its calendar.
     *
     * @param \stdClass $occurrence
     * @return bool
     */
    public static function can_set_window(\stdClass $occurrence): bool {
        return in_array($occurrence->source, [sync::SOURCE_INFERRED, sync::SOURCE_MANUAL], true);
    }

    /**
     * Set the window of an inferred (or already manual) occurrence.
     *
     * The occurrence becomes manual and gets a new m: key, so a later cluster of the same
     * sessions can never collide with it.
     *
     * @param \stdClass $occurrence
     * @param int $start
     * @param int $end
     * @return bool False when the recompute could not run now.
     */
    public static function set_window(\stdClass $occurrence, int $start, int $end): bool {
        global $DB;
        if (!self::can_set_window($occurrence)) {
            throw new \coding_exception('Only inferred or manual occurrences can have their window set.');
        }
        if ($end <= $start) {
            throw new \coding_exception('The window must end after it starts.');
        }
        $update = (object) [
            'id' => $occurrence->id,
            'timestart' => $start,
            'timeend' => $end,
            'timecomputed' => 0,
            'timemodified' => time(),
        ];
        if ($occurrence->source === sync::SOURCE_INFERRED) {
            $update->source = sync::SOURCE_MANUAL;
            $update->occurrencekey = 'm:' . sha1($occurrence->occurrencekey . '|' . $occurrence->id);
        }
        $DB->update_record('local_zoomattendance_occ', $update);
        return self::sync_zoom((int) $occurrence->zoomid);
    }

    /**
     * Drop a manual window. Its sessions become inferred again on the sync this triggers.
     *
     * @param \stdClass $occurrence
     * @return bool False when the sync could not run now.
     */
    public static function revert_window(\stdClass $occurrence): bool {
        global $DB;
        if ($occurrence->source !== sync::SOURCE_MANUAL) {
            throw new \coding_exception('Only manual occurrences can be reverted.');
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]);
        $DB->delete_records('local_zoomattendance_occ', ['id' => $occurrence->id]);
        $transaction->allow_commit();
        return self::sync_zoom((int) $occurrence->zoomid);
    }

    /**
     * Mark every occurrence in the course for recompute, then resync the course.
     *
     * @param int $courseid
     * @return bool False when at least one activity was locked by another sync.
     */
    protected static function recompute_course(int $courseid): bool {
        global $DB;
        $DB->execute("UPDATE {local_zoomattendance_occ}
                         SET timecomputed = 0
                       WHERE zoomid IN (SELECT id FROM {zoom} WHERE course = :courseid)", ['courseid' => $courseid]);
        $done = true;
        foreach (zoom_source::get_instances($courseid) as $instance) {
            if ($DB->record_exists('local_zoomattendance_occ', ['zoomid' => $instance->id])) {
                $done = sync::sync_instance($instance) && $done;
            }
        }
        return $done;
    }

    /**
     * Sync one zoom instance.
     *
     * @param int $zoomid
     * @return bool
     */
    protected static function sync_zoom(int $zoomid): bool {
        $instances = zoom_source::get_instances(null, $zoomid);
        return $instances ? sync::sync_instance(reset($instances)) : true;
    }
}
