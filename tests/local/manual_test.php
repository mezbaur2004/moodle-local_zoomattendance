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
 * Tests for teacher corrections.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(manual::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sync::class)]
/**
 * Tests for identity links and teacher-set occurrence windows.
 *
 * @covers \local_zoomattendance\local\manual
 * @covers \local_zoomattendance\local\sync
 */
final class manual_test extends \advanced_testcase {
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
        $this->course = $this->getDataGenerator()->create_course();
        $this->t0 = (intdiv(time(), DAYSECS) - 7) * DAYSECS + 10 * HOURSECS;
    }

    /**
     * Run the queued background tasks, as cron would.
     */
    protected function run_adhoc_tasks(): void {
        while ($task = \core\task\manager::get_next_adhoc_task(time())) {
            $task->execute();
            \core\task\manager::adhoc_task_complete($task);
        }
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
     * Results of an instance keyed by identity key.
     *
     * @param int $zoomid
     * @return \stdClass[]
     */
    protected function results(int $zoomid): array {
        global $DB;
        $bykey = [];
        $rows = $DB->get_records_sql("SELECT r.*
                                        FROM {local_zoomattendance_result} r
                                        JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
                                       WHERE o.zoomid = ?", [$zoomid]);
        foreach ($rows as $row) {
            $bykey[$row->identitykey] = $row;
        }
        return $bykey;
    }

    /**
     * A one-hour scheduled meeting with a student who joined twice: once matched, once from a
     * phone mod_zoom could not match.
     *
     * @return array [cm, student, phone identity key]
     */
    protected function meeting_with_phone(): array {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(20), ['userid' => $student->id]);
        $phone = $this->generator->create_participant(
            $session,
            $this->mins(15),
            $this->mins(40),
            ['name' => 'iPhone', 'uuid' => 'phone-uuid']
        );
        sync::sync_all();
        return [$cm, $student, sync::unmatched_key($phone)];
    }

    public function test_link_identity_merges_time_into_the_user(): void {
        [$cm, $student, $key] = $this->meeting_with_phone();
        $this->assertEquals(20 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);
        $this->assertArrayHasKey($key, $this->results($cm->instance));

        // The recompute runs in the background.
        $this->assertFalse(manual::link_identity($this->course->id, $key, $student->id, 'iPhone'));
        $this->assertEquals(20 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);
        $this->run_adhoc_tasks();
        $results = $this->results($cm->instance);
        $this->assertArrayNotHasKey($key, $results);
        // Union of 0-20 and 15-40, not the sum.
        $this->assertEquals(40 * MINSECS, $results['u:' . $student->id]->attendedsecs);
        $this->assertEquals(sync::MATCH_MANUAL, $results['u:' . $student->id]->matchstrength);

        // Another sync keeps the link applied.
        sync::sync_all();
        $this->assertEquals(40 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);
    }

    public function test_unlink_identity_restores_the_unmatched_row(): void {
        global $DB;
        [$cm, $student, $key] = $this->meeting_with_phone();
        manual::link_identity($this->course->id, $key, $student->id, 'iPhone');
        $this->run_adhoc_tasks();
        $link = $DB->get_record('local_zoomattendance_idmap', ['courseid' => $this->course->id, 'identitykey' => $key]);

        $this->assertFalse(manual::unlink_identity($this->course->id, $link->id));
        $this->run_adhoc_tasks();
        $results = $this->results($cm->instance);
        $this->assertArrayHasKey($key, $results);
        $this->assertEquals(20 * MINSECS, $results['u:' . $student->id]->attendedsecs);
        $this->assertNotEquals(sync::MATCH_MANUAL, $results['u:' . $student->id]->matchstrength);
    }

    public function test_links_are_per_course(): void {
        [$cm, , $key] = $this->meeting_with_phone();
        $othercourse = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_and_enrol($othercourse, 'student');
        manual::link_identity($othercourse->id, $key, $other->id, 'iPhone');
        $this->run_adhoc_tasks();
        $this->assertArrayHasKey($key, $this->results($cm->instance));
    }

    public function test_link_rejects_users_not_enrolled(): void {
        [, , $key] = $this->meeting_with_phone();
        $outsider = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        manual::link_identity($this->course->id, $key, $outsider->id, 'iPhone');
    }

    public function test_link_rejects_matched_identity_keys(): void {
        [, $student] = $this->meeting_with_phone();
        $this->expectException(\coding_exception::class);
        manual::link_identity($this->course->id, 'u:' . $student->id, $student->id, null);
    }

    public function test_marked_occurrences_are_recomputed_on_the_next_sync(): void {
        global $DB;
        [$cm, $student, $key] = $this->meeting_with_phone();
        // A link saved while another sync held the lock: only the marker is set.
        $DB->insert_record('local_zoomattendance_idmap', (object) ['courseid' => $this->course->id,
            'identitykey' => $key, 'userid' => $student->id, 'timecreated' => time()]);
        sync::sync_all();
        $this->assertArrayHasKey($key, $this->results($cm->instance));

        $DB->set_field('local_zoomattendance_occ', 'timecomputed', 0, ['zoomid' => $cm->instance]);
        sync::sync_all();
        $this->assertArrayNotHasKey($key, $this->results($cm->instance));
        $this->assertEquals(40 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);
    }

    /**
     * A meeting without a fixed time, with one inferred occurrence 10:05-11:05.
     *
     * @return array [cm, student, occurrence]
     */
    protected function unscheduled_meeting(): array {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 0]);
        $session = $this->generator->create_session($cm, $this->mins(5), $this->mins(65));
        $this->generator->create_participant($session, $this->mins(5), $this->mins(65), ['userid' => $student->id]);
        sync::sync_all();
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame(sync::SOURCE_INFERRED, $occurrence->source);
        return [$cm, $student, $occurrence];
    }

    public function test_set_window_on_inferred_occurrence(): void {
        global $DB;
        [$cm, $student, $occurrence] = $this->unscheduled_meeting();
        $this->assertEquals(60 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);

        // The class really ran 10:00-11:00.
        $this->assertTrue(manual::set_window($occurrence, $this->mins(0), $this->mins(60)));
        $updated = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrence->id]);
        $this->assertSame(sync::SOURCE_MANUAL, $updated->source);
        $this->assertStringStartsWith('m:', $updated->occurrencekey);
        $this->assertEquals([$this->mins(0), $this->mins(60)], [$updated->timestart, $updated->timeend]);
        $result = $this->results($cm->instance)['u:' . $student->id];
        $this->assertEquals(55 * MINSECS, $result->attendedsecs);
        $this->assertEquals($this->mins(5), $result->firstjoin);

        // Later syncs keep the teacher's window and do not recreate an inferred occurrence.
        sync::sync_all();
        $all = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $this->assertCount(1, $all);
        $this->assertEquals($this->mins(0), reset($all)->timestart);
    }

    public function test_set_window_refuses_scheduled_occurrences(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        sync::sync_all();
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance], '*', MUST_EXIST);
        $this->assertFalse(manual::can_set_window($occurrence));
        $this->expectException(\coding_exception::class);
        manual::set_window($occurrence, $this->mins(0), $this->mins(30));
    }

    public function test_revert_window_infers_it_again(): void {
        global $DB;
        [$cm, $student, $occurrence] = $this->unscheduled_meeting();
        manual::set_window($occurrence, $this->mins(0), $this->mins(60));
        $manual = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrence->id]);

        $this->assertTrue(manual::revert_window($manual));
        $all = array_values($DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
        $this->assertCount(1, $all);
        $this->assertSame(sync::SOURCE_INFERRED, $all[0]->source);
        $this->assertEquals([$this->mins(5), $this->mins(65)], [$all[0]->timestart, $all[0]->timeend]);
        $this->assertEquals(60 * MINSECS, $this->results($cm->instance)['u:' . $student->id]->attendedsecs);
    }

    public function test_window_moved_away_from_its_sessions(): void {
        global $DB;
        [$cm, , $occurrence] = $this->unscheduled_meeting();
        // A window on another day: the sessions no longer fit it and are inferred again,
        // without clashing with the manual occurrence's key.
        manual::set_window($occurrence, $this->mins(3 * 24 * 60), $this->mins(3 * 24 * 60 + 60));
        $all = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance], 'timestart');
        $this->assertCount(2, $all);
        $sources = array_column($all, 'source');
        $this->assertEqualsCanonicalizing([sync::SOURCE_INFERRED, sync::SOURCE_MANUAL], $sources);
    }

    public function test_changes_record_who_made_them_and_are_logged(): void {
        global $DB;
        [$cm, $student, $phonekey] = $this->meeting_with_phone();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $sink = $this->redirectEvents();

        manual::link_identity((int) $this->course->id, $phonekey, (int) $student->id, 'Phone');
        $link = $DB->get_record('local_zoomattendance_idmap', ['identitykey' => $phonekey]);
        $this->assertEquals($teacher->id, $link->usermodified);

        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        manual::set_excluded($occurrence, true, 'Public holiday');
        $updated = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrence->id]);
        $this->assertEquals(sync::STATUS_EXCLUDED, $updated->status);
        $this->assertEquals($teacher->id, $updated->usermodified);
        manual::set_excluded($updated, false);
        manual::unlink_identity((int) $this->course->id, (int) $link->id);

        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return strpos(get_class($event), 'local_zoomattendance\\event\\') === 0;
        }));
        $this->assertSame([
            \local_zoomattendance\event\identity_linked::class,
            \local_zoomattendance\event\occurrence_excluded::class,
            \local_zoomattendance\event\occurrence_included::class,
            \local_zoomattendance\event\identity_unlinked::class,
        ], array_map('get_class', $events));
        $this->assertEquals($student->id, $events[0]->relateduserid);
        $this->assertEquals(\context_course::instance($this->course->id), $events[0]->get_context());
        $this->assertEquals(\context_module::instance($cm->id), $events[1]->get_context());
        $this->assertEquals($occurrence->id, $events[1]->objectid);
        $this->assertNotEmpty($events[1]->get_description());
        $this->assertInstanceOf(\moodle_url::class, $events[1]->get_url());
    }

    public function test_exclusion_needs_a_reason_and_the_right_role_while_teachers_are_tracked(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        sync::sync_all();
        $context = \context_module::instance($cm->id);
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_and_enrol($this->course, 'manager');

        // Without teacher tracking, managing the activity is enough.
        set_config('teachertracking', 0, 'local_zoomattendance');
        $this->setUser($teacher);
        $this->assertTrue(manual::can_exclude($context));
        // While teachers are tracked, they cannot exclude their own classes; managers can.
        set_config('teachertracking', 1, 'local_zoomattendance');
        $this->assertFalse(manual::can_exclude($context));
        $this->setUser($manager);
        $this->assertTrue(manual::can_exclude($context));

        try {
            manual::set_excluded($occurrence, true, '   ');
            $this->fail('A reason is required.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $sink = $this->redirectEvents();
        manual::set_excluded($occurrence, true, 'Public holiday');
        $this->assertSame('Public holiday', $DB->get_field('local_zoomattendance_occ', 'excludereason', ['id' => $occurrence->id]));
        $event = $sink->get_events()[0];
        $this->assertSame('Public holiday', $event->other['reason']);
        $this->assertStringContainsString('Public holiday', $event->get_description());

        // Including it again clears the reason.
        manual::set_excluded($occurrence, false);
        $this->assertNull($DB->get_field('local_zoomattendance_occ', 'excludereason', ['id' => $occurrence->id]));
    }

    public function test_cancelled_occurrences_cannot_be_excluded(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        sync::sync_all();
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $DB->set_field('local_zoomattendance_occ', 'status', sync::STATUS_CANCELLED, ['id' => $occurrence->id]);
        $occurrence->status = sync::STATUS_CANCELLED;
        manual::set_excluded($occurrence, true, 'Public holiday');
        $status = $DB->get_field('local_zoomattendance_occ', 'status', ['id' => $occurrence->id]);
        $this->assertEquals(sync::STATUS_CANCELLED, $status);
    }

    public function test_window_changes_are_logged(): void {
        global $DB;
        [, , $occurrence] = $this->unscheduled_meeting();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $sink = $this->redirectEvents();

        manual::set_window($occurrence, $this->mins(0), $this->mins(60));
        $manual = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrence->id]);
        $this->assertEquals($teacher->id, $manual->usermodified);
        manual::revert_window($manual);

        $classes = array_map('get_class', array_values(array_filter($sink->get_events(), function ($event) {
            return strpos(get_class($event), 'local_zoomattendance\\event\\') === 0;
        })));
        $this->assertSame([
            \local_zoomattendance\event\window_set::class,
            \local_zoomattendance\event\window_reverted::class,
        ], $classes);
    }
}
