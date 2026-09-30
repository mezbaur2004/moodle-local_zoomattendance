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

        $this->assertTrue(manual::link_identity($this->course->id, $key, $student->id, 'iPhone'));
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
        $link = $DB->get_record('local_zoomattendance_idmap', ['courseid' => $this->course->id, 'identitykey' => $key]);

        $this->assertTrue(manual::unlink_identity($this->course->id, $link->id));
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
}
