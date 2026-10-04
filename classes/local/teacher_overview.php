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
     * @param string|string[] $capability One capability, or several (any of them).
     * @param int $categoryid Limit to this category and its subcategories (0 for all).
     * @return \stdClass[] Course records keyed by id.
     */
    public static function courses(int $userid, $capability, int $categoryid = 0): array {
        $categoryids = null;
        if ($categoryid && ($category = \core_course_category::get($categoryid, IGNORE_MISSING, true))) {
            $categoryids = array_flip(array_merge([$categoryid], $category->get_all_children_ids()));
        }
        $courses = [];
        foreach (self::course_records($userid, $capability) as $courseid => $record) {
            if ($categoryids !== null && !isset($categoryids[$record->category])) {
                continue;
            }
            $courses[$courseid] = get_course($courseid);
        }
        return $courses;
    }

    /**
     * Whether the user holds a capability in at least one course with a Zoom activity.
     *
     * @param int $userid
     * @param string|string[] $capability One capability, or several (any of them).
     * @return bool
     */
    public static function has_courses(int $userid, $capability): bool {
        return (bool) self::course_records($userid, $capability);
    }

    /**
     * Categories worth filtering by: those holding the viewer's courses, and their parents.
     *
     * @param int $userid
     * @param string $capability
     * @return string[] category id => name with its path, in category order.
     */
    public static function categories(int $userid, string $capability): array {
        $wanted = [];
        foreach (self::course_records($userid, $capability) as $record) {
            $category = \core_course_category::get($record->category, IGNORE_MISSING, true);
            if ($category) {
                foreach (array_merge(explode('/', trim($category->path, '/')), [$category->id]) as $id) {
                    $wanted[(int) $id] = true;
                }
            }
        }
        return array_intersect_key(\core_course_category::make_categories_list(), $wanted);
    }

    /**
     * Start of a course's first Zoom class.
     *
     * @param int $courseid
     * @return int|null Null when the course has no occurrences yet.
     */
    public static function first_class(int $courseid): ?int {
        global $DB;
        $first = $DB->get_field_sql(
            "SELECT MIN(o.timestart)
               FROM {local_zoomattendance_occ} o
               JOIN {zoom} z ON z.id = o.zoomid
              WHERE z.course = :courseid",
            ['courseid' => $courseid]
        );
        return $first ? (int) $first : null;
    }

    /**
     * End of a day chosen in a date selector: the next midnight in the user's time zone, also
     * across a daylight saving change.
     *
     * @param int $day Midnight of the day, as a date selector returns it.
     * @return int
     */
    public static function day_end(int $day): int {
        return usergetmidnight($day + DAYSECS + 2 * HOURSECS);
    }

    /**
     * Course records (id, category) with a Zoom activity where the user holds a capability.
     *
     * @param int $userid
     * @param string|string[] $capability One capability, or several (any of them).
     * @return \stdClass[] Keyed by course id.
     */
    protected static function course_records(int $userid, $capability): array {
        global $DB;
        $withzoom = array_flip($DB->get_fieldset_sql('SELECT DISTINCT course FROM {zoom}'));
        $records = [];
        foreach ((array) $capability as $cap) {
            // The capability course list is a plain list, not keyed by course id.
            foreach (get_user_capability_course($cap, $userid, true, 'category', 'fullname') ?: [] as $record) {
                $courseid = (int) $record->id;
                if ($courseid != SITEID && isset($withzoom[$courseid])) {
                    $records[$courseid] = $record;
                }
            }
        }
        return $records;
    }

    /** @var string[] Capabilities that give the viewer's own view: themself, and the non-editing teachers. */
    public const MINE_CAPABILITIES = [
        'local/zoomattendance:viewownteacher',
        'local/zoomattendance:viewnoneditingteachers',
    ];

    /**
     * Rows for a viewer: every teacher in the courses where they hold viewteacherreports, or, in
     * their own view, themself where they hold viewownteacher and the non-editing teachers where
     * they hold viewnoneditingteachers.
     *
     * @param int $viewerid
     * @param bool $mine The viewer's own view.
     * @param int $from Occurrences starting at or after this time.
     * @param int $to Occurrences starting before this time.
     * @param int $categoryid Limit to a category (0 for all).
     * @return \stdClass[] Each with user, role, course, stats, overall and joined; sorted by teacher then course.
     */
    public static function rows(int $viewerid, bool $mine, int $from, int $to, int $categoryid = 0): array {
        $capability = $mine ? self::MINE_CAPABILITIES : 'local/zoomattendance:viewteacherreports';
        $rows = [];
        foreach (self::courses($viewerid, $capability, $mine ? 0 : $categoryid) as $course) {
            $userids = $mine ? teacher_access::visible_teachers(\context_course::instance($course->id), $viewerid, false) : null;
            $summary = teacher_summary::build($course, $userids, $from, $to);
            foreach ($summary->users as $userid => $user) {
                // Leave out teachers whose classes in range are all awaiting or reset: nothing to show.
                $stats = $summary->stats[$userid] ?? null;
                if (!$stats || (!$stats['expected'] && !$stats['excluded'])) {
                    continue;
                }
                $rows[] = (object) [
                    'user' => $user,
                    'role' => $summary->roles[$userid] ?? '',
                    'course' => $course,
                    'stats' => $summary->stats[$userid],
                    'overall' => $summary->overall[$userid] ?? null,
                    'joined' => $summary->joined[$userid] ?? null,
                ];
            }
        }
        usort($rows, function ($a, $b) {
            return strcmp(fullname($a->user), fullname($b->user)) ?: strcmp($a->course->fullname, $b->course->fullname);
        });
        return $rows;
    }
}
