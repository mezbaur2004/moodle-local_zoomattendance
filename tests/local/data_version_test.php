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
 * Tests for the cached summaries.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(data_version::class)]
/**
 * Tests for the cached summaries.
 *
 * @covers \local_zoomattendance\local\data_version
 * @covers \local_zoomattendance\local\course_summary
 * @covers \local_zoomattendance\local\teacher_summary
 */
final class data_version_test extends \advanced_testcase {
    public function test_summaries_are_cached_until_the_data_changes(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $amy = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'teacher');
        // A class still running: its expected students follow the enrolments.
        $start = time() - 30 * MINSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $amy->id]);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $teacher->id]);
        sync::sync_all();
        $this->setAdminUser();

        $summary = course_summary::build($course);
        $this->assertSame([(int) $amy->id], array_keys($summary->users));
        $this->assertInstanceOf(\cm_info::class, $summary->activities[$cm->id]->cm);
        $teachers = teacher_summary::build($course);
        $this->assertInstanceOf(\cm_info::class, $teachers->classes[0]->cm);

        // Enrolling a student starts a new data version (through the role assignment event), so
        // the next build sees them.
        $ben = $dg->create_user();
        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual']),
            'userid' => $ben->id,
            'timestart' => 0,
            'timeend' => 0,
            'status' => ENROL_USER_ACTIVE,
        ]);
        $this->assertCount(1, course_summary::build($course)->users);
        role_assign($DB->get_field('role', 'id', ['shortname' => 'student']), $ben->id, \context_course::instance($course->id));
        $this->assertCount(2, course_summary::build($course)->users);

        // An exclusion is seen at once too.
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $version = data_version::get();
        manual::set_excluded($occurrence, true, 'Public holiday');
        $this->assertNotSame($version, data_version::get());
        $this->assertSame([], course_summary::build($course)->activities);
    }

    public function test_one_entry_per_summary_and_per_course_versions(): void {
        global $DB;
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $one = $dg->create_course();
        $two = $dg->create_course();
        $builds = 0;
        $build = function () use (&$builds) {
            return ++$builds;
        };

        $this->assertSame(1, data_version::cached(['k'], $build, (int) $one->id));
        $this->assertSame(1, data_version::cached(['k'], $build, (int) $one->id));
        // Another course's change leaves this course's summaries alone.
        data_version::bump_course((int) $two->id);
        $this->assertSame(1, data_version::cached(['k'], $build, (int) $one->id));
        // Its own change, or a site-wide one, rebuilds them, overwriting the one entry.
        data_version::bump_course((int) $one->id);
        $this->assertSame(2, data_version::cached(['k'], $build, (int) $one->id));
        data_version::bump();
        $this->assertSame(3, data_version::cached(['k'], $build, (int) $one->id));
        $entry = \cache::make('local_zoomattendance', 'summaries')->get(sha1(json_encode(['k'])));
        $this->assertSame(3, $entry['value']);
        // Something else the value depends on, such as the hour.
        $this->assertSame(4, data_version::cached(['k'], $build, (int) $one->id, 'next hour'));

        // The version for caches spanning courses changes with any of them.
        $any = data_version::get();
        data_version::bump_course((int) $two->id);
        $this->assertNotSame($any, data_version::get());

        // A profile update changes only the user's courses.
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $user = $dg->create_and_enrol($one, 'student');
        $before = [data_version::for_course((int) $one->id), data_version::for_course((int) $two->id)];
        $user->firstname = 'Renamed';
        user_update_user($user, false);
        $this->assertNotSame($before[0], data_version::for_course((int) $one->id));
        $this->assertSame($before[1], data_version::for_course((int) $two->id));
    }

    public function test_a_sync_that_changes_nothing_keeps_the_version(): void {
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $student = $dg->create_and_enrol($course, 'student');
        $start = time() - DAYSECS;
        $cm = $dg->get_plugin_generator('local_zoomattendance')->create_zoom(['course' => $course->id,
            'start_time' => $start, 'duration' => HOURSECS]);
        sync::sync_all();
        $instances = source\zoom_source::get_instances(null, (int) $cm->instance);
        $version = data_version::for_course((int) $course->id);
        sync::sync_instance(reset($instances));
        $this->assertSame($version, data_version::for_course((int) $course->id));

        // New Zoom data does change it.
        $session = $dg->get_plugin_generator('local_zoomattendance')->create_session($cm, $start, $start + HOURSECS);
        $dg->get_plugin_generator('local_zoomattendance')->create_participant(
            $session,
            $start,
            $start + HOURSECS,
            ['userid' => $student->id]
        );
        sync::sync_instance(reset($instances));
        $this->assertNotSame($version, data_version::for_course((int) $course->id));
    }
}
