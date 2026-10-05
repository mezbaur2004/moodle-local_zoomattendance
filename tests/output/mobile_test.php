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
 * Tests for the Moodle app views.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\output;

use local_zoomattendance\local\sync;

#[\PHPUnit\Framework\Attributes\CoversClass(mobile::class)]
/**
 * Tests for the Moodle app views.
 *
 * @covers \local_zoomattendance\output\mobile
 */
final class mobile_test extends \advanced_testcase {
    public function test_course_tab_for_students_and_teachers(): void {
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $other = $dg->create_course();
        $amy = $dg->create_and_enrol($course, 'student');
        $ben = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'editingteacher');
        $dg->enrol_user($amy->id, $other->id, 'student');
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $amy->id]);
        $generator->create_participant($session, $start, $start + 40 * MINSECS, ['userid' => $ben->id,
            'name' => '{{ constructor.constructor("alert(1)")() }}']);
        sync::sync_all();

        // The tab only shows in courses with a Zoom activity.
        $this->setUser($amy);
        $this->assertSame(['courses' => [(int) $course->id]], mobile::mobile_init([])['restrict']);

        $view = mobile::mobile_course_view(['courseid' => $course->id]);
        $this->assertSame('main', $view['templates'][0]['id']);
        $this->assertSame('100.0%', $view['otherdata']['overall']);
        $this->assertSame('success', $view['otherdata']['overallcolor']);
        $mine = json_decode($view['otherdata']['mine']);
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('Present', $mine[0]->label);
        $this->assertSame([], json_decode($view['otherdata']['classes']));
        // Text reaches the app as data, never inside the template.
        $this->assertStringNotContainsString('Zoom 1', $view['templates'][0]['html']);

        // A teacher sees how many students attended each class.
        $this->setUser($teacher);
        $view = mobile::mobile_course_view(['courseid' => $course->id]);
        $classes = json_decode($view['otherdata']['classes']);
        $this->assertSame('2 of 2 present', $classes[0]->label);
        $this->assertSame('1 present + 1 partial · 0 absent', $classes[0]->detail);
        $this->assertSame('', $view['otherdata']['overall']);
        $this->assertSame(0, $view['otherdata']['empty']);

        // Participant data masked by the Zoom plugin: no headcounts.
        set_config('maskparticipantdata', 1, 'zoom');
        $this->assertSame([], mobile::course_data($course)['classes']);
    }
}
