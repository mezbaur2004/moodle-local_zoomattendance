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
 * Rows of the teacher attendance list.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * One row per teacher and course, across the courses a viewer may see.
 */
class teacher_overview {
    /**
     * Courses with a Zoom activity where the user holds a capability.
     *
     * @param int $userid
     * @param string $capability
     * @param int $categoryid Limit to this category and its subcategories (0 for all).
     * @return \stdClass[] Course records keyed by id.
     */
    public static function courses(int $userid, string $capability, int $categoryid = 0): array {
        global $DB;
        $records = get_user_capability_course($capability, $userid, true, 'category', 'fullname') ?: [];
        $withzoom = array_flip($DB->get_fieldset_sql('SELECT DISTINCT course FROM {zoom}'));
        $categoryids = null;
        if ($categoryid && ($category = \core_course_category::get($categoryid, IGNORE_MISSING, true))) {
            $categoryids = array_flip(array_merge([$categoryid], $category->get_all_children_ids()));
        }
        $courses = [];
        // The records are a list, not keyed by course id.
        foreach ($records as $record) {
            $courseid = (int) $record->id;
            if ($courseid == SITEID || !isset($withzoom[$courseid])) {
                continue;
            }
            if ($categoryids !== null && !isset($categoryids[$record->category])) {
                continue;
            }
            $courses[$courseid] = get_course($courseid);
        }
        return $courses;
    }

    /**
     * Rows for a viewer: every teacher in the courses where they hold viewteacherreports, or
     * only themself in the courses where they hold viewownteacher.
     *
     * @param int $viewerid
     * @param bool $mine Only the viewer's own figures.
     * @param int $from Occurrences starting at or after this time.
     * @param int $to Occurrences starting before this time.
     * @param int $categoryid Limit to a category (0 for all).
     * @return \stdClass[] Each with user, course, stats and overall; sorted by teacher then course.
     */
    public static function rows(int $viewerid, bool $mine, int $from, int $to, int $categoryid = 0): array {
        $capability = $mine ? 'local/zoomattendance:viewownteacher' : 'local/zoomattendance:viewteacherreports';
        $rows = [];
        foreach (self::courses($viewerid, $capability, $mine ? 0 : $categoryid) as $course) {
            $summary = teacher_summary::build($course, $mine ? $viewerid : null, $from, $to);
            foreach ($summary->users as $userid => $user) {
                if (empty($summary->stats[$userid])) {
                    continue;
                }
                $rows[] = (object) [
                    'user' => $user,
                    'course' => $course,
                    'stats' => $summary->stats[$userid],
                    'overall' => $summary->overall[$userid] ?? null,
                ];
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp(fullname($a->user), fullname($b->user)) ?: strcmp($a->course->fullname, $b->course->fullname);
        });
        return $rows;
    }
}
