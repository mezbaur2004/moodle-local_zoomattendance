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
 * Event observers.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

use local_zoomattendance\local\sync;
use local_zoomattendance\local\source\zoom_source;

/**
 * Keeps attendance data in step with deletions and course resets.
 */
class observer {
    /**
     * A course module was deleted.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        $DB->delete_records('local_zoomattendance_setting', ['cmid' => $event->objectid]);
        if (($event->other['modulename'] ?? '') === 'zoom') {
            sync::delete_for_zoomids([(int) $event->other['instanceid']]);
        }
    }

    /**
     * A course was deleted. Its zoom rows are already gone, so remove orphans.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        sync::delete_orphans();
    }

    /**
     * A user was deleted.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $DB->delete_records('local_zoomattendance_result', ['userid' => $event->objectid]);
    }

    /**
     * A course was reset. mod_zoom's reset may have deleted participant rows, so re-sync the
     * course's activities; results always mirror the mod_zoom data.
     *
     * @param \core\event\course_reset_ended $event
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        global $DB;
        foreach (zoom_source::get_instances((int) $event->courseid) as $instance) {
            $enabled = \local_zoomattendance\local\settings::from_override(
                zoom_source::override_from_instance($instance)
            )->enabled;
            if (!$enabled && !$DB->record_exists('local_zoomattendance_occ', ['zoomid' => $instance->id])) {
                continue;
            }
            try {
                sync::sync_instance($instance, true);
            } catch (\Throwable $e) {
                debugging('local_zoomattendance: reset sync failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
