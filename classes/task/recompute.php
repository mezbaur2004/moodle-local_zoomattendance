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
 * Background recompute of attendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\task;

use local_zoomattendance\local\source\zoom_source;
use local_zoomattendance\local\sync as sync_engine;

/**
 * Recompute attendance in the background after a change that affects many classes, such as an
 * identity link, which applies to every Zoom activity of a course.
 *
 * Custom data: courseid (every synced activity of the course) or zoomid (one activity), and
 * all (recompute every class, not only those marked for it).
 */
class recompute extends \core\task\adhoc_task {
    /**
     * Queue a recompute, unless the same one is already queued.
     *
     * @param int|null $courseid
     * @param int|null $zoomid
     * @param bool $all
     */
    public static function queue(?int $courseid, ?int $zoomid = null, bool $all = false): void {
        $task = new self();
        $task->set_custom_data((object) ['courseid' => $courseid, 'zoomid' => $zoomid, 'all' => $all]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run it. An activity another sync holds keeps its marked classes for the next sync.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $instances = zoom_source::get_instances(
            empty($data->courseid) ? null : (int) $data->courseid,
            empty($data->zoomid) ? null : (int) $data->zoomid
        );
        foreach ($instances as $instance) {
            if (empty($data->zoomid) && !$DB->record_exists('local_zoomattendance_occ', ['zoomid' => $instance->id])) {
                continue;
            }
            if (!sync_engine::sync_instance($instance, !empty($data->all))) {
                mtrace("local_zoomattendance: zoom {$instance->id} is being synced; its classes stay marked for recompute.");
            }
        }
    }
}
