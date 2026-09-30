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
 * Attendance status evaluation.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Turns attended time into present / partial / absent using the effective thresholds.
 */
class status {
    /** @var string Present. */
    public const PRESENT = 'present';
    /** @var string Joined, but not present: too little time or joined after the late period. */
    public const PARTIAL = 'partial';
    /** @var string Absent. */
    public const ABSENT = 'absent';
    /** @var string The occurrence has no usable denominator. */
    public const INVALID = 'invalid';

    /**
     * Evaluate one participant for one occurrence.
     *
     * ABSENT below latepct; PRESENT at or above presentpct when the first join is within the
     * grace period; PARTIAL otherwise.
     *
     * @param int $attendedsecs Attended seconds (0 when there is no result).
     * @param int|null $firstjoin First clipped join, null when there is no result.
     * @param int $windowstart Occurrence window start.
     * @param int $denominator Seconds the percentage is measured against.
     * @param settings $settings Effective settings.
     * @return string One of the class constants.
     */
    public static function evaluate(
        int $attendedsecs,
        ?int $firstjoin,
        int $windowstart,
        int $denominator,
        settings $settings
    ): string {
        $pct = calculator::percentage($attendedsecs, $denominator);
        if ($pct === null) {
            return self::INVALID;
        }
        if ($firstjoin === null || $pct < $settings->latepct) {
            return self::ABSENT;
        }
        if ($pct >= $settings->presentpct && $firstjoin <= $windowstart + $settings->lategracemins * MINSECS) {
            return self::PRESENT;
        }
        return self::PARTIAL;
    }
}
