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
 * Observer tests.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

use local_zoomattendance\local\sync;

#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Observer tests.
 *
 * @covers \local_zoomattendance\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Create a synced activity with one attending student.
     *
     * @return array [course, cm, user]
     */
    protected function setup_activity(): array {
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $user = $dg->create_and_enrol($course, 'student');
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $user->id]);
        sync::sync_all();
        return [$course, $cm, $user];
    }

    public function test_course_module_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->setup_activity();
        $DB->insert_record('local_zoomattendance_setting', (object) ['cmid' => $cm->id, 'enabled' => 1, 'timemodified' => time()]);
        $this->assertSame(1, $DB->count_records('local_zoomattendance_result'));

        // Delete synchronously; mod_zoom's delete would call the Zoom API, so remove the row first.
        $DB->delete_records('zoom', ['id' => $cm->instance]);
        $event = \core\event\course_module_deleted::create([
            'courseid' => $cm->course,
            'context' => \context_module::instance($cm->id),
            'objectid' => $cm->id,
            'other' => ['modulename' => 'zoom', 'instanceid' => $cm->instance],
        ]);
        $event->trigger();

        $this->assertSame(0, $DB->count_records('local_zoomattendance_result'));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ'));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_session'));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_setting'));
    }

    public function test_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, , $user] = $this->setup_activity();
        $DB->insert_record('local_zoomattendance_idmap', (object) ['courseid' => $course->id,
            'identitykey' => 'z:' . sha1('x'), 'userid' => $user->id, 'timecreated' => time()]);
        delete_user($user);
        $this->assertFalse($DB->record_exists('local_zoomattendance_result', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_zoomattendance_idmap', ['userid' => $user->id]));
    }

    public function test_course_reset_mirrors_mod_zoom(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/lib.php');
        [$course, , $user] = $this->setup_activity();
        $DB->insert_record('local_zoomattendance_idmap', (object) ['courseid' => $course->id,
            'identitykey' => 'z:' . sha1('x'), 'userid' => $user->id, 'timecreated' => time()]);
        $this->assertSame(1, $DB->count_records('local_zoomattendance_result'));

        // A reset that leaves the Zoom data alone keeps the links.
        reset_course_userdata((object) ['id' => $course->id, 'reset_start_date_old' => $course->startdate]);
        $this->assertSame(1, $DB->count_records('local_zoomattendance_idmap'));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_result'));

        reset_course_userdata((object) ['id' => $course->id, 'reset_zoom_all' => 1,
            'reset_start_date_old' => $course->startdate]);
        $this->assertSame(0, $DB->count_records('zoom_meeting_participants'));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_result'));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_idmap'));
    }
}
