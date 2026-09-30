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
 * Interval arithmetic for attendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Clips join/leave intervals to a window and merges them with an interval union.
 *
 * All methods are pure so they can be unit tested without a database.
 */
class calculator {
    /**
     * Clip intervals to [$start, $end) and merge overlapping or touching ones.
     *
     * Empty and reversed intervals are dropped.
     *
     * @param array $intervals List of [join, leave] pairs (unix seconds).
     * @param int $start Window start.
     * @param int $end Window end.
     * @return array List of merged [start, end] pairs, sorted by start.
     */
    public static function clip_and_merge(array $intervals, int $start, int $end): array {
        $clipped = [];
        foreach ($intervals as $interval) {
            $a = max((int) $interval[0], $start);
            $b = min((int) $interval[1], $end);
            if ($b > $a) {
                $clipped[] = [$a, $b];
            }
        }

        usort($clipped, function ($x, $y) {
            return $x[0] <=> $y[0];
        });

        $merged = [];
        foreach ($clipped as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    /**
     * Summarise a participant's attendance inside a window.
     *
     * @param array $intervals List of [join, leave] pairs.
     * @param int $start Window start.
     * @param int $end Window end.
     * @return \stdClass With attendedsecs, firstjoin, lastleave (null when empty) and segments.
     */
    public static function summarise(array $intervals, int $start, int $end): \stdClass {
        $merged = self::clip_and_merge($intervals, $start, $end);
        $total = 0;
        foreach ($merged as $interval) {
            $total += $interval[1] - $interval[0];
        }

        return (object) [
            'attendedsecs' => $total,
            'firstjoin' => $merged ? $merged[0][0] : null,
            'lastleave' => $merged ? $merged[count($merged) - 1][1] : null,
            'segments' => count($merged),
        ];
    }

    /**
     * Attendance percentage, capped at 100.
     *
     * @param int $attendedsecs Attended seconds.
     * @param int $denominator Seconds the percentage is measured against.
     * @return float|null Null when the denominator is not positive.
     */
    public static function percentage(int $attendedsecs, int $denominator): ?float {
        if ($denominator <= 0) {
            return null;
        }
        return min(100.0, $attendedsecs * 100.0 / $denominator);
    }
}
