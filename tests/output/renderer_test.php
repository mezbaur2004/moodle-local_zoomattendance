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
 * Tests for the attendance bars.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\output;

use local_zoomattendance\local\settings;
use local_zoomattendance\local\status;
use local_zoomattendance\local\summary;

#[\PHPUnit\Framework\Attributes\CoversClass(renderer::class)]
/**
 * Tests for the attendance bars.
 *
 * @covers \local_zoomattendance\output\renderer
 */
final class renderer_test extends \advanced_testcase {
    /**
     * The plugin renderer.
     *
     * @return renderer
     */
    protected function renderer(): renderer {
        global $PAGE;
        $PAGE->set_url('/');
        return $PAGE->get_renderer('local_zoomattendance');
    }

    /**
     * A summary with one occurrence at the given percentage of an hour.
     *
     * @param float $pct
     * @return summary
     */
    protected function summary(float $pct): summary {
        $summary = new summary();
        $summary->add(
            (object) ['status' => status::PARTIAL, 'attendedsecs' => (int) round($pct * 36)],
            (object) ['denominator' => HOURSECS]
        );
        return $summary;
    }

    public function test_class_cells_get_a_bar_in_the_status_colour(): void {
        $this->resetAfterTest();
        $renderer = $this->renderer();

        $html = $renderer->status_cell(status::PARTIAL, 42.5);
        $this->assertStringContainsString('42.5%', $html);
        $this->assertStringContainsString('local-zoomattendance-statusbar', $html);
        $this->assertStringContainsString('bg-warning', $html);
        $this->assertStringContainsString('width: 42.5%;', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);

        $this->assertStringContainsString('bg-success', $renderer->status_bar(status::PRESENT, 100.0));
        $this->assertStringContainsString('bg-danger', $renderer->status_bar(status::ABSENT, 0.0));
        // Over 100 never overflows the cell.
        $this->assertStringContainsString('width: 100%;', $renderer->status_bar(status::PRESENT, 104.0));
        // No bar without a percentage, or for states such as excluded.
        $this->assertSame('', $renderer->status_bar(status::ABSENT, null));
        $this->assertSame('', $renderer->status_bar('excluded', 50.0));
    }

    public function test_overall_meter_is_coloured_by_threshold_with_a_present_line(): void {
        $this->resetAfterTest();
        $renderer = $this->renderer();
        // Site defaults: Present from 75 %, Partial from 50 %.
        $defaults = settings::site_defaults();

        $html = $renderer->overall_meter($this->summary(80), $defaults);
        $this->assertStringContainsString('80.0%', $html);
        $this->assertStringContainsString('bg-success', $html);
        $this->assertStringContainsString('left: 75%;', $html);
        $this->assertStringContainsString('The line marks the Present threshold, 75%.', $html);
        $this->assertStringContainsString('bg-warning', $renderer->overall_meter($this->summary(60), $defaults));
        $this->assertStringContainsString('bg-danger', $renderer->overall_meter($this->summary(30), $defaults));
        $large = $renderer->overall_meter($this->summary(30), $defaults, true);
        $this->assertStringContainsString('local-zoomattendance-meter-large', $large);

        // Teachers are marked against their own thresholds: 80 % is below their Present 90 %.
        $teacher = $renderer->overall_meter($this->summary(80), settings::teacher());
        $this->assertStringContainsString('bg-warning', $teacher);
        $this->assertStringContainsString('left: 90%;', $teacher);

        // When joined: the bar, and how many classes it covers.
        $stats = ['joined' => 1, 'expected' => 2] + \local_zoomattendance\local\teacher_summary::empty_stats();
        $joined = $renderer->joined_meter($this->summary(70), $stats, settings::teacher());
        $this->assertStringContainsString('70.0%', $joined);
        $this->assertStringContainsString('1 of 2 classes joined', $joined);
        $this->assertSame('–', $renderer->joined_meter(null, $stats, settings::teacher()));

        // Nothing evaluated, nothing shown.
        $this->assertSame('', $renderer->overall_meter(null, $defaults));
        $this->assertSame('', $renderer->overall_meter(new summary(), $defaults));
    }
}
