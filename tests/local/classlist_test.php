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
 * Tests for changes to class lists after a class ended.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(roster::class)]
/**
 * Tests for changes to class lists: people who joined before they were enrolled, changes by
 * hand, and hidden activities.
 *
 * @covers \local_zoomattendance\local\roster
 * @covers \local_zoomattendance\local\course_summary
 */
final class classlist_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass[] */
    protected $users = [];
    /** @var \cm_info */
    protected $cm;
    /** @var int[] Start of each class: two days and one day ago. */
    protected $starts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $this->course = $dg->create_course(['fullname' => 'Spoken English']);
        $this->users = [
            'amy' => $dg->create_and_enrol($this->course, 'student', ['firstname' => 'Amy']),
            'coordinator' => $dg->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Coordinator']),
            // Not enrolled yet: they join by the meeting link.
            'tess' => $dg->create_user(['firstname' => 'Tess']),
            'lee' => $dg->create_user(['firstname' => 'Lee']),
            'manager' => $dg->create_user(['firstname' => 'Manager']),
        ];
        role_assign($this->role('manager'), $this->users['manager']->id, \context_system::instance()->id);
        $this->starts = [time() - 2 * DAYSECS, time() - DAYSECS];
        $this->cm = $generator->create_zoom([
            'course' => $this->course->id,
            'recurring' => 1,
            'recurrence_type' => 2,
            'start_time' => $this->starts[0],
            'duration' => HOURSECS,
        ]);
        foreach ($this->starts as $i => $start) {
            $generator->create_occurrence_event($this->cm, $start, HOURSECS);
            $session = $generator->create_session($this->cm, $start, $start + HOURSECS);
            // Amy and Tess join both classes, Lee only the first.
            foreach ($i ? ['amy', 'tess'] : ['amy', 'tess', 'lee'] as $name) {
                $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $this->users[$name]->id]);
            }
        }
        sync::sync_all();
        $this->setAdminUser();
    }

    /**
     * A role id.
     *
     * @param string $shortname
     * @return int
     */
    protected function role(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
    }

    /**
     * The class starting at a time.
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
     * A user's row in a class's list.
     *
     * @param int $start
     * @param string $name
     * @param string $kind
     * @return \stdClass|false
     */
    protected function entry(int $start, string $name, string $kind) {
        global $DB;
        return $DB->get_record('local_zoomattendance_roster', [
            'occurrenceid' => $this->occurrence($start)->id,
            'userid' => $this->users[$name]->id,
            'kind' => $kind,
        ]);
    }

    public function test_teacher_who_joined_before_enrolment_counts_once_enrolled(): void {
        // Tess taught both classes by the link, then is enrolled as a teacher.
        $this->assertFalse($this->entry($this->starts[0], 'tess', roster::KIND_TEACHER));
        $this->getDataGenerator()->enrol_user($this->users['tess']->id, $this->course->id, 'teacher');

        foreach ($this->starts as $start) {
            $entry = $this->entry($start, 'tess', roster::KIND_TEACHER);
            $this->assertSame(roster::SOURCE_JOINED, $entry->source);
            $this->assertSame(0, (int) $entry->removed);
        }
        // Her teacher attendance counts both classes: present, never "–".
        $summary = teacher_summary::build($this->course);
        foreach ($this->starts as $start) {
            $this->assertSame(status::PRESENT, $summary->cells[$this->users['tess']->id][$this->occurrence($start)->id]->status);
        }
        $this->assertEqualsWithDelta(100.0, $summary->overall[$this->users['tess']->id]->percentage(), 0.01);
        // Not a student: not on the students' list.
        $this->assertFalse($this->entry($this->starts[0], 'tess', roster::KIND_STUDENT));
    }

    public function test_student_enrolled_late_counts_only_the_classes_they_joined(): void {
        $this->getDataGenerator()->enrol_user($this->users['lee']->id, $this->course->id, 'student');
        $this->assertSame(roster::SOURCE_JOINED, $this->entry($this->starts[0], 'lee', roster::KIND_STUDENT)->source);
        // The second class, which Lee did not join, does not count against them.
        $this->assertFalse($this->entry($this->starts[1], 'lee', roster::KIND_STUDENT));
        $summary = course_summary::build($this->course);
        $lee = $this->users['lee']->id;
        $this->assertSame(status::PRESENT, $summary->cells[$lee][$this->occurrence($this->starts[0])->id]->status);
        $this->assertArrayNotHasKey($this->occurrence($this->starts[1])->id, $summary->cells[$lee]);
        $this->assertEqualsWithDelta(100.0, $summary->overall[$lee]->percentage(), 0.01);
        // Running the rule again adds nothing more.
        $this->assertSame(0, roster::add_joiners($this->cm));
    }

    public function test_someone_enrolled_at_the_time_but_left_off_the_list_is_not_added(): void {
        global $DB;
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        // Bo was enrolled, but the activity was hidden from students when its class ran: Bo
        // joined by the link anyway, and is left off its list.
        $bo = $dg->create_and_enrol($this->course, 'student', ['firstname' => 'Bo']);
        $DB->set_field('user_enrolments', 'timecreated', $this->starts[0] - DAYSECS, ['userid' => $bo->id]);
        $start = time() - 3 * HOURSECS;
        $hidden = $generator->create_zoom(['course' => $this->course->id, 'start_time' => $start, 'duration' => HOURSECS,
            'visible' => 0]);
        $session = $generator->create_session($hidden, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $bo->id]);
        sync::sync_all();
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $hidden->instance], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $occurrence->rosterfrozen);
        $this->assertSame(0, roster::add_joiners(get_fast_modinfo($this->course)->get_cm($hidden->id)));
        $conditions = ['occurrenceid' => $occurrence->id, 'userid' => $bo->id];
        $this->assertFalse($DB->record_exists('local_zoomattendance_roster', $conditions));
    }

    public function test_changes_by_hand_are_kept_and_never_undone_automatically(): void {
        global $DB;
        $first = $this->occurrence($this->starts[0]);
        $cm = get_fast_modinfo($this->course)->get_cm($this->cm->id);
        $amy = (int) $this->users['amy']->id;
        $this->setUser($this->users['manager']);
        $sink = $this->redirectEvents();

        // Removed: no longer expected at the class.
        roster::remove_by_hand($first, $cm, $amy, roster::KIND_STUDENT, 'Joined by mistake');
        $entry = $this->entry($this->starts[0], 'amy', roster::KIND_STUDENT);
        $this->assertSame(1, (int) $entry->removed);
        $this->assertSame('Joined by mistake', $entry->reason);
        $this->assertSame((int) $this->users['manager']->id, (int) $entry->usermodified);
        $summary = course_summary::build($this->course);
        $this->assertArrayNotHasKey($first->id, $summary->cells[$amy]);
        $this->assertSame(
            'Removed by Manager ' . $this->users['manager']->lastname . ', '
                . userdate((int) $entry->timemodified, get_string('strftimedatetimeshort', 'langconfig')) . ': Joined by mistake',
            \local_zoomattendance\output\renderer::list_note($entry, $this->users['manager'])
        );
        // The automatic rule never adds her back.
        $this->assertSame(0, roster::add_joiners($cm));

        // Added back by hand.
        roster::add_by_hand($first, $cm, $amy, roster::KIND_STUDENT, 'Removed by mistake');
        $entry = $this->entry($this->starts[0], 'amy', roster::KIND_STUDENT);
        $this->assertSame([0, roster::SOURCE_ADDED], [(int) $entry->removed, $entry->source]);
        $this->assertArrayHasKey($first->id, course_summary::build($this->course)->cells[$amy]);

        // Someone not enrolled, added by hand as a student.
        roster::add_by_hand($first, $cm, (int) $this->users['lee']->id, roster::KIND_STUDENT, 'Guest student');
        $this->assertSame(roster::SOURCE_ADDED, $this->entry($this->starts[0], 'lee', roster::KIND_STUDENT)->source);

        $events = array_map(function ($event) {
            return get_class($event);
        }, $sink->get_events());
        $this->assertSame([
            \local_zoomattendance\event\classlist_removed::class,
            \local_zoomattendance\event\classlist_added::class,
            \local_zoomattendance\event\classlist_added::class,
        ], array_values(array_filter($events, function ($class) {
            return strpos($class, 'classlist_') !== false;
        })));
        $sink->close();

        // A reason is required, and only ended classes' lists change.
        try {
            roster::add_by_hand($first, $cm, $amy, roster::KIND_STUDENT, ' ');
            $this->fail('A reason is required');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $DB->set_field('local_zoomattendance_occ', 'rosterfrozen', 0, ['id' => $first->id]);
        $this->expectException(\coding_exception::class);
        $live = $DB->get_record('local_zoomattendance_occ', ['id' => $first->id]);
        roster::add_by_hand($live, $cm, $amy, roster::KIND_STUDENT, 'x');
    }

    public function test_who_may_change_class_lists(): void {
        $context = \context_module::instance($this->cm->id);
        // Coordinators change students' lists; teachers' lists, which decide their own
        // attendance, need excludetracked while teacher attendance is tracked: managers.
        $this->setUser($this->users['coordinator']);
        $this->assertTrue(roster::can_edit($context, roster::KIND_STUDENT));
        $this->assertFalse(roster::can_edit($context, roster::KIND_TEACHER));
        $this->setUser($this->users['manager']);
        $this->assertTrue(roster::can_edit($context, roster::KIND_TEACHER));
        $this->setUser($this->users['amy']);
        $this->assertFalse(roster::can_edit($context, roster::KIND_STUDENT));
    }

    public function test_hidden_activity_still_counts_on_the_students_own_page(): void {
        // Hidden after its classes, to tidy the course page: still on Amy's own page and overall.
        set_coursemodule_visible($this->cm->id, 0);
        $this->setUser($this->users['amy']);
        $summary = course_summary::build($this->course, 0, (int) $this->users['amy']->id, 'local/zoomattendance:viewown');
        $this->assertArrayHasKey($this->cm->id, $summary->activities);
        $this->assertCount(2, $summary->activities[$this->cm->id]->columns);
        $this->assertEqualsWithDelta(100.0, $summary->overall[$this->users['amy']->id]->percentage(), 0.01);
    }
}
