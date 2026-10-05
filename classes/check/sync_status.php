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
 * Health check: the attendance sync runs.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\check;

use core\check\result;

/**
 * Whether the hourly attendance sync has run recently.
 */
class sync_status extends \core\check\check {
    /** @var int Seconds after which a missing sync is an error. */
    public const LATE = 3 * HOURSECS;

    /**
     * Check name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checksync', 'local_zoomattendance');
    }

    /**
     * Link to the scheduled task.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/tool/task/scheduledtasks.php'),
            get_string('scheduledtasks', 'tool_task')
        );
    }

    /**
     * Result.
     *
     * @return result
     */
    public function get_result(): result {
        $last = (int) get_config('local_zoomattendance', 'lastsync');
        if (!$last) {
            return new result(result::WARNING, get_string('checksync_never', 'local_zoomattendance'));
        }
        $a = userdate($last);
        if ($last < time() - self::LATE) {
            return new result(result::ERROR, get_string('checksync_late', 'local_zoomattendance', $a));
        }
        return new result(result::OK, get_string('checksync_ok', 'local_zoomattendance', $a));
    }
}
