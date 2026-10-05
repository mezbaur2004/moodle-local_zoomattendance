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
 * Tests for the class headcount.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(headcount::class)]
/**
 * Tests for the class headcount.
 *
 * @covers \local_zoomattendance\local\headcount
 */
final class headcount_test extends \advanced_testcase {
    public function test_counts_expected_students_per_class(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $full = $dg->create_and_enrol($course, 'student');
        $half = $dg->create_and_enrol($course, 'student');
        $away = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'teacher');
        $coordinator = $dg->create_and_enrol($course, 'editingteacher');

        // One past one-hour class: full stays, half for 40 minutes, away never joins.
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $full->id]);
        $generator->create_participant($session, $start, $start + 40 * MINSECS, ['userid' => $half->id]);
        // Teachers are not students: never counted.
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $coordinator->id]);
        sync::sync_all();

        $this->setAdminUser();
        $summary = course_summary::build($course);
        $occurrenceid = array_key_first($summary->activities[$cm->id]->columns);
        $expected = ['expected' => 3, status::PRESENT => 1, status::PARTIAL => 1, status::ABSENT => 1];
        $this->assertSame([$occurrenceid => $expected], headcount::from_summary($summary));

        $this->setUser($coordinator);
        $this->assertSame([$occurrenceid => $expected], headcount::for_viewer($course));

        // In separate groups, a teacher counts their own groups only, each student once.
        $DB->update_record('course', (object) ['id' => $course->id, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $course = get_course($course->id);
        $one = $dg->create_group(['courseid' => $course->id]);
        $two = $dg->create_group(['courseid' => $course->id]);
        foreach ([$one, $two] as $group) {
            $dg->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
            $dg->create_group_member(['groupid' => $group->id, 'userid' => $full->id]);
        }
        $dg->create_group_member(['groupid' => $two->id, 'userid' => $away->id]);
        rebuild_course_cache($course->id, true);
        $this->setUser($teacher);
        $this->assertSame(
            [$occurrenceid => ['expected' => 2, status::PRESENT => 1, status::PARTIAL => 0, status::ABSENT => 1]],
            headcount::for_viewer($course)
        );

        // Without access to the student reports there is nothing to count.
        $this->setUser($dg->create_user());
        $this->assertSame([], headcount::for_viewer($course));
        $this->assertNull(headcount::visible_cells($course));
    }
}
