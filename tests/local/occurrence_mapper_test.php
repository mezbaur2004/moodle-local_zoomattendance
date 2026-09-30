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
 * Tests for the occurrence mapper.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Tests for the occurrence mapper.
 *
 * @covers \local_zoomattendance\local\occurrence_mapper
 */
#[\PHPUnit\Framework\Attributes\CoversClass(occurrence_mapper::class)]
final class occurrence_mapper_test extends \basic_testcase {
    /**
     * Build a session.
     *
     * @param int $id
     * @param int $start
     * @param int $end
     * @return \stdClass
     */
    protected static function session(int $id, int $start, int $end): \stdClass {
        return (object) ['id' => $id, 'uuid' => "u$id", 'start_time' => $start, 'end_time' => $end];
    }

    /**
     * Sessions map to the occurrence they overlap most, within margins.
     */
    public function test_map_to_scheduled(): void {
        $occurrences = [
            10 => (object) ['timestart' => 1000, 'timeend' => 2000],
            20 => (object) ['timestart' => 5000, 'timeend' => 6000],
        ];
        $sessions = [
            1 => self::session(1, 900, 2100), // Overlaps 10.
            2 => self::session(2, 4700, 4900), // Before 20, within the 300s early margin.
            3 => self::session(3, 3000, 3500), // Matches nothing.
            4 => self::session(4, 1950, 5400), // Overlaps 20 more than 10 once margins apply.
        ];
        $map = occurrence_mapper::map_to_scheduled($sessions, $occurrences, 300, 300);
        $this->assertSame([1 => 10, 2 => 20, 4 => 20], $map);
    }

    /**
     * Sessions close together form one inferred occurrence.
     */
    public function test_cluster(): void {
        $sessions = [
            3 => self::session(3, 10000, 11000),
            1 => self::session(1, 1000, 2000),
            2 => self::session(2, 2500, 3000),
        ];
        $clusters = array_values(occurrence_mapper::cluster($sessions, 600));
        $this->assertCount(2, $clusters);
        $this->assertSame([1000, 3000, [1, 2]], [$clusters[0]->timestart, $clusters[0]->timeend, $clusters[0]->detailsids]);
        $this->assertSame('s:' . sha1('u1'), $clusters[0]->key);
        $this->assertSame([3], $clusters[1]->detailsids);
    }
}
