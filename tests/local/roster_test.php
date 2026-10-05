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
 * Tests for the frozen lists of expected users.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(roster::class)]
/**
 * Tests for the frozen lists of expected users.
 *
 * @covers \local_zoomattendance\local\roster
 * @covers \local_zoomattendance\local\attendance
 */
final class roster_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass[] */
    protected $users = [];
    /** @var \cm_info */
    protected $cm;
    /** @var int Start of a class three days ago. */
    protected $past;
    /** @var int Start of a class that ended an hour ago. */
    protected $recent;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $this->course = $dg->create_course();
        foreach (['amy', 'ben', 'cal'] as $name) {
            $this->users[$name] = $dg->create_and_enrol($this->course, 'student', ['firstname' => ucfirst($name)]);
        }
        $this->users['teacher'] = $dg->create_and_enrol($this->course, 'teacher', ['firstname' => 'Tess']);

        // A weekly class: one three days ago, one that ended an hour ago.
        $this->past = time() - 3 * DAYSECS;
        $this->recent = time() - 2 * HOURSECS;
        $this->cm = $generator->create_zoom([
            'course' => $this->course->id,
            'recurring' => 1,
            'recurrence_type' => 2,
            'start_time' => $this->past,
            'duration' => HOURSECS,
        ]);
        foreach ([$this->past, $this->recent] as $start) {
            $generator->create_occurrence_event($this->cm, $start, HOURSECS);
            $session = $generator->create_session($this->cm, $start, $start + HOURSECS);
            foreach (['amy', 'teacher'] as $name) {
                $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $this->users[$name]->id]);
            }
        }
        sync::sync_all();
        $this->setAdminUser();
    }

    /**
     * The occurrence starting at a time.
     *
     * @param int $start
     * @return \stdClass
     */
    protected function occurrence(int $start): \stdClass {
        global $DB;
        $conditions = ['zoomid' => $this->cm->instance, 'timestart' => $start];
        return $DB->get_record('local_zoomattendance_occ', $conditions, '*', MUST_EXIST);
    }

    /**
     * The zoom_source record of the activity.
     *
     * @return \stdClass
     */
    protected function instance(): \stdClass {
        $instances = source\zoom_source::get_instances(null, (int) $this->cm->instance);
        return reset($instances);
    }

    public function test_past_class_is_frozen_and_survives_enrolment_changes(): void {
        global $DB;
        $past = $this->occurrence($this->past);
        $recent = $this->occurrence($this->recent);
        $this->assertGreaterThan(0, (int) $past->rosterfrozen);
        // Ended an hour ago: within the Not held delay, still live.
        $this->assertSame(0, (int) $recent->rosterfrozen);
        $this->assertSame(3, $DB->count_records('local_zoomattendance_roster', ['occurrenceid' => $past->id, 'kind' => 'student']));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_roster', ['occurrenceid' => $past->id, 'kind' => 'teacher']));

        // Ben leaves the course, Cal is suspended, Dee joins with an enrolment covering every class.
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $this->users['ben']->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->users['cal']->id]);
        $dee = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['firstname' => 'Dee']);

        $summary = course_summary::build($this->course);
        $counts = headcount::from_summary($summary);
        // The frozen class keeps Amy, Ben and Cal, and not Dee.
        $this->assertSame(3, $counts[$past->id]['expected']);
        $this->assertSame(1, $counts[$past->id][status::PRESENT]);
        $this->assertSame(2, $counts[$past->id][status::ABSENT]);
        $this->assertArrayHasKey($past->id, $summary->cells[$this->users['ben']->id]);
        $this->assertArrayNotHasKey($past->id, $summary->cells[$dee->id] ?? []);
        // The recent class follows the live enrolments: Amy and Dee.
        $this->assertSame(2, $counts[$recent->id]['expected']);
        $this->assertArrayHasKey($recent->id, $summary->cells[$dee->id]);
        $this->assertArrayNotHasKey($recent->id, $summary->cells[$this->users['ben']->id]);

        // Freezing again changes nothing.
        $this->assertSame(0, roster::freeze_due($this->instance()));
    }

    public function test_teacher_who_left_keeps_past_classes(): void {
        global $DB;
        $past = $this->occurrence($this->past);
        $enrol = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $enrol->unenrol_user($instance, $this->users['teacher']->id);

        $summary = teacher_summary::build($this->course);
        $teacherid = $this->users['teacher']->id;
        $this->assertArrayHasKey($teacherid, $summary->users);
        $this->assertSame(status::PRESENT, $summary->cells[$teacherid][$past->id]->status);
        $this->assertArrayNotHasKey($this->occurrence($this->recent)->id, $summary->cells[$teacherid]);
    }

    public function test_lists_go_with_their_class_and_user(): void {
        global $DB;
        $past = $this->occurrence($this->past);
        delete_user($this->users['ben']);
        $this->assertFalse($DB->record_exists('local_zoomattendance_roster', ['userid' => $this->users['ben']->id]));
        $this->assertSame(3, $DB->count_records('local_zoomattendance_roster', ['occurrenceid' => $past->id]));

        sync::delete_for_zoomids([(int) $this->cm->instance]);
        $this->assertSame(0, $DB->count_records('local_zoomattendance_roster'));
    }
}
