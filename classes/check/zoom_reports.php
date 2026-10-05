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
 * Health check: the Zoom plugin fetches meeting reports.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\check;

use core\check\result;

/**
 * Whether mod_zoom's "Get meeting reports" task is enabled and has fetched reports recently.
 * Attendance only comes from those reports, and Zoom keeps them for about 30 days.
 */
class zoom_reports extends \core\check\check {
    /** @var string mod_zoom's report task. */
    public const TASK = '\\mod_zoom\\task\\get_meeting_reports';
    /** @var int Seconds after which old reports are a warning. */
    public const LATE = 2 * DAYSECS;
    /** @var int Seconds after which old reports are an error: Zoom starts dropping them. */
    public const LOST = 25 * DAYSECS;

    /**
     * Check name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('checkreports', 'local_zoomattendance');
    }

    /**
     * Link to the scheduled tasks.
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
        $task = \core\task\manager::get_scheduled_task(self::TASK);
        if ($task && $task->get_disabled()) {
            return new result(result::ERROR, get_string('checkreports_disabled', 'local_zoomattendance'));
        }
        $last = (int) get_config('zoom', 'last_call_made_at');
        if (!$last) {
            return new result(result::WARNING, get_string('checkreports_never', 'local_zoomattendance'));
        }
        $a = userdate($last);
        if ($last < time() - self::LOST) {
            return new result(result::ERROR, get_string('checkreports_lost', 'local_zoomattendance', $a));
        }
        if ($last < time() - self::LATE) {
            return new result(result::WARNING, get_string('checkreports_late', 'local_zoomattendance', $a));
        }
        return new result(result::OK, get_string('checkreports_ok', 'local_zoomattendance', $a));
    }
}
