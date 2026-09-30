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
 * Tests for teacher attendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(teacher_attendance::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teacher_summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settings::class)]
/**
 * Tests for teacher attendance.
 *
 * @covers \local_zoomattendance\local\teacher_attendance
 * @covers \local_zoomattendance\local\teacher_summary
 * @covers \local_zoomattendance\local\settings
 */
final class teacher_attendance_test extends \advanced_testcase {
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
        set_config('teachertracking', 1, 'local_zoomattendance');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_zoomattendance');
        $this->course = $this->getDataGenerator()->create_course();
        $this->t0 = (intdiv(time(), DAYSECS) - 7) * DAYSECS + 10 * HOURSECS;
        set_config('teachertrackingsince', $this->t0 - DAYSECS, 'local_zoomattendance');
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
     * A one hour Zoom activity starting at t0 plus the given minutes.
     *
     * @param int $offset Minutes after t0.
     * @return \cm_info
     */
    protected function create_class(int $offset = 0): \cm_info {
        return $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins($offset),
            'duration' => HOURSECS]);
    }

    /**
     * Evaluate the teachers of an activity's first occurrence.
     *
     * @param \cm_info $cm
     * @return \stdClass
     */
    protected function evaluate_first(\cm_info $cm): \stdClass {
        $teachers = new teacher_attendance(new attendance($cm));
        $occurrences = $teachers->attendance->get_occurrences();
        $occurrence = reset($occurrences);
        $results = $teachers->attendance->get_results([$occurrence->id])[$occurrence->id] ?? [];
        return $teachers->evaluate($occurrence, $teachers->get_candidates(), $results);
    }

    public function test_defaults_and_bounds(): void {
        $settings = settings::teacher();
        $this->assertTrue($settings->enabled);
        $this->assertSame(90, $settings->presentpct);
        $this->assertSame(50, $settings->latepct);
        $this->assertSame(5, $settings->lategracemins);
        $this->assertSame(settings::DENOMINATOR_SCHEDULED, $settings->denominator);
        $this->assertSame(DAYSECS, settings::teacher_notheld_delay());

        set_config('teacherpresentpct', 60, 'local_zoomattendance');
        set_config('teacherpartialpct', 80, 'local_zoomattendance');
        $this->assertSame(60, settings::teacher()->latepct);

        unset_config('teachertrackingsince', 'local_zoomattendance');
        $this->assertGreaterThanOrEqual(time() - 5, settings::teacher_tracking_since());
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        settings::teacher_tracking_updated();
        $this->assertGreaterThan(1, (int) get_config('local_zoomattendance', 'teachertrackingsince'));
    }

    public function test_teacher_statuses_and_figures(): void {
        $dg = $this->getDataGenerator();
        $ontime = $dg->create_and_enrol($this->course, 'editingteacher');
        $late = $dg->create_and_enrol($this->course, 'teacher');
        $partial = $dg->create_and_enrol($this->course, 'editingteacher');
        $absent = $dg->create_and_enrol($this->course, 'editingteacher');
        $later = $dg->create_and_enrol($this->course, 'editingteacher', null, 'manual', $this->mins(2 * 24 * 60));
        $student = $dg->create_and_enrol($this->course, 'student');

        $cm = $this->create_class();
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        // Joined before the start, left 5 minutes early: 55 of 60 minutes.
        $this->generator->create_participant($session, $this->mins(-3), $this->mins(55), ['userid' => $ontime->id]);
        // Joined 6 minutes late, stayed to the end: 90 %, but after the 5 minute grace.
        $this->generator->create_participant($session, $this->mins(6), $this->mins(60), ['userid' => $late->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(40), ['userid' => $partial->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $student->id]);
        sync::sync_all();

        $evaluation = $this->evaluate_first($cm);
        $this->assertSame(attendance::STATE_EVALUATED, $evaluation->state);
        $this->assertEqualsCanonicalizing([$ontime->id, $late->id, $partial->id, $absent->id], array_keys($evaluation->rows));
        $this->assertArrayNotHasKey($later->id, $evaluation->rows);

        $row = $evaluation->rows[$ontime->id];
        $this->assertSame(status::PRESENT, $row->status);
        $this->assertEqualsWithDelta(55 * 100 / 60, $row->percentage, 0.01);
        $this->assertSame(0, $row->latesecs);
        $this->assertSame(5 * MINSECS, $row->earlysecs);
        $this->assertFalse($row->latestart);
        $this->assertFalse($row->earlyleave);

        $row = $evaluation->rows[$late->id];
        $this->assertSame(status::PARTIAL, $row->status);
        $this->assertSame(6 * MINSECS, $row->latesecs);
        $this->assertTrue($row->latestart);

        $this->assertSame(status::PARTIAL, $evaluation->rows[$partial->id]->status);
        $this->assertTrue($evaluation->rows[$partial->id]->earlyleave);
        $this->assertSame(status::ABSENT, $evaluation->rows[$absent->id]->status);
        $this->assertSame(0.0, $evaluation->rows[$absent->id]->percentage);

        // Changing the site thresholds applies without a recompute.
        set_config('teachergracemins', 10, 'local_zoomattendance');
        $this->assertSame(status::PRESENT, $this->evaluate_first($cm)->rows[$late->id]->status);
    }

    public function test_not_held_needs_the_watermark_and_delay(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $cm = $this->create_class();
        sync::sync_all();
        $end = $this->mins(60);

        // The Zoom plugin has not fetched reports far enough past the class yet.
        set_config('last_call_made_at', $end + 23 * HOURSECS, 'zoom');
        $evaluation = $this->evaluate_first($cm);
        $this->assertSame(teacher_attendance::STATE_AWAITING, $evaluation->state);
        $this->assertNull($evaluation->rows[$teacher->id]->status);

        set_config('last_call_made_at', $end + 25 * HOURSECS, 'zoom');
        $evaluation = $this->evaluate_first($cm);
        $this->assertSame(teacher_attendance::STATE_NOTHELD, $evaluation->state);
        $this->assertSame(status::ABSENT, $evaluation->rows[$teacher->id]->status);
        $this->assertSame(0.0, $evaluation->rows[$teacher->id]->percentage);

        // A shorter configured delay applies too.
        set_config('last_call_made_at', $end + 2 * HOURSECS, 'zoom');
        set_config('teachernotheldhours', 1, 'local_zoomattendance');
        $this->assertSame(teacher_attendance::STATE_NOTHELD, $this->evaluate_first($cm)->state);

        // Classes from before teacher tracking was switched on are never marked not held.
        set_config('teachertrackingsince', $end + 1, 'local_zoomattendance');
        $this->assertSame(teacher_attendance::STATE_AWAITING, $this->evaluate_first($cm)->state);

        // Students still see no session data, and nothing is counted for them.
        $attendance = new attendance($cm);
        $occurrences = $attendance->get_occurrences();
        $this->assertSame(attendance::STATE_NODATA, $attendance->state(reset($occurrences)));
    }

    public function test_disabled_activities_are_synced_while_teachers_are_tracked(): void {
        global $DB;
        set_config('defaultenabled', 0, 'local_zoomattendance');
        set_config('teachertracking', 0, 'local_zoomattendance');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $cm = $this->create_class();
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $teacher->id]);

        sync::sync_all();
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ'));

        set_config('teachertracking', 1, 'local_zoomattendance');
        sync::sync_all();
        $this->assertSame(1, $DB->count_records('local_zoomattendance_result', ['userid' => $teacher->id]));
        $this->assertSame(status::PRESENT, $this->evaluate_first($cm)->rows[$teacher->id]->status);
    }

    public function test_course_summary_counts(): void {
        global $DB;
        $dg = $this->getDataGenerator();
        $teacher = $dg->create_and_enrol($this->course, 'editingteacher');
        $other = $dg->create_and_enrol($this->course, 'teacher');

        // Held: teacher present, other absent.
        $held = $this->create_class();
        $session = $this->generator->create_session($held, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(8), $this->mins(50), ['userid' => $teacher->id]);
        // Not held.
        $this->create_class(24 * 60);
        // Excluded by the teacher.
        $excluded = $this->create_class(2 * 24 * 60);
        sync::sync_all();
        set_config('last_call_made_at', time(), 'zoom');
        $this->setUser($teacher);
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $excluded->instance]);
        manual::set_excluded($occurrence, true);

        $summary = teacher_summary::build($this->course);
        $this->assertCount(3, $summary->activities);
        $this->assertEqualsCanonicalizing([$teacher->id, $other->id], array_keys($summary->users));

        $stats = $summary->stats[$teacher->id];
        $this->assertSame(2, $stats['expected']);
        $this->assertSame(1, $stats[status::PARTIAL]);
        $this->assertSame(1, $stats[status::ABSENT]);
        $this->assertSame(1, $stats['notheld']);
        $this->assertSame(1, $stats['latestarts']);
        $this->assertSame(1, $stats['earlyleaves']);
        $this->assertSame(1, $stats['excluded']);
        $this->assertSame(1, $stats['excludedbyself']);
        // 42 of 120 minutes.
        $this->assertEqualsWithDelta(35.0, $summary->overall[$teacher->id]->percentage(), 0.01);
        $this->assertSame(0, $summary->stats[$other->id]['excludedbyself']);
        $this->assertSame((int) $teacher->id, (int) $summary->excludedby[$occurrence->id]->id);

        // A teacher's own view holds only their row; the date range limits the columns.
        $mine = teacher_summary::build($this->course, (int) $teacher->id);
        $this->assertEquals([$teacher->id], array_keys($mine->users));
        $range = teacher_summary::build($this->course, null, $this->mins(12 * 60), $this->mins(36 * 60));
        $this->assertCount(1, $range->activities);
    }

    public function test_self_links_are_flagged(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $cm = $this->create_class();
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['name' => 'Tablet',
            'user_email' => 'tablet@example.org']);
        sync::sync_all();

        $this->setUser($teacher);
        manual::link_identity((int) $this->course->id, 'z:' . sha1('e:tablet@example.org'), (int) $teacher->id, 'Tablet');
        $summary = teacher_summary::build($this->course);
        $this->assertArrayHasKey($teacher->id, $summary->selflinkers);
        $this->assertSame(1, $summary->stats[$teacher->id]['selflinked']);
        $this->assertSame(status::PRESENT, reset($summary->cells[$teacher->id])->status);
    }

    public function test_other_teachers_are_hidden(): void {
        $dg = $this->getDataGenerator();
        $teacher = $dg->create_and_enrol($this->course, 'editingteacher');
        $guest = $dg->create_user();
        $cm = $this->create_class();
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $teacher->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $guest->id]);
        sync::sync_all();

        $attendance = new attendance($cm);
        $occurrences = $attendance->get_occurrences();
        $occurrence = reset($occurrences);
        $evaluation = $attendance->evaluate(
            $occurrence,
            $attendance->get_candidates(),
            $attendance->get_results([$occurrence->id])[$occurrence->id]
        );
        $this->assertEqualsCanonicalizing([$teacher->id, $guest->id], array_keys($evaluation->notexpected));
        $this->assertEquals([$guest->id], array_keys($attendance->without_teachers($evaluation->notexpected)));
    }
}
