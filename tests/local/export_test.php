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
 * Tests for safe downloads.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(export::class)]
/**
 * Tests for safe downloads.
 *
 * @covers \local_zoomattendance\local\export
 */
final class export_test extends \advanced_testcase {
    public function test_formula_text_is_neutralised(): void {
        foreach (['=HYPERLINK("x")', '+1', '-2+3', '@SUM(A1)', "\tcmd", "\rcmd"] as $value) {
            $this->assertSame("'" . $value, export::cell($value));
        }
        // Ordinary text, numbers and empty cells stay as they are.
        $this->assertSame('Amy Student', export::cell('Amy Student'));
        $this->assertSame('', export::cell(''));
        $this->assertSame(-5, export::cell(-5));
        $this->assertSame(42.5, export::cell(42.5));
        $this->assertNull(export::cell(null));
    }

    public function test_headings_are_neutralised_too(): void {
        // An activity name a teacher chose ends up in a manager's download heading.
        [$columns, $rows] = export::safe(['o1' => '=HYPERLINK("http://x")', 'name' => 'Name'], [['o1' => '-', 'name' => 'Amy']]);
        $this->assertSame(['o1' => '\'=HYPERLINK("http://x")', 'name' => 'Name'], $columns);
        $this->assertSame([['o1' => "'-", 'name' => 'Amy']], $rows);
    }
}
