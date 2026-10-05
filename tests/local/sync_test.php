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
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(sync::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\zoom_source::class)]
/**
 * Tests for the sync engine, using mod_zoom tables filled by the plugin's generator.
 *
 * @covers \local_zoomattendance\local\sync
 * @covers \local_zoomattendance\local\source\zoom_source
 */
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
        $rows = $DB->get_records_sql("SELECT r.*
                                        FROM {local_zoomattendance_result} r
                                        JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
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
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(-2), $this->mins(62));
        // The worked example: 50 minutes after clipping and union.
        $this->generator->create_participant(
            $session,
            $this->mins(-5),
            $this->mins(20),
            ['userid' => $user->id, 'user_email' => 'A@example.com']
        );
        $this->generator->create_participant(
            $session,
            $this->mins(15),
            $this->mins(30),
            ['userid' => $user->id, 'user_email' => 'a@example.com']
        );
        $this->generator->create_participant($session, $this->mins(40), $this->mins(70), ['userid' => $user->id]);
        $this->generator->create_participant(
            $session,
            $this->mins(10),
            $this->mins(20),
            ['name' => 'Guest', 'user_email' => 'guest@example.org']
        );
        // Only outside the window: no row.
        $this->generator->create_participant($session, $this->mins(-30), $this->mins(-10), ['name' => 'Early']);

        $this->assertSame(1, sync::sync_all());

        $occurrences = $DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $this->assertCount(1, $occurrences);
        $occurrence = reset($occurrences);
        $this->assertSame(sync::KEY_SINGLE, $occurrence->occurrencekey);
        $this->assertEquals(HOURSECS, $occurrence->actualsecs);

        $results = $this->results($cm->instance);
        $this->assertCount(2, $results);
        $mine = $results['u:' . $user->id];
        $this->assertEquals(50 * MINSECS, $mine->attendedsecs);
        $this->assertEquals($this->mins(0), $mine->firstjoin);
        $this->assertEquals($this->mins(60), $mine->lastleave);
        $this->assertEquals(2, $mine->matchstrength);
        $guest = $results['z:' . sha1('e:guest@example.org')];
        $this->assertNull($guest->userid);
        $this->assertSame('Guest', $guest->displayname);
        $this->assertEquals(10 * MINSECS, $guest->attendedsecs);
    }

    public function test_sync_is_idempotent_and_picks_up_new_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(20), ['userid' => $user->id]);

        sync::sync_all();
        $before = $DB->get_records('local_zoomattendance_result');
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance]);
        $DB->set_field('local_zoomattendance_occ', 'timecomputed', 1, ['id' => $occurrence->id]);

        sync::sync_all();
        $this->assertEquals($before, $DB->get_records('local_zoomattendance_result'));
        $this->assertEquals(1, $DB->get_field('local_zoomattendance_occ', 'timecomputed', ['id' => $occurrence->id]));

        // A later mod_zoom import adds a segment: only now is the occurrence recomputed.
        $this->generator->create_participant($session, $this->mins(30), $this->mins(40), ['userid' => $user->id]);
        sync::sync_all();
        $this->assertEquals(30 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);
        $this->assertNotEquals(1, $DB->get_field('local_zoomattendance_occ', 'timecomputed', ['id' => $occurrence->id]));
    }

    public function test_rematched_duplicate_segment_counts_once(): void {
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        // The Zoom plugin inserts the same segment twice when a re-run matches the user differently.
        $this->generator->create_participant(
            $session,
            $this->mins(0),
            $this->mins(20),
            ['name' => 'Ann', 'zoomuserid' => 'z1']
        );
        $this->generator->create_participant(
            $session,
            $this->mins(0),
            $this->mins(20),
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
        $past = $this->generator->create_occurrence_event($cm, $this->mins(0), HOURSECS, 'past');
        $this->generator->create_occurrence_event($cm, $this->mins(7 * 24 * 60), HOURSECS, 'week2');
        $future = time() + 7 * DAYSECS;
        $this->generator->create_occurrence_event($cm, $future, HOURSECS, 'future');
        $session = $this->generator->create_session($cm, $this->mins(3), $this->mins(58));
        $user = $this->getDataGenerator()->create_user();
        $this->generator->create_participant($session, $this->mins(3), $this->mins(58), ['userid' => $user->id]);

        sync::sync_all();
        $bykey = [];
        foreach ($DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]) as $o) {
            $bykey[$o->occurrencekey] = $o;
        }
        $this->assertEqualsCanonicalizing(['past', 'week2', 'future'], array_keys($bykey));
        $this->assertEquals(
            $bykey['past']->id,
            $DB->get_field('local_zoomattendance_session', 'occurrenceid', ['detailsid' => $session->id])
        );
        $this->assertEquals(55 * MINSECS, $bykey['past']->actualsecs);

        // The Zoom plugin's update_meetings task deletes events missing from the Zoom response.
        $DB->delete_records('event', ['modulename' => 'zoom', 'instance' => $cm->instance]);
        sync::sync_all();
        $after = [];
        foreach ($DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]) as $o) {
            $after[$o->occurrencekey] = $o;
        }
        $this->assertEquals(sync::STATUS_ACTIVE, $after['past']->status);
        $this->assertEquals(sync::STATUS_CANCELLED, $after['future']->status);
        $this->assertEquals(55 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);

        // The future occurrence comes back.
        $this->generator->create_occurrence_event($cm, $future, HOURSECS, 'future');
        sync::sync_all();
        $this->assertEquals(sync::STATUS_ACTIVE, $DB->get_field(
            'local_zoomattendance_occ',
            'status',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'future']
        ));
    }

    public function test_rescheduling_only_changes_future_occurrences(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 1]);
        $past = $this->generator->create_occurrence_event($cm, $this->mins(0), HOURSECS, 'past');
        $futurestart = time() + DAYSECS;
        $future = $this->generator->create_occurrence_event($cm, $futurestart, HOURSECS, 'future');
        sync::sync_all();

        $DB->set_field('event', 'timestart', $this->mins(30), ['id' => $past->id]);
        $DB->set_field('event', 'timestart', $futurestart + HOURSECS, ['id' => $future->id]);
        sync::sync_all();
        $this->assertEquals($this->mins(0), $DB->get_field(
            'local_zoomattendance_occ',
            'timestart',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'past']
        ));
        $this->assertEquals($futurestart + HOURSECS, $DB->get_field(
            'local_zoomattendance_occ',
            'timestart',
            ['zoomid' => $cm->instance, 'occurrencekey' => 'future']
        ));
    }

    public function test_host_restart_sessions_share_an_occurrence(): void {
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $first = $this->generator->create_session($cm, $this->mins(0), $this->mins(25));
        $second = $this->generator->create_session($cm, $this->mins(30), $this->mins(60));
        $this->generator->create_participant($first, $this->mins(0), $this->mins(25), ['userid' => $user->id]);
        $this->generator->create_participant($second, $this->mins(30), $this->mins(60), ['userid' => $user->id]);

        sync::sync_all();
        $this->assertEquals(55 * MINSECS, $this->results($cm->instance)['u:' . $user->id]->attendedsecs);
    }

    public function test_no_fixed_time_meeting_uses_inferred_windows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 0]);
        $a = $this->generator->create_session($cm, $this->mins(0), $this->mins(40));
        $b = $this->generator->create_session($cm, $this->mins(50), $this->mins(90));
        $c = $this->generator->create_session($cm, $this->mins(24 * 60), $this->mins(24 * 60 + 60));
        $this->generator->create_participant($a, $this->mins(0), $this->mins(40), ['userid' => $user->id]);
        $this->generator->create_participant($c, $this->mins(24 * 60), $this->mins(24 * 60 + 30), ['userid' => $user->id]);

        sync::sync_all();
        $occurrences = array_values($DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance], 'timestart'));
        $this->assertCount(2, $occurrences);
        $this->assertSame(sync::SOURCE_INFERRED, $occurrences[0]->source);
        $this->assertEquals([$this->mins(0), $this->mins(90)], [$occurrences[0]->timestart, $occurrences[0]->timeend]);
        $this->assertEquals(80 * MINSECS, $occurrences[0]->actualsecs);
        $this->assertEquals(
            $occurrences[0]->id,
            $DB->get_field('local_zoomattendance_session', 'occurrenceid', ['detailsid' => $b->id])
        );

        // Deleting the sessions (mod_zoom instance cleanup) removes inferred occurrences.
        $DB->delete_records('zoom_meeting_participants');
        $DB->delete_records('zoom_meeting_details');
        sync::sync_all();
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_result'));
    }

    public function test_past_classes_without_events_use_the_regular_time(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        // Weekly at t0's time of day; Zoom only lists upcoming occurrences, so past ones have no event.
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 2,
            'start_time' => $this->mins(14 * 24 * 60), 'duration' => HOURSECS, 'timezone' => 'UTC']);
        // The host opened the room 20 minutes early and ran 15 minutes over.
        $held = $this->generator->create_session($cm, $this->mins(-20), $this->mins(75));
        $this->generator->create_participant($held, $this->mins(-15), $this->mins(70), ['userid' => $user->id]);
        // An extra meeting at another time of day stays inferred.
        $extra = $this->generator->create_session($cm, $this->mins(5 * 60), $this->mins(5 * 60 + 30));

        sync::sync_all();
        $occurrences = array_values($DB->get_records('local_zoomattendance_occ', ['zoomid' => $cm->instance], 'timestart'));
        $this->assertCount(2, $occurrences);
        $this->assertSame(sync::SOURCE_PATTERN, $occurrences[0]->source);
        $this->assertEquals([$this->mins(0), $this->mins(60)], [$occurrences[0]->timestart, $occurrences[0]->timeend]);
        $result = $this->results($cm->instance)['u:' . $user->id];
        $this->assertEquals(HOURSECS, $result->attendedsecs);
        $this->assertSame(sync::SOURCE_INFERRED, $occurrences[1]->source);
        $this->assertEquals(
            $occurrences[1]->id,
            $DB->get_field('local_zoomattendance_session', 'occurrenceid', ['detailsid' => $extra->id])
        );

        // Idempotent, and a class whose sessions are gone is dropped like an inferred one.
        sync::sync_all();
        $this->assertSame(2, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
        $DB->delete_records('zoom_meeting_participants', ['detailsid' => $held->id]);
        $DB->delete_records('zoom_meeting_details', ['id' => $held->id]);
        sync::sync_all();
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ', ['source' => sync::SOURCE_PATTERN]));
    }

    public function test_classes_inferred_before_upgrade_move_to_the_regular_time(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'recurring' => 1, 'recurrence_type' => 2,
            'start_time' => 0, 'duration' => HOURSECS, 'timezone' => 'UTC']);
        $this->generator->create_session($cm, $this->mins(-20), $this->mins(75));
        sync::sync_all();
        $source = $DB->get_field('local_zoomattendance_occ', 'source', ['zoomid' => $cm->instance]);
        $this->assertSame(sync::SOURCE_INFERRED, $source);

        $DB->set_field('zoom', 'start_time', $this->mins(14 * 24 * 60), ['id' => $cm->instance]);
        sync::sync_all();
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame(sync::SOURCE_PATTERN, $occurrence->source);
        $this->assertEquals(HOURSECS, $occurrence->actualsecs);
    }

    public function test_pattern_window_follows_the_meeting_time_zone(): void {
        $london = new \DateTimeZone('Europe/London');
        $instance = (object) [
            'recurring' => 1,
            'recurrence_type' => 2,
            // 10:00 in London in winter (UTC+0).
            'start_time' => (new \DateTime('2026-01-12 10:00', $london))->getTimestamp(),
            'duration' => HOURSECS,
            'timezone' => 'Europe/London',
        ];
        // A summer class at 10:00 London time is 09:00 UTC.
        $summer = (new \DateTime('2026-06-15 09:00', new \DateTimeZone('UTC')))->getTimestamp();
        $window = sync::pattern_window($instance, $summer - 600, $summer + 3000, 1800, 1800);
        $this->assertSame([$summer, $summer + HOURSECS], $window);
        // Far from the regular time, or without a fixed time, there is no window.
        $this->assertNull(sync::pattern_window($instance, $summer + 5 * HOURSECS, $summer + 6 * HOURSECS, 1800, 1800));
        $instance->recurrence_type = 0;
        $this->assertNull(sync::pattern_window($instance, $summer, $summer + HOURSECS, 1800, 1800));
    }

    public function test_results_mirror_deleted_source_rows(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $user->id]);
        sync::sync_all();
        $this->assertCount(1, $this->results($cm->instance));

        // Course reset in mod_zoom deletes participants but keeps the session.
        $DB->delete_records('zoom_meeting_participants', ['detailsid' => $session->id]);
        sync::sync_all();
        $this->assertCount(0, $this->results($cm->instance));
    }

    public function test_disabled_activity_is_skipped(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $DB->insert_record('local_zoomattendance_setting', (object) ['cmid' => $cm->id, 'enabled' => 0, 'timemodified' => time()]);
        $this->assertSame(0, sync::sync_all());
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ'));
    }

    public function test_orphans_are_deleted(): void {
        global $DB;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        sync::sync_all();
        $this->assertSame(1, $DB->count_records('local_zoomattendance_occ'));
        $DB->delete_records('zoom', ['id' => $cm->instance]);
        sync::delete_orphans();
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ'));
    }

    /**
     * Make the incremental sync treat every mod_zoom row so far as long known: it looks at the
     * most recent ids again, for rows committed late.
     */
    protected function forget_recent_rows(): void {
        $state = json_decode(get_config('local_zoomattendance', 'syncstate'));
        $state->pid += 2000;
        $state->did += 2000;
        set_config('syncstate', json_encode($state), 'local_zoomattendance');
    }

    public function test_hourly_sync_only_visits_changed_activities(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $sessions = [];
        $cms = [];
        foreach (['a', 'b'] as $name) {
            $cms[$name] = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
                'duration' => HOURSECS]);
            $sessions[$name] = $this->generator->create_session($cms[$name], $this->mins(0), $this->mins(60));
            $this->generator->create_participant($sessions[$name], $this->mins(0), $this->mins(20), ['userid' => $student->id]);
        }
        // The first sync is a full pass. The activities were created an hour ago (the sync looks a
        // few minutes back for rows written while it ran); nothing changed since, so nothing is visited.
        $this->assertSame(2, sync::sync_all());
        $DB->set_field('zoom', 'timemodified', time() - HOURSECS);
        // Recent rows are looked at again, in case one was committed late.
        $this->assertSame(2, sync::sync_all());
        $this->forget_recent_rows();
        $this->assertSame(0, sync::sync_all());

        // A new participant row is found from its id.
        $this->forget_recent_rows();
        $this->generator->create_participant($sessions['a'], $this->mins(20), $this->mins(50), ['userid' => $student->id]);
        $this->assertSame(1, sync::sync_all());
        $this->assertEquals(50 * MINSECS, $this->results($cms['a']->instance)['u:' . $student->id]->attendedsecs);

        // A deleted row is only noticed by a full pass.
        $this->forget_recent_rows();
        $DB->delete_records('zoom_meeting_participants', ['detailsid' => $sessions['b']->id]);
        $this->assertSame(0, sync::sync_all());
        $this->assertArrayHasKey('u:' . $student->id, $this->results($cms['b']->instance));
        $this->assertSame(2, sync::sync_all(null, true));
        $this->assertArrayNotHasKey('u:' . $student->id, $this->results($cms['b']->instance));

        // Changing how sessions map to classes asks for a full pass.
        set_config('earlymarginmins', 20, 'local_zoomattendance');
        $this->assertSame(2, sync::sync_all());
        $this->assertGreaterThan(0, (int) get_config('local_zoomattendance', 'lastsync'));
    }

    public function test_retention_removes_old_classes_and_keeps_them_out(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $old = time() - 40 * DAYSECS;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $old,
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $old, $old + HOURSECS);
        $this->generator->create_participant($session, $old, $old + HOURSECS, ['userid' => $student->id]);
        sync::sync_all();
        $this->assertSame(1, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));

        // Three days is less than the shortest period: 30 days are kept.
        set_config('retentiondays', 3, 'local_zoomattendance');
        $this->assertEqualsWithDelta(time() - 30 * DAYSECS, settings::retention_cutoff(), 5);

        // The class was 40 days ago.
        sync::sync_all();
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_result'));
        sync::sync_all(null, true);
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
        // The Zoom plugin's own data is untouched.
        $this->assertTrue($DB->record_exists('zoom_meeting_participants', ['detailsid' => $session->id]));
    }

    public function test_retention_does_not_bring_back_a_class_at_the_cutoff(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        set_config('retentiondays', 30, 'local_zoomattendance');
        // A class that started just before the cutoff and ran past it.
        $start = time() - 30 * DAYSECS - 30 * MINSECS;
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $start,
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $start, $start + HOURSECS + 10 * MINSECS);
        $this->generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $student->id]);
        sync::sync_all(null, true);
        sync::sync_all(null, true);
        // Neither the scheduled class nor a class made up from its session is kept.
        $this->assertSame(0, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $cm->instance]));
    }

    public function test_late_committed_and_changed_rows_are_noticed(): void {
        global $DB;
        $amy = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $ben = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $gap = $this->generator->create_participant($session, $this->mins(0), $this->mins(10), ['userid' => $ben->id]);
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $amy->id]);
        $DB->delete_records('zoom_meeting_participants', ['id' => $gap->id]);
        sync::sync_all();
        $DB->set_field('zoom', 'timemodified', time() - HOURSECS);

        // A row whose id was handed out earlier is committed after the sync saw higher ids.
        $DB->insert_record_raw('zoom_meeting_participants', (array) $gap, false, false, true);
        sync::sync_all();
        $this->assertEquals(10 * MINSECS, $this->results($cm->instance)['u:' . $ben->id]->attendedsecs);

        // The Zoom plugin re-matches two rows in place, swapping their users.
        $rows = $DB->get_records('zoom_meeting_participants', ['detailsid' => $session->id], 'id');
        [$first, $second] = array_values($rows);
        $DB->set_field('zoom_meeting_participants', 'userid', $second->userid, ['id' => $first->id]);
        $DB->set_field('zoom_meeting_participants', 'userid', $first->userid, ['id' => $second->id]);
        sync::sync_all(null, true);
        $results = $this->results($cm->instance);
        $this->assertEquals(10 * MINSECS, $results['u:' . $amy->id]->attendedsecs);
        $this->assertEquals(60 * MINSECS, $results['u:' . $ben->id]->attendedsecs);
    }

    public function test_fingerprints_from_older_versions_do_not_recompute(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $cm = $this->generator->create_zoom(['course' => $this->course->id, 'start_time' => $this->mins(0),
            'duration' => HOURSECS]);
        $session = $this->generator->create_session($cm, $this->mins(0), $this->mins(60));
        $this->generator->create_participant($session, $this->mins(0), $this->mins(60), ['userid' => $student->id]);
        sync::sync_all();
        $sessions = source\zoom_source::get_sessions((int) $cm->instance);
        $DB->set_field('local_zoomattendance_session', 'fingerprint', $sessions[$session->id]->legacyfingerprint);
        $DB->set_field('local_zoomattendance_occ', 'timecomputed', 1);

        sync::sync_all(null, true);
        // Stored the new way, without recomputing the class.
        $this->assertSame($sessions[$session->id]->fingerprint, $DB->get_field(
            'local_zoomattendance_session',
            'fingerprint',
            ['detailsid' => $session->id]
        ));
        $this->assertEquals(1, $DB->get_field('local_zoomattendance_occ', 'timecomputed', ['zoomid' => $cm->instance]));
    }
}
