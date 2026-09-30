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
 * Tests for the sync engine.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Tests for the sync engine, using mod_zoom tables filled by the plugin's generator.
 *
 * @covers \local_zoomattendance\local\sync
 * @covers \local_zoomattendance\local\source\zoom_source
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sync::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\zoom_source::class)]
final class sync_test extends \advanced_testcase {
    /** @var \local_zoomattendance_generator */
    protected $generator;
    /** @var \stdClass */
    protected $course;
    /** @var int A fixed past hour to build windows around. */
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
    protected function at(int $minutes): int {
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
        $rows = $DB->get_records_sql("SELECT r.*
                                        FROM {local_zoomatt_result} r
                                        JOIN {local_zoomatt_occurrence} o ON o.id = r.occurrenceid
                                       WHERE o.zoomid = ?", [$zoomid]);
        $bykey = [];
        foreach ($rows as $row) {
            $bykey[$row->identitykey] = $row;
        }
        return $bykey;
    }

    public function test_single_meeting_union_and_unmatched(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['email' => 'a@example.com']);
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->at(-2), $this->at(62));
        // The worked example: 50 minutes after clipping and union.
        $this->generator->create_participant(
            $session,
            $this->at(-5),
            $this->at(20),
            ['userid' => $user->id, 'user_email' => 'A@example.com']
        );
        $this->generator->create_participant(
            $session,
            $this->at(15),
            $this->at(30),
            ['userid' => $user->id, 'user_email' => 'a@example.com']
        );
        $this->generator->create_participant($session, $this->at(40), $this->at(70), ['userid' => $user->id]);
        $this->generator->create_participant(
            $session,
            $this->at(10),
            $this->at(20),
            ['name' => 'Guest', 'user_email' => 'guest@example.org']
        );
        // Only outside the window: no row.
        $this->generator->create_participant($session, $this->at(-30), $this->at(-10), ['name' => 'Early']);

        $this->assertSame(1, sync::sync_all());

        $occurrences = $DB->get_records('local_zoomatt_occurrence', ['zoomid' => $cm->instance]);
        $this->assertCount(1, $occurrences);
        $occurrence = reset($occurrences);
        $this->assertSame(sync::KEY_SINGLE, $occurrence->occurrencekey);
        $this->assertEquals(HOURSECS, $occurrence->actualsecs);

        $results = $this->results($cm->instance);
        $this->assertCount(2, $results);
        $mine = $results['u:' . $user->id];
        $this->assertEquals(50 * MINSECS, $mine->attendedsecs);
        $this->assertEquals($this->at(0), $mine->firstjoin);
        $this->assertEquals($this->at(60), $mine->lastleave);
        $this->assertEquals(2, $mine->matchstrength);
        $guest = $results['z:' . sha1('e:guest@example.org')];
        $this->assertNull($guest->userid);
        $this->assertSame('Guest', $guest->displayname);
        $this->assertEquals(10 * MINSECS, $guest->attendedsecs);
    }

    public function test_sync_is_idempotent_and_picks_up_new_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->at(0), $this->at(60));
        $this->generator->create_participant($session, $this->at(0), $this->at(20), ['userid' => $user->id]);

        sync::sync_all();
        $before = $DB->get_records('local_zoomatt_result');
        $occurrence = $DB->get_record('local_zoomatt_occurrence', ['zoomid' => $cm->instance]);
        $DB->set_field('local_zoomatt_occurrence', 'timecomputed', 1, ['id' => $occurrence->id]);

        sync::sync_all();
        $this->assertEquals($before, $DB->get_records('local_zoomatt_result'));
        $this->assertEquals(1, $DB->get_field('local_zoomatt_occurrence', 'timecomputed', ['id' => $occurrence->id]));

        // A later mod_zoom import adds a segment: only now is the occurrence recomputed.
        $this->generator->create_participant($session, $this->at(30), $this->at(40), ['userid' => $user->id]);
        sync::sync_all();
        $this->assertEquals(30 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);
        $this->assertNotEquals(1, $DB->get_field('local_zoomatt_occurrence', 'timecomputed', ['id' => $occurrence->id]));
    }

    public function test_rematched_duplicate_segment_counts_once(): void {
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->at(0), $this->at(60));
        // The Zoom plugin inserts the same segment twice when a re-run matches the user differently.
        $this->generator->create_participant(
            $session,
            $this->at(0),
            $this->at(20),
            ['name' => 'Ann', 'zoomuserid' => 'z1']
        );
        $this->generator->create_participant(
            $session,
            $this->at(0),
            $this->at(20),
            ['userid' => $user->id, 'name' => 'ANN USER', 'zoomuserid' => 'z1']
        );

        sync::sync_all();
        $results = $this->results($cm->instance);
        $this->assertSame(['u:' . $user->id], array_keys($results));
        $this->assertEquals(1, $results['u:' . $user->id]->matchstrength);
    }

    public function test_recurring_snapshot_survives_event_deletion(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 2]);
        $past = $this->generator->create_occurrence_event($cm, $this->at(0), HOURSECS, 'past');
        $this->generator->create_occurrence_event($cm, $this->at(7 * 24 * 60), HOURSECS, 'week2');
        $future = time() + 7 * DAYSECS;
        $this->generator->create_occurrence_event($cm, $future, HOURSECS, 'future');
        $session = $this->generator->create_session($cm, $this->at(3), $this->at(58));
        $user = $this->getDataGenerator()->create_user();
        $this->generator->create_participant($session, $this->at(3), $this->at(58), ['userid' => $user->id]);

        sync::sync_all();
        $bykey = [];
        foreach ($DB->get_records('local_zoomatt_occurrence', ['zoomid' => $cm->instance]) as $o) {
            $bykey[$o->occurrencekey] = $o;
        }
        $this->assertEqualsCanonicalizing(['past', 'week2', 'future'], array_keys($bykey));
        $this->assertEquals(
            $bykey['past']->id,
            $DB->get_field('local_zoomatt_session', 'occurrenceid', ['detailsid' => $session->id])
        );
        $this->assertEquals(55 * MINSECS, $bykey['past']->actualsecs);

        // The Zoom plugin's update_meetings task deletes events missing from the Zoom response.
        $DB->delete_records('event', ['modulename' => 'zoom', 'instance' => $cm->instance]);
        sync::sync_all();
        $after = [];
        foreach ($DB->get_records('local_zoomatt_occurrence', ['zoomid' => $cm->instance]) as $o) {
            $after[$o->occurrencekey] = $o;
        }
        $this->assertEquals(sync::STATUS_ACTIVE, $after['past']->status);
        $this->assertEquals(sync::STATUS_CANCELLED, $after['future']->status);
        $this->assertEquals(55 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);

        // The future occurrence comes back.
        $this->generator->create_occurrence_event($cm, $future, HOURSECS, 'future');
        sync::sync_all();
        $this->assertEquals(sync::STATUS_ACTIVE, $DB->get_field(
            'local_zoomatt_occurrence',
            'status',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'future']
        ));
    }

    public function test_rescheduling_only_changes_future_occurrences(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 1]);
        $past = $this->generator->create_occurrence_event($cm, $this->at(0), HOURSECS, 'past');
        $futurestart = time() + DAYSECS;
        $future = $this->generator->create_occurrence_event($cm, $futurestart, HOURSECS, 'future');
        sync::sync_all();

        $DB->set_field('event', 'timestart', $this->at(30), ['id' => $past->id]);
        $DB->set_field('event', 'timestart', $futurestart + HOURSECS, ['id' => $future->id]);
        sync::sync_all();
        $this->assertEquals($this->at(0), $DB->get_field(
            'local_zoomatt_occurrence',
            'timestart',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'past']
        ));
        $this->assertEquals($futurestart + HOURSECS, $DB->get_field(
            'local_zoomatt_occurrence',
            'timestart',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'future']
        ));
    }

    public function test_host_restart_sessions_share_an_occurrence(): void {
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $first = $this->generator->create_session($cm, $this->at(0), $this->at(25));
        $second = $this->generator->create_session($cm, $this->at(30), $this->at(60));
        $this->generator->create_participant($first, $this->at(0), $this->at(25), ['userid' => $user->id]);
        $this->generator->create_participant($second, $this->at(30), $this->at(60), ['userid' => $user->id]);

        sync::sync_all();
        $this->assertEquals(55 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);
    }

    public function test_no_fixed_time_meeting_uses_inferred_windows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 0]);
        $a = $this->generator->create_session($cm, $this->at(0), $this->at(40));
        $b = $this->generator->create_session($cm, $this->at(50), $this->at(90));
        $c = $this->generator->create_session($cm, $this->at(24 * 60), $this->at(24 * 60 + 60));
        $this->generator->create_participant($a, $this->at(0), $this->at(40), ['userid' => $user->id]);
        $this->generator->create_participant($c, $this->at(24 * 60), $this->at(24 * 60 + 30), ['userid' => $user->id]);

        sync::sync_all();
        $occurrences = array_values($DB->get_records('local_zoomatt_occurrence', ['zoomid' => $cm->instance], 'timestart'));
        $this->assertCount(2, $occurrences);
        $this->assertSame(sync::SOURCE_INFERRED, $occurrences[0]->source);
        $this->assertEquals([$this->at(0), $this->at(90)], [$occurrences[0]->timestart, $occurrences[0]->timeend]);
        $this->assertEquals(80 * MINSECS, $occurrences[0]->actualsecs);
        $this->assertEquals(
            $occurrences[0]->id,
            $DB->get_field('local_zoomatt_session', 'occurrenceid', ['detailsid' => $b->id])
        );

        // Deleting the sessions (mod_zoom instance cleanup) removes inferred occurrences.
        $DB->delete_records('zoom_meeting_participants');
        $DB->delete_records('zoom_meeting_details');
        sync::sync_all();
        $this->assertSame(0, $DB->count_records('local_zoomatt_occurrence', ['zoomid' => $cm->instance]));
        $this->assertSame(0, $DB->count_records('local_zoomatt_result'));
    }

    public function test_results_mirror_deleted_source_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->at(0), $this->at(60));
        $this->generator->create_participant($session, $this->at(0), $this->at(60), ['userid' => $user->id]);
        sync::sync_all();
        $this->assertCount(1, $this->results($cm->instance));

        // Course reset in mod_zoom deletes participants but keeps the session.
        $DB->delete_records('zoom_meeting_participants', ['detailsid' => $session->id]);
        sync::sync_all();
        $this->assertCount(0, $this->results($cm->instance));
    }

    public function test_disabled_activity_is_skipped(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        $DB->insert_record('local_zoomatt_settings', (object) ['cmid' => $cm->id, 'enabled' => 0, 'timemodified' => time()]);
        $this->assertSame(0, sync::sync_all());
        $this->assertSame(0, $DB->count_records('local_zoomatt_occurrence'));
    }

    public function test_orphans_are_deleted(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->at(0),
            'duration' => HOURSECS]);
        sync::sync_all();
        $this->assertSame(1, $DB->count_records('local_zoomatt_occurrence'));
        $DB->delete_records('zoom', ['id' => $cm->instance]);
        sync::delete_orphans();
        $this->assertSame(0, $DB->count_records('local_zoomatt_occurrence'));
    }
}
