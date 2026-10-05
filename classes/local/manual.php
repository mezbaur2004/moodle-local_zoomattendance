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
 * Teacher corrections: linking an unmatched Zoom participant to a user (per course), setting
 * the window of an inferred occurrence, and excluding an occurrence. Each records who made it
 * and is logged, because it can change teacher attendance figures too.
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
     * @return bool False: attendance is recomputed in the background.
     */
    public static function link_identity(int $courseid, string $identitykey, int $userid, ?string $displayname): bool {
        global $DB, $USER;
        if (!self::is_unmatched_key($identitykey)) {
            throw new \coding_exception('Only unmatched (z:) identities can be linked.');
        }
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $userid)) {
            throw new \moodle_exception('errornotenrolled', 'local_zoomattendance');
        }
        self::require_link_to($context, $userid);
        $record = $DB->get_record('local_zoomattendance_idmap', ['courseid' => $courseid, 'identitykey' => $identitykey]);
        if ($record) {
            $record->userid = $userid;
            $record->usermodified = (int) $USER->id;
            $DB->update_record('local_zoomattendance_idmap', $record);
        } else {
            $record = (object) [
                'courseid' => $courseid,
                'identitykey' => $identitykey,
                'userid' => $userid,
                'displayname' => $displayname === null ? null : \core_text::substr($displayname, 0, 255),
                'timecreated' => time(),
                'usermodified' => (int) $USER->id,
            ];
            $record->id = $DB->insert_record('local_zoomattendance_idmap', $record);
        }
        \local_zoomattendance\event\identity_linked::create_from_link($record)->trigger();
        return self::recompute_course($courseid);
    }

    /**
     * Remove an identity link.
     *
     * @param int $courseid
     * @param int $linkid local_zoomattendance_idmap id.
     * @return bool False: attendance is recomputed in the background.
     */
    public static function unlink_identity(int $courseid, int $linkid): bool {
        global $DB;
        $link = $DB->get_record('local_zoomattendance_idmap', ['id' => $linkid, 'courseid' => $courseid]);
        if ($link) {
            self::require_link_to(\context_course::instance($courseid), (int) $link->userid);
            $DB->delete_records('local_zoomattendance_idmap', ['id' => $link->id]);
            \local_zoomattendance\event\identity_unlinked::create_from_link($link)->trigger();
        }
        return self::recompute_course($courseid);
    }

    /**
     * Whether the current user may link Zoom identities to a user, or remove such links.
     *
     * A link adds the participant's time to the user's. While teacher attendance is tracked,
     * linking to (or unlinking from) a tracked teacher takes excludetracked too, so teachers
     * cannot add someone else's Zoom time to their own or a colleague's figures.
     *
     * @param \context_course $context
     * @param int $userid The user the identity is linked to.
     * @return bool
     */
    public static function can_link_to(\context_course $context, int $userid): bool {
        if (!settings::teacher_tracking() || has_capability('local/zoomattendance:excludetracked', $context)) {
            return true;
        }
        return !has_capability('local/zoomattendance:betrackedteacher', $context, $userid);
    }

    /**
     * Throw unless the current user may link identities to a user, see can_link_to().
     *
     * @param \context_course $context
     * @param int $userid
     */
    protected static function require_link_to(\context_course $context, int $userid): void {
        if (!self::can_link_to($context, $userid)) {
            throw new \required_capability_exception($context, 'local/zoomattendance:excludetracked', 'nopermissions', '');
        }
    }

    /**
     * Whether the current user may exclude classes of an activity, or include them again, and
     * set the window of a class.
     *
     * Excluded classes do not count against teachers, and a window decides how late a teacher
     * joined, so while teacher attendance is tracked teachers cannot change either for their
     * own classes: it takes excludetracked as well, given to managers by default.
     *
     * @param \context_module $context
     * @return bool
     */
    public static function can_exclude(\context_module $context): bool {
        if (!has_capability('local/zoomattendance:manage', $context)) {
            return false;
        }
        return !settings::teacher_tracking() || has_capability('local/zoomattendance:excludetracked', $context);
    }

    /**
     * Exclude an occurrence from the figures, with a reason, or include it again. Cancelled
     * occurrences, and those from before a Zoom data reset, stay as they are. Records who made
     * the change.
     *
     * @param \stdClass $occurrence
     * @param bool $excluded
     * @param string $reason Why it is excluded; required to exclude.
     */
    public static function set_excluded(\stdClass $occurrence, bool $excluded, string $reason = ''): void {
        global $DB, $USER;
        if (in_array((int) $occurrence->status, [sync::STATUS_CANCELLED, sync::STATUS_RESET], true)) {
            return;
        }
        $reason = trim($reason);
        if ($excluded && $reason === '') {
            throw new \coding_exception('A reason is required to exclude a class.');
        }
        $occurrence->status = $excluded ? sync::STATUS_EXCLUDED : sync::STATUS_ACTIVE;
        $occurrence->excludereason = $excluded ? \core_text::substr($reason, 0, 255) : null;
        $occurrence->usermodified = (int) $USER->id;
        $occurrence->timemodified = time();
        $DB->update_record('local_zoomattendance_occ', (object) [
            'id' => $occurrence->id,
            'status' => $occurrence->status,
            'excludereason' => $occurrence->excludereason,
            'usermodified' => $occurrence->usermodified,
            'timemodified' => $occurrence->timemodified,
        ]);
        $cm = self::get_cm($occurrence);
        data_version::bump_course((int) $cm->course);
        $class = $excluded ? \local_zoomattendance\event\occurrence_excluded::class
            : \local_zoomattendance\event\occurrence_included::class;
        $class::create_from_occurrence($occurrence, $cm)->trigger();
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
     * Whether a teacher may set the window of an occurrence: only inferred, regular-time or
     * already manual ones. Scheduled windows come from mod_zoom and are refreshed from its calendar.
     *
     * @param \stdClass $occurrence
     * @return bool
     */
    public static function can_set_window(\stdClass $occurrence): bool {
        return in_array($occurrence->source, [sync::SOURCE_INFERRED, sync::SOURCE_PATTERN, sync::SOURCE_MANUAL], true);
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
        global $DB, $USER;
        if (!self::can_set_window($occurrence)) {
            throw new \coding_exception('Only inferred, regular-time or manual occurrences can have their window set.');
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
            'usermodified' => (int) $USER->id,
        ];
        if ($occurrence->source !== sync::SOURCE_MANUAL) {
            $update->source = sync::SOURCE_MANUAL;
            $update->occurrencekey = 'm:' . sha1($occurrence->occurrencekey . '|' . $occurrence->id);
        }
        $DB->update_record('local_zoomattendance_occ', $update);
        $event = \local_zoomattendance\event\window_set::create_from_occurrence(
            (object) array_merge((array) $occurrence, (array) $update),
            self::get_cm($occurrence)
        );
        $event->trigger();
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
        $event = \local_zoomattendance\event\window_reverted::create_from_occurrence($occurrence, self::get_cm($occurrence));
        $transaction = $DB->start_delegated_transaction();
        roster::delete_for_occurrences([$occurrence->id]);
        $DB->delete_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]);
        $DB->delete_records('local_zoomattendance_occ', ['id' => $occurrence->id]);
        $transaction->allow_commit();
        $event->trigger();
        return self::sync_zoom((int) $occurrence->zoomid);
    }

    /**
     * Mark every occurrence in the course for recompute, and recompute them in the background:
     * an identity link applies to every Zoom activity of the course, which can take a while.
     *
     * @param int $courseid
     * @return bool False: the recompute is queued, not done yet.
     */
    protected static function recompute_course(int $courseid): bool {
        global $DB;
        // Restored classes keep their figures: they are never recomputed.
        $DB->execute("UPDATE {local_zoomattendance_occ}
                         SET timecomputed = 0
                       WHERE restored = 0
                         AND zoomid IN (SELECT id FROM {zoom} WHERE course = :courseid)", ['courseid' => $courseid]);
        \local_zoomattendance\task\recompute::queue($courseid);
        return false;
    }

    /**
     * The zoom course module of an occurrence.
     *
     * @param \stdClass $occurrence
     * @return \stdClass
     */
    protected static function get_cm(\stdClass $occurrence): \stdClass {
        return get_coursemodule_from_instance('zoom', $occurrence->zoomid, 0, false, MUST_EXIST);
    }

    /**
     * Sync one zoom instance.
     *
     * @param int $zoomid
     * @return bool
     */
    protected static function sync_zoom(int $zoomid): bool {
        $instances = zoom_source::get_instances(null, $zoomid);
        if (!$instances || sync::sync_instance(reset($instances))) {
            return true;
        }
        // Another sync holds the activity: finish in the background.
        \local_zoomattendance\task\recompute::queue(null, $zoomid);
        return false;
    }
}
