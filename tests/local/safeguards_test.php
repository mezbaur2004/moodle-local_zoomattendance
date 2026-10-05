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
 * Tests for the safeguards against wrong teacher and student figures.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

use local_zoomattendance\local\source\zoom_source;

#[\PHPUnit\Framework\Attributes\CoversClass(sync::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(teacher_attendance::class)]
/**
 * Tests for the safeguards against wrong teacher and student figures: missing participant
 * reports, activities sharing one Zoom meeting, restores running during a sync, and who may
 * change teacher figures.
 *
 * @covers \local_zoomattendance\local\sync
 * @covers \local_zoomattendance\local\teacher_attendance
 * @covers \local_zoomattendance\local\attendance
 */
final class safeguards_test extends \advanced_testcase {
    /** @var \local_zoomattendance_generator */
    protected $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        set_config('last_call_made_at', time(), 'zoom');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_zoomattendance');
        $this->setAdminUser();
    }

    /**
     * A course with a student and a teacher.
     *
     * @return array [course, student, teacher]
     */
    protected function course(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        return [$course, $dg->create_and_enrol($course, 'student'), $dg->create_and_enrol($course, 'editingteacher')];
    }

    /**
     * The only occurrence of an activity at a start time.
     *
     * @param \cm_info $cm
     * @param int $start
     * @return \stdClass|false
     */
    protected function occurrence(\cm_info $cm, int $start) {
        global $DB;
        return $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance, 'timestart' => $start]);
    }

    /**
     * Evaluate the teachers of an occurrence.
     *
     * @param \cm_info $cm
     * @param \stdClass $occurrence
     * @return \stdClass
     */
    protected function teachers(\cm_info $cm, \stdClass $occurrence): \stdClass {
        $teachers = new teacher_attendance(new attendance($cm));
        $results = $teachers->attendance->get_results([$occurrence->id])[$occurrence->id] ?? [];
        return $teachers->evaluate($occurrence, $teachers->get_candidates(), $results);
    }

    public function test_session_without_participant_report_is_not_counted(): void {
        global $DB;
        [$course, $student, $teacher] = $this->course();
        $start = time() - 3 * DAYSECS;
        $cm = $this->generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        // Zoom recorded the meeting, but the Zoom plugin got no participants for it.
        $this->generator->create_session($cm, $start, $start + HOURSECS);
        sync::sync_all();
        $DB->set_field('local_zoomattendance_occ', 'timecreated', 1);
        $occurrence = $this->occurrence($cm, $start);

        $attendance = new attendance($cm);
        $this->assertSame(attendance::STATE_NOREPORT, $attendance->state($occurrence));
        $this->assertSame([], course_summary::build($course)->activities);
        $evaluation = $this->teachers($cm, $occurrence);
        $this->assertSame(attendance::STATE_NOREPORT, $evaluation->state);
        $this->assertNull($evaluation->rows[$teacher->id]->status);
        $summary = teacher_summary::build($course);
        $this->assertSame(0, $summary->stats[$teacher->id]['expected']);
        $this->assertTrue(teacher_summary::shown(attendance::STATE_NOREPORT));
    }

    public function test_course_copy_sharing_a_meeting_counts_each_session_where_it_belongs(): void {
        global $DB;
        // Last term's course and this term's copy use the same Zoom meeting. The Zoom plugin
        // files every session under the older activity.
        [$old, $oldstudent] = $this->course();
        [$new, $newstudent, $newteacher] = $this->course();
        $lastterm = time() - 60 * DAYSECS;
        $thisterm = time() - 2 * DAYSECS;
        $oldcm = $this->generator->create_zoom(['course' => $old->id, 'start_time' => $lastterm,
            'duration' => HOURSECS, 'meeting_id' => 777]);
        $newcm = $this->generator->create_zoom(['course' => $new->id, 'start_time' => $thisterm,
            'duration' => HOURSECS, 'meeting_id' => 777]);
        $before = $this->generator->create_session($oldcm, $lastterm, $lastterm + HOURSECS);
        $this->generator->create_participant($before, $lastterm, $lastterm + HOURSECS, ['userid' => $oldstudent->id]);
        $misfiled = $this->generator->create_session($oldcm, $thisterm, $thisterm + HOURSECS);
        $this->generator->create_participant($misfiled, $thisterm, $thisterm + HOURSECS, ['userid' => $newstudent->id]);
        $this->generator->create_participant($misfiled, $thisterm, $thisterm + HOURSECS, ['userid' => $newteacher->id]);
        sync::sync_all();

        // The new course counts the session of its class; the old course keeps only its own,
        // with no extra class made up from the new term's session.
        $this->assertEquals($newcm->instance, $DB->get_field(
            'local_zoomattendance_session',
            'zoomid',
            ['detailsid' => $misfiled->id]
        ));
        $this->assertEquals($oldcm->instance, $DB->get_field(
            'local_zoomattendance_session',
            'zoomid',
            ['detailsid' => $before->id]
        ));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $oldcm->instance]));
        $summary = course_summary::build($new);
        $occurrence = $this->occurrence($newcm, $thisterm);
        $this->assertSame(status::PRESENT, $summary->cells[$newstudent->id][$occurrence->id]->status);
        $this->assertSame(status::PRESENT, $this->teachers($newcm, $occurrence)->rows[$newteacher->id]->status);
        $this->assertArrayNotHasKey($newstudent->id, course_summary::build($old)->users);

        // The other order gives the same result: the new course first takes the session over,
        // then the old course drops it.
        sync::delete_for_zoomids([(int) $oldcm->instance, (int) $newcm->instance]);
        $instances = zoom_source::get_instances(null, null);
        sync::sync_instance($instances[$newcm->instance]);
        sync::sync_instance($instances[$oldcm->instance]);
        $this->assertEquals($newcm->instance, $DB->get_field(
            'local_zoomattendance_session',
            'zoomid',
            ['detailsid' => $misfiled->id]
        ));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $oldcm->instance]));
        $this->assertSame(0, $DB->count_records_select(
            'local_zoomattendance_result',
            'userid = :userid AND occurrenceid IN (SELECT id FROM {local_zoomattendance_occ} WHERE zoomid = :zoomid)',
            ['userid' => $newstudent->id, 'zoomid' => $oldcm->instance]
        ));

        // A new session filed under the old activity makes the incremental sync visit both.
        $this->generator->create_session($oldcm, time() - HOURSECS, time() - MINSECS);
        $this->assertSame(2, sync::sync_all());
    }

    public function test_shared_meeting_at_the_same_time_is_held_elsewhere_not_not_held(): void {
        global $DB;
        // Two cross-listed courses share one meeting at the same time: the session matches both
        // schedules, so it stays where the Zoom plugin filed it.
        [$first, $student] = $this->course();
        [$second, , $teacher] = $this->course();
        $start = time() - 3 * DAYSECS;
        $one = $this->generator->create_zoom(['course' => $first->id, 'start_time' => $start,
            'duration' => HOURSECS, 'meeting_id' => 888]);
        $two = $this->generator->create_zoom(['course' => $second->id, 'start_time' => $start,
            'duration' => HOURSECS, 'meeting_id' => 888]);
        $session = $this->generator->create_session($one, $start, $start + HOURSECS);
        $this->generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $student->id]);
        // The host's reports are fetched past the class.
        $this->generator->create_session($one, time() - HOURSECS, time() - MINSECS);
        sync::sync_all();
        $DB->set_field('local_zoomattendance_occ', 'timecreated', 1);

        $occurrence = $this->occurrence($two, $start);
        $evaluation = $this->teachers($two, $occurrence);
        $this->assertSame(teacher_attendance::STATE_ELSEWHERE, $evaluation->state);
        $this->assertNull($evaluation->rows[$teacher->id]->status);
        $this->assertSame(0, teacher_summary::build($second)->stats[$teacher->id]['notheld']);
    }

    public function test_classes_a_restore_is_writing_survive_a_sync(): void {
        global $DB;
        $now = time();
        $occurrence = (object) ['zoomid' => 0, 'occurrencekey' => 'tmp:' . sha1('x'), 'source' => sync::SOURCE_SCHEDULE,
            'timestart' => $now - DAYSECS, 'timeend' => $now - DAYSECS + HOURSECS, 'status' => sync::STATUS_ACTIVE,
            'actualsecs' => HOURSECS, 'timecomputed' => $now, 'timecreated' => $now, 'timemodified' => $now,
            'restored' => $now];
        $occurrence->id = $DB->insert_record('local_zoomattendance_occ', $occurrence);
        $DB->insert_record('local_zoomattendance_result', (object) ['occurrenceid' => $occurrence->id, 'userid' => 2,
            'identitykey' => 'u:2', 'attendedsecs' => HOURSECS, 'segments' => 1, 'matchstrength' => 2, 'timemodified' => $now]);

        // A sync while the restore runs leaves the class and its results alone.
        sync::sync_all(null, true);
        $this->assertTrue($DB->record_exists('local_zoomattendance_occ', ['id' => $occurrence->id]));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]));

        // Left over from a restore that failed days ago: cleaned up.
        $DB->set_field('local_zoomattendance_occ', 'restored', $now - 3 * DAYSECS, ['id' => $occurrence->id]);
        sync::sync_all(null, true);
        $this->assertFalse($DB->record_exists('local_zoomattendance_occ', ['id' => $occurrence->id]));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_result', ['occurrenceid' => $occurrence->id]));

        // Results whose class is gone are removed by the full pass.
        $DB->insert_record('local_zoomattendance_result', (object) ['occurrenceid' => 987654, 'userid' => 2,
            'identitykey' => 'u:2', 'attendedsecs' => 1, 'segments' => 1, 'matchstrength' => 2, 'timemodified' => $now]);
        sync::sync_all();
        $this->assertTrue($DB->record_exists('local_zoomattendance_result', ['occurrenceid' => 987654]));
        sync::sync_all(null, true);
        $this->assertFalse($DB->record_exists('local_zoomattendance_result', ['occurrenceid' => 987654]));
    }

    public function test_class_windows_need_the_exclusion_permission_while_teachers_are_tracked(): void {
        global $PAGE;
        [$course, , $teacher] = $this->course();
        // A recurring meeting without a fixed time: its classes are inferred from the sessions.
        $start = time() - 3 * DAYSECS;
        $cm = $this->generator->create_zoom(['course' => $course->id, 'recurring' => 1, 'recurrence_type' => 0]);
        $session = $this->generator->create_session($cm, $start, $start + HOURSECS);
        $this->generator->create_participant($session, $start + 20 * MINSECS, $start + HOURSECS, ['userid' => $teacher->id]);
        sync::sync_all();

        $this->setUser($teacher);
        $context = \context_module::instance($cm->id);
        $this->assertTrue(has_capability('local/zoomattendance:manage', $context));
        $this->assertFalse(manual::can_exclude($context));
        $attendance = new attendance($cm);
        $evaluations = [];
        foreach ($attendance->get_occurrences() as $occurrence) {
            $evaluations[$occurrence->id] = $attendance->evaluate($occurrence, $attendance->get_candidates(), []);
        }
        $renderer = $PAGE->get_renderer('local_zoomattendance');
        $url = new \moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
        // The teacher could move the window to when they joined, and never be late.
        $this->assertStringNotContainsString('window.php', $renderer->occurrence_list(
            $attendance,
            $evaluations,
            $url,
            true,
            false,
            manual::can_exclude($context)
        ));
        $this->setAdminUser();
        $this->assertStringContainsString('window.php', $renderer->occurrence_list(
            $attendance,
            $evaluations,
            $url,
            true,
            false,
            manual::can_exclude($context)
        ));
    }
}
