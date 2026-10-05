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
 * Per-class headcount of expected students.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Out of the students expected at a class, how many were present, partial and absent. Present
 * overall counts both present and partial: every student who attended.
 *
 * Counts come from the course summary's cells, so they always agree with the course report.
 */
class headcount {
    /**
     * An empty count.
     *
     * @return int[] With expected, overall (present + partial), present, partial and absent.
     */
    public static function empty(): array {
        return ['expected' => 0, 'overall' => 0, status::PRESENT => 0, status::PARTIAL => 0, status::ABSENT => 0];
    }

    /**
     * Counts as string placeholders.
     *
     * @param int[] $counts See empty().
     * @return \stdClass With expected, overall, present, partial and absent.
     */
    public static function string_data(array $counts): \stdClass {
        return (object) [
            'expected' => $counts['expected'],
            'overall' => $counts['overall'],
            'present' => $counts[status::PRESENT],
            'partial' => $counts[status::PARTIAL],
            'absent' => $counts[status::ABSENT],
        ];
    }

    /**
     * Counts per class of a course summary.
     *
     * @param course_summary $summary
     * @return array[] occurrence id => counts, see empty().
     */
    public static function from_summary(course_summary $summary): array {
        return self::from_cells($summary->cells);
    }

    /**
     * Counts per class from cells.
     *
     * @param \stdClass[][] $cells userid => occurrence id => row with status.
     * @return array[] occurrence id => counts, see empty().
     */
    public static function from_cells(array $cells): array {
        $counts = [];
        foreach ($cells as $row) {
            foreach ($row as $occurrenceid => $cell) {
                $counts[$occurrenceid] = $counts[$occurrenceid] ?? self::empty();
                $counts[$occurrenceid]['expected']++;
                if (isset($counts[$occurrenceid][$cell->status])) {
                    $counts[$occurrenceid][$cell->status]++;
                }
                // Present overall: everyone who attended, present or partial.
                if ($cell->status === status::PRESENT || $cell->status === status::PARTIAL) {
                    $counts[$occurrenceid]['overall']++;
                }
            }
        }
        return $counts;
    }

    /**
     * The students the current user may see in a course: every student, or in a separate-groups
     * course without access to all groups, those in the user's own groups.
     *
     * @param \stdClass $course
     * @return array|null With summary (the last course_summary built, or null), and cells and
     *     overall merged by student, so one in two groups counts once. Null when the user views
     *     no reports there.
     */
    public static function visible_cells(\stdClass $course): ?array {
        global $USER;
        $context = \context_course::instance($course->id);
        if (!has_capability('local/zoomattendance:viewreports', $context)) {
            return null;
        }
        $groupids = [0];
        if (
            groups_get_course_groupmode($course) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context)
        ) {
            $groupids = array_keys(groups_get_all_groups($course->id, $USER->id));
        }
        $summary = null;
        $cells = [];
        $overall = [];
        foreach ($groupids as $groupid) {
            $summary = course_summary::build($course, (int) $groupid);
            $cells = $summary->cells + $cells;
            $overall = $summary->overall + $overall;
        }
        return ['summary' => $summary, 'cells' => $cells, 'overall' => $overall];
    }

    /**
     * Counts per class for the students the current user may see in a course.
     *
     * @param \stdClass $course
     * @return array[] occurrence id => counts, see empty(); empty when the user views no reports.
     */
    public static function for_viewer(\stdClass $course): array {
        $visible = self::visible_cells($course);
        return $visible ? self::from_cells($visible['cells']) : [];
    }
}
