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
 * Tests for the health checks.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

use core\check\result;
use local_zoomattendance\check\sync_status;
use local_zoomattendance\check\zoom_reports;

/**
 * Tests for the health checks.
 *
 * @covers \local_zoomattendance\check\sync_status
 * @covers \local_zoomattendance\check\zoom_reports
 */
final class check_test extends \advanced_testcase {
    public function test_sync_check(): void {
        $this->resetAfterTest();
        $check = new sync_status();
        unset_config('lastsync', 'local_zoomattendance');
        $this->assertSame(result::WARNING, $check->get_result()->get_status());
        local\sync::sync_all();
        $this->assertSame(result::OK, $check->get_result()->get_status());
        set_config('lastsync', time() - 4 * HOURSECS, 'local_zoomattendance');
        $this->assertSame(result::ERROR, $check->get_result()->get_status());
        $this->assertNotEmpty($check->get_name());
    }

    public function test_reports_check(): void {
        $this->resetAfterTest();
        $check = new zoom_reports();
        set_config('last_call_made_at', 0, 'zoom');
        $this->assertSame(result::WARNING, $check->get_result()->get_status());
        set_config('last_call_made_at', time() - HOURSECS, 'zoom');
        $this->assertSame(result::OK, $check->get_result()->get_status());
        set_config('last_call_made_at', time() - 3 * DAYSECS, 'zoom');
        $this->assertSame(result::WARNING, $check->get_result()->get_status());
        set_config('last_call_made_at', time() - 26 * DAYSECS, 'zoom');
        $this->assertSame(result::ERROR, $check->get_result()->get_status());

        $task = \core\task\manager::get_scheduled_task(zoom_reports::TASK);
        $task->set_disabled(true);
        \core\task\manager::configure_scheduled_task($task);
        $this->assertSame(result::ERROR, $check->get_result()->get_status());

        // Both are on the site status report.
        $names = array_map(function ($check) {
            return get_class($check);
        }, \core\check\manager::get_checks('status'));
        $this->assertContains(sync_status::class, $names);
        $this->assertContains(zoom_reports::class, $names);
    }
}
