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
     * Summarise occurrences with the default thresholds (75 / 50 / 10 min).
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
            $summary->add(
                $row,
                (object) ['occurrence' => (object) ['timestart' => $start], 'denominator' => $length],
                $settings
            );
        }
        return $summary;
    }

    /**
     * One occurrence gives exactly that occurrence's status.
     *
     * @return array
     */
    public static function single_provider(): array {
        return [
            'no result' => [0, null],
            'below late' => [29 * 60, 0],
            'between thresholds' => [40 * 60, 0],
            'present' => [45 * 60, 0],
            'enough time but joined after grace' => [50 * 60, 10 * 60 + 1],
        ];
    }

    /**
     * Test a single occurrence.
     *
     * @dataProvider single_provider
     * @param int $attended
     * @param int|null $offset
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('single_provider')]
    public function test_single_occurrence_matches_its_status(int $attended, ?int $offset): void {
        $this->resetAfterTest();
        $expected = status::evaluate($attended, $offset, 0, HOURSECS, settings::site_defaults());
        $summary = $this->summarise([[$attended, $offset, HOURSECS]]);
        $this->assertSame($expected, $summary->status(settings::site_defaults()));
        $this->assertEqualsWithDelta($attended * 100 / HOURSECS, $summary->percentage(), 0.001);
    }

    public function test_percentage_is_weighted_by_length(): void {
        $this->resetAfterTest();
        // 30 of 30 minutes and 30 of 90 minutes: 60 of 120 minutes, not the mean of 100% and 33%.
        $summary = $this->summarise([[30 * 60, 0, 30 * 60], [30 * 60, 0, 90 * 60]]);
        $this->assertEqualsWithDelta(50.0, $summary->percentage(), 0.001);
        $this->assertSame(status::LATE, $summary->status(settings::site_defaults()));
    }

    public function test_status_thresholds_on_overall_percentage(): void {
        $this->resetAfterTest();
        $settings = settings::site_defaults();
        // Present twice and absent once: 100 + 100 + 0 = 66.7%, below the present threshold.
        $this->assertSame(status::LATE, $this->summarise([[HOURSECS, 0, HOURSECS], [HOURSECS, 0, HOURSECS],
            [0, null, HOURSECS]])->status($settings));
        // Absent twice and present once: 33.3%, below the late threshold.
        $this->assertSame(status::ABSENT, $this->summarise([[HOURSECS, 0, HOURSECS], [0, null, HOURSECS],
            [0, null, HOURSECS]])->status($settings));
        // Never joined.
        $summary = $this->summarise([[0, null, HOURSECS], [0, null, HOURSECS]]);
        $this->assertSame(status::ABSENT, $summary->status($settings));
        $this->assertSame([status::PRESENT => 0, status::LATE => 0, status::ABSENT => 2], $summary->counts);
    }

    public function test_late_joins_count_in_more_than_half(): void {
        $this->resetAfterTest();
        $settings = settings::site_defaults();
        $ontime = [HOURSECS - 60, 0, HOURSECS];
        $late = [HOURSECS - 20 * 60, 20 * 60, HOURSECS];
        // One late join in two occurrences is not more than half.
        $this->assertSame(status::PRESENT, $this->summarise([$ontime, $late])->status($settings));
        $this->assertSame(status::LATE, $this->summarise([$ontime, $late, $late])->status($settings));
    }

    public function test_nothing_evaluated(): void {
        $this->resetAfterTest();
        $summary = new summary();
        $summary->add(
            (object) ['status' => status::INVALID, 'attendedsecs' => 0, 'firstjoin' => null],
            (object) ['occurrence' => (object) ['timestart' => 0], 'denominator' => 0],
            settings::site_defaults()
        );
        $this->assertSame(0, $summary->count);
        $this->assertNull($summary->percentage());
        $this->assertNull($summary->status(settings::site_defaults()));
    }
}
