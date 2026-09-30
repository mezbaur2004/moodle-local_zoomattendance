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
 * Tests for status evaluation.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Tests for status evaluation.
 *
 * @covers \local_zoomattendance\local\status
 * @covers \local_zoomattendance\local\settings
 */
#[\PHPUnit\Framework\Attributes\CoversClass(status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settings::class)]
final class status_test extends \advanced_testcase {
    /**
     * Status cases with the default thresholds (75 / 50 / 10 min) and a one hour window.
     *
     * @return array
     */
    public static function status_provider(): array {
        return [
            'no result' => [0, null, status::ABSENT],
            'below late' => [29 * 60, 0, status::ABSENT],
            'exactly late threshold' => [30 * 60, 0, status::LATE],
            'between thresholds' => [40 * 60, 0, status::LATE],
            'exactly present threshold' => [45 * 60, 0, status::PRESENT],
            'present joined at grace' => [50 * 60, 10 * 60, status::PRESENT],
            'enough time but joined after grace' => [50 * 60, 10 * 60 + 1, status::LATE],
        ];
    }

    /**
     * Test evaluate.
     *
     * @dataProvider status_provider
     * @param int $attended
     * @param int|null $firstjoin
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('status_provider')]
    public function test_evaluate(int $attended, ?int $firstjoin, string $expected): void {
        $this->resetAfterTest();
        $this->assertSame($expected, status::evaluate($attended, $firstjoin, 0, HOURSECS, settings::site_defaults()));
    }

    /**
     * A zero denominator is invalid.
     */
    public function test_invalid_denominator(): void {
        $this->resetAfterTest();
        $this->assertSame(status::INVALID, status::evaluate(10, 0, 0, 0, settings::site_defaults()));
    }

    /**
     * Overrides replace site defaults field by field; late never exceeds present.
     */
    public function test_overrides(): void {
        $this->resetAfterTest();
        set_config('presentpct', 80, 'local_zoomattendance');
        $settings = settings::from_override((object) [
            'enabled' => '1', 'presentpct' => null, 'latepct' => '90', 'lategracemins' => '5', 'denominator' => 'actual',
        ]);
        $this->assertTrue($settings->enabled);
        $this->assertSame(80, $settings->presentpct);
        $this->assertSame(80, $settings->latepct);
        $this->assertSame(5, $settings->lategracemins);
        $this->assertSame(settings::DENOMINATOR_ACTUAL, $settings->denominator);
        $this->assertSame(1200, $settings->denominator_for((object) ['timestart' => 0, 'timeend' => 3600, 'actualsecs' => 1200]));

        $defaults = settings::from_override(null);
        $this->assertFalse($defaults->enabled);
        $this->assertSame(3600, $defaults->denominator_for((object) ['timestart' => 0, 'timeend' => 3600, 'actualsecs' => 1200]));
    }
}
