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
 * Tests for the course summary.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(course_summary::class)]
/**
 * Tests for the course summary.
 *
 * @covers \local_zoomattendance\local\course_summary
 */
final class course_summary_test extends \advanced_testcase {
    public function test_columns_cells_and_course_overall(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $this->setAdminUser();
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $alice = $dg->create_and_enrol($course, 'student', ['firstname' => 'Alice', 'lastname' => 'A']);
        $bob = $dg->create_and_enrol($course, 'student', ['firstname' => 'Bob', 'lastname' => 'B']);
        $t0 = (intdiv(time(), DAYSECS) - 21) * DAYSECS + 10 * HOURSECS;

        // A weekly meeting: two past occurrences with sessions, one without, one upcoming.
        $weekly = $generator->create_zoom(['course' => $course->id, 'recurring' => 1, 'recurrence_type' => 2]);
        foreach ([0, 1, 2] as $week) {
            $generator->create_occurrence_event($weekly, $t0 + $week * WEEKSECS, HOURSECS);
        }
        $generator->create_occurrence_event($weekly, $t0 + 4 * WEEKSECS, HOURSECS);
        $session = $generator->create_session($weekly, $t0, $t0 + HOURSECS);
        $generator->create_participant($session, $t0, $t0 + HOURSECS, ['userid' => $alice->id]);
        $generator->create_participant($session, $t0, $t0 + 20 * MINSECS, ['userid' => $bob->id]);
        $session = $generator->create_session($weekly, $t0 + WEEKSECS, $t0 + WEEKSECS + HOURSECS);
        $generator->create_participant($session, $t0 + WEEKSECS, $t0 + WEEKSECS + HOURSECS, ['userid' => $alice->id]);

        // A one-off two hour workshop.
        $start = $t0 + DAYSECS;
        $workshop = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => 2 * HOURSECS]);
        $session = $generator->create_session($workshop, $start, $start + 2 * HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $alice->id]);
        $generator->create_participant($session, $start, $start + 2 * HOURSECS, ['userid' => $bob->id]);

        // Tracking turned off: no columns.
        $off = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $generator->create_session($off, $start, $start + HOURSECS);
        $DB->insert_record('local_zoomattendance_setting', (object) ['cmid' => $off->id, 'enabled' => 0,
            'timemodified' => time()]);

        sync::sync_all();
        $summary = course_summary::build($course);

        $this->assertEquals([$weekly->id, $workshop->id], array_keys($summary->activities));
        $this->assertCount(2, $summary->activities[$weekly->id]->columns);
        $this->assertCount(1, $summary->activities[$workshop->id]->columns);
        $this->assertEquals([$alice->id, $bob->id], array_keys($summary->users));

        [$week1, $week2] = array_keys($summary->activities[$weekly->id]->columns);
        $this->assertSame(status::PRESENT, $summary->cells[$alice->id][$week1]->status);
        $this->assertSame(status::ABSENT, $summary->cells[$bob->id][$week1]->status);
        $this->assertSame(status::ABSENT, $summary->cells[$bob->id][$week2]->status);
        $this->assertEqualsWithDelta(100.0 / 3, $summary->cells[$bob->id][$week1]->percentage, 0.01);
        // Alice joined the workshop on time but left at half time.
        $workshopcolumn = array_key_first($summary->activities[$workshop->id]->columns);
        $this->assertSame(status::PARTIAL, $summary->cells[$alice->id][$workshopcolumn]->status);

        // Alice: 60 + 60 + 60 of 60 + 60 + 120 minutes. Bob: 20 + 0 + 120 of the same.
        $this->assertEqualsWithDelta(75.0, $summary->overall[$alice->id]->percentage(), 0.01);
        $this->assertEqualsWithDelta(140 / 2.4, $summary->overall[$bob->id]->percentage(), 0.01);
        $this->assertSame(3, $summary->overall[$bob->id]->count);

        // The user page builds the same overall for one user.
        $single = course_summary::build($course, 0, $bob->id);
        $this->assertEquals([$bob->id], array_keys($single->users));
        $this->assertEqualsWithDelta(
            $summary->overall[$bob->id]->percentage(),
            $single->overall[$bob->id]->percentage(),
            0.001
        );
    }
}
