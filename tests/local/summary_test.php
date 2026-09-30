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
 * Tests for the overall summary.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(summary::class)]
/**
 * Tests for the overall summary.
 *
 * @covers \local_zoomattendance\local\summary
 */
final class summary_test extends \advanced_testcase {
    /**
     * Summarise occurrences with the default thresholds.
     *
     * @param array $occurrences Each [attended secs, first join offset or null, length secs].
     * @return summary
     */
    protected function summarise(array $occurrences): summary {
        $settings = settings::site_defaults();
        $summary = new summary();
        foreach ($occurrences as $i => [$attended, $offset, $length]) {
            $start = $i * DAYSECS;
            $firstjoin = $offset === null ? null : $start + $offset;
            $row = (object) [
                'attendedsecs' => $attended,
                'firstjoin' => $firstjoin,
                'status' => status::evaluate($attended, $firstjoin, $start, $length, $settings),
            ];
            $summary->add($row, (object) ['denominator' => $length]);
        }
        return $summary;
    }

    public function test_single_occurrence_matches_its_percentage(): void {
        $this->resetAfterTest();
        $summary = $this->summarise([[40 * 60, 0, HOURSECS]]);
        $this->assertSame(1, $summary->count);
        $this->assertEqualsWithDelta(40 * 100 / 60, $summary->percentage(), 0.001);
    }

    public function test_percentage_is_weighted_by_length(): void {
        $this->resetAfterTest();
        // 30 of 30 minutes and 30 of 90 minutes: 60 of 120 minutes, not the mean of 100% and 33%.
        $summary = $this->summarise([[30 * 60, 0, 30 * 60], [30 * 60, 0, 90 * 60]]);
        $this->assertEqualsWithDelta(50.0, $summary->percentage(), 0.001);
    }

    public function test_absent_occurrences_count_as_zero(): void {
        $this->resetAfterTest();
        $summary = $this->summarise([[HOURSECS, 0, HOURSECS], [0, null, HOURSECS], [0, null, HOURSECS]]);
        $this->assertSame(3, $summary->count);
        $this->assertEqualsWithDelta(100 / 3, $summary->percentage(), 0.001);
    }

    public function test_nothing_evaluated(): void {
        $this->resetAfterTest();
        $summary = new summary();
        $summary->add(
            (object) ['status' => status::INVALID, 'attendedsecs' => 0, 'firstjoin' => null],
            (object) ['denominator' => 0]
        );
        $summary->add(
            (object) ['status' => null, 'attendedsecs' => 600, 'firstjoin' => 0],
            (object) ['denominator' => HOURSECS]
        );
        $this->assertSame(0, $summary->count);
        $this->assertNull($summary->percentage());
    }
}
