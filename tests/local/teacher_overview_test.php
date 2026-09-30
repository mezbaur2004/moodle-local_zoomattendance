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
 * Tests for the teacher attendance list.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(teacher_overview::class)]
/**
 * Tests for the teacher attendance list.
 *
 * @covers \local_zoomattendance\local\teacher_overview
 */
final class teacher_overview_test extends \advanced_testcase {
    public function test_rows_are_scoped_by_capability_and_category(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $start = time() - DAYSECS;
        $categories = [$dg->create_category(), $dg->create_category()];
        $teachers = [];
        foreach ($categories as $i => $category) {
            $course = $dg->create_course(['category' => $category->id, 'fullname' => "Course $i"]);
            $teachers[$i] = $dg->create_and_enrol($course, 'editingteacher', ['lastname' => "Teacher $i"]);
            $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
            $session = $generator->create_session($cm, $start, $start + HOURSECS);
            $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $teachers[$i]->id]);
        }
        $dg->create_course();
        sync::sync_all();
        $manager = $dg->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, \context_system::instance()->id);
        $student = $dg->create_user();

        $rows = teacher_overview::rows((int) $manager->id, false, 0, time());
        $this->assertCount(2, $rows);
        $this->assertEquals($teachers[0]->id, $rows[0]->user->id);
        $this->assertSame(1, $rows[0]->stats[status::PRESENT]);
        $this->assertEqualsWithDelta(100.0, $rows[0]->overall->percentage(), 0.01);

        $rows = teacher_overview::rows((int) $manager->id, false, 0, time(), (int) $categories[1]->id);
        $this->assertCount(1, $rows);
        $this->assertEquals($teachers[1]->id, $rows[0]->user->id);

        // A teacher sees only their own row, and nothing without viewteacherreports.
        $rows = teacher_overview::rows((int) $teachers[0]->id, true, 0, time());
        $this->assertCount(1, $rows);
        $this->assertEquals($teachers[0]->id, $rows[0]->user->id);
        $this->assertSame([], teacher_overview::courses((int) $teachers[0]->id, 'local/zoomattendance:viewteacherreports'));
        $this->assertSame([], teacher_overview::rows((int) $student->id, true, 0, time()));

        // The date range limits the rows.
        $this->assertSame([], teacher_overview::rows((int) $manager->id, false, $start + 1, time()));
    }

    public function test_helpers(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $parent = $dg->create_category(['name' => 'Science']);
        $child = $dg->create_category(['name' => 'Biology', 'parent' => $parent->id]);
        $other = $dg->create_category(['name' => 'Arts']);
        $withzoom = $dg->create_course(['category' => $child->id]);
        $nozoom = $dg->create_course(['category' => $other->id]);
        $teacher = $dg->create_and_enrol($withzoom, 'editingteacher');
        $artteacher = $dg->create_and_enrol($nozoom, 'editingteacher');
        $this->assertNull(teacher_overview::first_class((int) $withzoom->id));
        $start = time() - 3 * DAYSECS;
        $cm = $generator->create_zoom(['course' => $withzoom->id, 'start_time' => $start, 'duration' => HOURSECS]);
        sync::sync_all();

        // A teacher only in a course without Zoom has nothing to see (and gets no profile link).
        $this->assertTrue(teacher_overview::has_courses((int) $teacher->id, 'local/zoomattendance:viewownteacher'));
        $this->assertFalse(teacher_overview::has_courses((int) $artteacher->id, 'local/zoomattendance:viewownteacher'));
        $this->assertSame($start, teacher_overview::first_class((int) $withzoom->id));

        // The category filter offers the viewer's categories and their parents only.
        $manager = $dg->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \context_coursecat::instance($parent->id)->id
        );
        $categories = teacher_overview::categories((int) $manager->id, 'local/zoomattendance:viewteacherreports');
        $this->assertEquals([$parent->id, $child->id], array_keys($categories));

        // A teacher whose classes in range are all reset has no row.
        $DB->set_field('local_zoomattendance_occ', 'status', sync::STATUS_RESET, ['zoomid' => $cm->instance]);
        $this->assertSame([], teacher_overview::rows((int) $manager->id, false, 0, time()));
    }

    public function test_day_end_across_daylight_saving(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/London');
        // 25 October 2026 has 25 hours in London.
        $day = make_timestamp(2026, 10, 25);
        $this->assertSame(make_timestamp(2026, 10, 26), teacher_overview::day_end($day));
        $this->assertSame(make_timestamp(2026, 3, 30), teacher_overview::day_end(make_timestamp(2026, 3, 29)));
        $this->assertSame(make_timestamp(2026, 7, 2), teacher_overview::day_end(make_timestamp(2026, 7, 1)));
    }
}
