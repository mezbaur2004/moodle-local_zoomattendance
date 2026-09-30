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
 * Tests for teacher visibility.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(teacher_access::class)]
/**
 * Tests for teacher visibility.
 *
 * @covers \local_zoomattendance\local\teacher_access
 */
final class teacher_access_test extends \advanced_testcase {
    public function test_editing_teachers_see_non_editing_teachers(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $context = \context_course::instance($course->id);
        $editor = $dg->create_and_enrol($course, 'editingteacher', ['lastname' => 'A']);
        $coeditor = $dg->create_and_enrol($course, 'editingteacher', ['lastname' => 'B']);
        $assistant = $dg->create_and_enrol($course, 'teacher', ['lastname' => 'C']);
        $student = $dg->create_and_enrol($course, 'student', ['lastname' => 'D']);
        $manager = $dg->create_and_enrol($course, 'manager');
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        foreach ([$editor, $coeditor, $assistant] as $teacher) {
            $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $teacher->id]);
        }
        sync::sync_all();

        // Editing teachers: themself and the non-editing teacher, not the other editing teacher.
        $this->assertTrue(teacher_access::can_view($context, (int) $assistant->id, (int) $editor->id));
        $this->assertFalse(teacher_access::can_view($context, (int) $coeditor->id, (int) $editor->id));
        $this->assertTrue(teacher_access::can_view($context, (int) $student->id, (int) $editor->id));
        $visible = teacher_access::visible_teachers($context, (int) $editor->id);
        sort($visible);
        $this->assertEquals([$editor->id, $assistant->id], $visible);
        $summary = teacher_summary::build($course, $visible);
        $this->assertEquals([$editor->id, $assistant->id], array_keys($summary->users));

        // Non-editing teachers: only themself.
        $this->assertFalse(teacher_access::can_view($context, (int) $editor->id, (int) $assistant->id));
        $this->assertTrue(teacher_access::can_view($context, (int) $assistant->id, (int) $assistant->id));
        $this->assertEquals([$assistant->id], teacher_access::visible_teachers($context, (int) $assistant->id));

        // Managers: everyone; students: no teacher.
        $this->assertNull(teacher_access::visible_teachers($context, (int) $manager->id));
        $this->assertTrue(teacher_access::can_view($context, (int) $coeditor->id, (int) $manager->id));
        $this->assertFalse(teacher_access::can_view_any($context, (int) $student->id));
        $this->assertSame([], teacher_access::visible_teachers($context, (int) $student->id));
        $this->assertSame([], teacher_summary::build($course, [])->users);

        // The own view across courses lists the editing teacher and the non-editing teacher.
        $rows = teacher_overview::rows((int) $editor->id, true, 0, time());
        $this->assertEquals([$editor->id, $assistant->id], array_map(function ($row) {
            return (int) $row->user->id;
        }, $rows));
        $this->assertCount(1, teacher_overview::rows((int) $assistant->id, true, 0, time()));

        // The rule is a capability: without it, editing teachers see only themself.
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('local/zoomattendance:viewnoneditingteachers', CAP_PROHIBIT, $role, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(teacher_access::can_view($context, (int) $assistant->id, (int) $editor->id));
        $this->assertEquals([$editor->id], teacher_access::visible_teachers($context, (int) $editor->id));

        // Occurrence reports keep only the visible teachers.
        $attendance = new attendance($cm);
        $rows = [$coeditor->id => 1, $assistant->id => 2, $student->id => 3];
        $this->assertEquals([$assistant->id, $student->id], array_keys($attendance->without_teachers($rows, [$assistant->id])));
    }
}
