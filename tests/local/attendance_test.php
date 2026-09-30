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
 * Tests for read-side attendance evaluation.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(attendance::class)]
/**
 * Tests for expected users and per-occurrence evaluation.
 *
 * @covers \local_zoomattendance\local\attendance
 */
final class attendance_test extends \advanced_testcase {
    /** @var \local_zoomattendance_generator */
    protected $generator;
    /** @var \stdClass */
    protected $course;
    /** @var int */
    protected $t0;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_zoomattendance');
        $this->course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
        $this->t0 = (intdiv(time(), DAYSECS) - 7) * DAYSECS + 10 * HOURSECS;
    }

    /**
     * Minutes after t0.
     *
     * @param int $minutes
     * @return int
     */
    protected function mins(int $minutes): int {
        return $this->t0 + $minutes * MINSECS;
    }

    /**
     * Evaluate the first occurrence of an activity.
     *
     * @param \cm_info $cm
     * @param int $groupid
     * @return \stdClass
     */
    protected function evaluate_first(\cm_info $cm, int $groupid = 0): \stdClass {
        $attendance = new attendance($cm);
        $occurrences = $attendance->get_occurrences();
        $occurrence = reset($occurrences);
        $results = $attendance->get_results([$occurrence->id])[$occurrence->id] ?? [];
        return $attendance->evaluate($occurrence, $attendance->get_candidates($groupid), $results, $groupid);
    }

    public function test_expected_users_and_statuses(): void {
        $dg = $this->getDataGenerator();
        $present = $dg->create_and_enrol($this->course, 'student');
        $late = $dg->create_and_enrol($this->course, 'student');
        $absent = $dg->create_and_enrol($this->course, 'student');
        $teacher = $dg->create_and_enrol($this->course, 'editingteacher');
        $suspended = $dg->create_and_enrol($this->course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $enrolledlater = $dg->create_and_enrol($this->course, 'student', null, 'manual', $this->mins(2 * 24 * 60));
        $outsider = $dg->create_user();

        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(50), ['userid' => $present->id]);
        $this->generator->create_participant($session, $this->mins(20), $this->mins(60), ['userid' => $late->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $teacher->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(30), ['userid' => $outsider->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(10), ['name' => 'Phone caller']);
        sync::sync_all();

        $evaluation = $this->evaluate_first($cm);
        $this->assertSame(attendance::STATE_EVALUATED, $evaluation->state);
        $this->assertEqualsCanonicalizing([$present->id, $late->id, $absent->id], array_keys($evaluation->expected));
        $this->assertSame(status::PRESENT, $evaluation->expected[$present->id]->status);
        $this->assertSame(status::PARTIAL, $evaluation->expected[$late->id]->status);
        $this->assertSame(status::ABSENT, $evaluation->expected[$absent->id]->status);
        $this->assertSame(0.0, $evaluation->expected[$absent->id]->percentage);
        $this->assertSame([status::PRESENT => 1, status::PARTIAL => 1, status::ABSENT => 1], $evaluation->counts);

        // Teachers are not tracked by default; outsiders are listed without status.
        $this->assertEqualsCanonicalizing([$teacher->id, $outsider->id], array_keys($evaluation->notexpected));
        $this->assertNull($evaluation->notexpected[$teacher->id]->status);
        $this->assertCount(1, $evaluation->unmatched);
        $this->assertArrayNotHasKey($suspended->id, $evaluation->expected);
        $this->assertArrayNotHasKey($enrolledlater->id, $evaluation->expected);
    }

    public function test_thresholds_apply_without_recompute(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(30));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(30), ['userid' => $student->id]);
        sync::sync_all();

        $this->assertSame(status::PARTIAL, $this->evaluate_first($cm)->expected[$student->id]->status);

        // Measured against the time the meeting actually ran (30 minutes), 100%.
        $DB->insert_record('local_zoomattendance_setting', (object) ['cmid' => $cm->id, 'denominator' => 'actual',
            'timemodified' => time()]);
        $this->assertSame(status::PRESENT, $this->evaluate_first($cm)->expected[$student->id]->status);
    }

    public function test_occurrence_states(): void {
        global $DB;
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $past = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $future = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => time() + DAYSECS,
            'duration' => HOURSECS]);
        sync::sync_all();

        $evaluation = $this->evaluate_first($past);
        $this->assertSame(attendance::STATE_NODATA, $evaluation->state);
        $this->assertSame([status::PRESENT => 0, status::PARTIAL => 0, status::ABSENT => 0], $evaluation->counts);
        $this->assertNull(reset($evaluation->expected)->status);
        $this->assertSame(attendance::STATE_UPCOMING, $this->evaluate_first($future)->state);

        $DB->set_field('local_zoomattendance_occ', 'status', sync::STATUS_EXCLUDED, ['zoomid' => $past->instance]);
        $this->assertSame(attendance::STATE_EXCLUDED, $this->evaluate_first($past)->state);
    }

    public function test_group_filter(): void {
        $dg = $this->getDataGenerator();
        $group = $dg->create_group(['courseid' => $this->course->id]);
        $inside = $dg->create_and_enrol($this->course, 'student');
        $outside = $dg->create_and_enrol($this->course, 'student');
        $dg->create_group_member(['groupid' => $group->id, 'userid' => $inside->id]);
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS, 'groupmode' => SEPARATEGROUPS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(10), ['name' => 'Unknown']);
        sync::sync_all();

        $evaluation = $this->evaluate_first($cm, (int) $group->id);
        $this->assertEquals([$inside->id], array_keys($evaluation->expected));
        $this->assertSame([], $evaluation->unmatched);
        $this->assertCount(2, $this->evaluate_first($cm)->expected);
        $this->assertArrayNotHasKey($outside->id, $evaluation->expected);
    }

    public function test_availability_restriction(): void {
        global $DB;
        $dg = $this->getDataGenerator();
        $group = $dg->create_group(['courseid' => $this->course->id]);
        $member = $dg->create_and_enrol($this->course, 'student');
        $dg->create_and_enrol($this->course, 'student');
        $dg->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        set_config('enableavailability', 1);
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_group\condition::get_json($group->id)]
        ));
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cm->id]);
        rebuild_course_cache($this->course->id, true);
        $cm = get_fast_modinfo($this->course->id)->get_cm($cm->id);
        sync::sync_all();

        $this->assertEquals([$member->id], array_keys($this->evaluate_first($cm)->expected));
    }
}
