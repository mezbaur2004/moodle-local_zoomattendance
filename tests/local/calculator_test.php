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
 * Tests for the interval calculator.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(calculator::class)]
/**
 * Tests for the interval calculator.
 *
 * @covers \local_zoomattendance\local\calculator
 */
final class calculator_test extends \basic_testcase {
    /**
     * Clip-and-merge cases.
     *
     * @return array
     */
    public static function merge_provider(): array {
        return [
            'empty' => [[], 0, 100, []],
            'inside' => [[[10, 20]], 0, 100, [[10, 20]]],
            'clipped both ends' => [[[-10, 200]], 0, 100, [[0, 100]]],
            'outside' => [[[-50, -10], [110, 120]], 0, 100, []],
            'overlapping' => [[[10, 30], [20, 40]], 0, 100, [[10, 40]]],
            'nested' => [[[10, 50], [20, 30]], 0, 100, [[10, 50]]],
            'adjacent' => [[[10, 20], [20, 30]], 0, 100, [[10, 30]]],
            'disjoint unsorted' => [[[60, 70], [10, 20]], 0, 100, [[10, 20], [60, 70]]],
            'zero length dropped' => [[[15, 15], [30, 40]], 0, 100, [[30, 40]]],
            'reversed dropped' => [[[40, 30]], 0, 100, []],
            'duplicates' => [[[10, 20], [10, 20]], 0, 100, [[10, 20]]],
            'third inside a gap' => [[[0, 10], [50, 60], [20, 30]], 0, 100, [[0, 10], [20, 30], [50, 60]]],
        ];
    }

    /**
     * Test clip_and_merge.
     *
     * @dataProvider merge_provider
     * @param array $intervals
     * @param int $start
     * @param int $end
     * @param array $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('merge_provider')]
    public function test_clip_and_merge(array $intervals, int $start, int $end, array $expected): void {
        $this->assertSame($expected, calculator::clip_and_merge($intervals, $start, $end));
    }

    /**
     * The worked example from docs/ARCHITECTURE.md B4.10.
     */
    public function test_summarise_worked_example(): void {
        $h = function (int $hour, int $minute): int {
            return $hour * HOURSECS + $minute * MINSECS;
        };
        $segments = [[$h(9, 55), $h(10, 20)], [$h(10, 15), $h(10, 30)], [$h(10, 40), $h(11, 10)]];
        $summary = calculator::summarise($segments, $h(10, 0), $h(11, 0));
        $this->assertSame(50 * MINSECS, $summary->attendedsecs);
        $this->assertSame($h(10, 0), $summary->firstjoin);
        $this->assertSame($h(11, 0), $summary->lastleave);
        $this->assertSame(2, $summary->segments);
        $this->assertEqualsWithDelta(83.33, calculator::percentage($summary->attendedsecs, HOURSECS), 0.01);
    }

    /**
     * Empty summary.
     */
    public function test_summarise_empty(): void {
        $summary = calculator::summarise([[200, 300]], 0, 100);
        $this->assertSame(0, $summary->attendedsecs);
        $this->assertNull($summary->firstjoin);
        $this->assertNull($summary->lastleave);
        $this->assertSame(0, $summary->segments);
    }

    /**
     * Percentage edge cases.
     */
    public function test_percentage(): void {
        $this->assertNull(calculator::percentage(10, 0));
        $this->assertSame(50.0, calculator::percentage(50, 100));
        $this->assertSame(100.0, calculator::percentage(150, 100));
    }
}
