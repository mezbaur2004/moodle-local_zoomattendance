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
 * Which teachers' attendance a viewer may see.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Teacher visibility in a course.
 *
 * viewteacherreports sees every teacher. viewownteacher sees oneself. viewnoneditingteachers
 * (editing teachers by default) sees the non-editing teachers: those who cannot manage the
 * course's activities. Nobody without viewteacherreports sees another editing teacher.
 */
class teacher_access {
    /** @var string Capability that tells editing teachers from non-editing ones. */
    public const EDITING_CAPABILITY = 'moodle/course:manageactivities';

    /**
     * Whether a teacher is an editing teacher in the course.
     *
     * @param \context_course $context
     * @param int $userid
     * @return bool
     */
    public static function is_editing(\context_course $context, int $userid): bool {
        return has_capability(self::EDITING_CAPABILITY, $context, $userid);
    }

    /**
     * Whether the viewer may see any teacher attendance in the course.
     *
     * @param \context_course $context
     * @param int|null $viewerid Defaults to the current user.
     * @return bool
     */
    public static function can_view_any(\context_course $context, ?int $viewerid = null): bool {
        return has_any_capability([
            'local/zoomattendance:viewteacherreports',
            'local/zoomattendance:viewownteacher',
            'local/zoomattendance:viewnoneditingteachers',
        ], $context, $viewerid);
    }

    /**
     * Teachers the viewer may see in the course.
     *
     * @param \context_course $context
     * @param int|null $viewerid Defaults to the current user.
     * @param bool $withall Whether viewteacherreports counts; false for the viewer's own view.
     * @return int[]|null User ids, or null for every teacher.
     */
    public static function visible_teachers(\context_course $context, ?int $viewerid = null, bool $withall = true): ?array {
        global $USER;
        $viewerid = $viewerid ?? (int) $USER->id;
        if ($withall && has_capability('local/zoomattendance:viewteacherreports', $context, $viewerid)) {
            return null;
        }
        $ids = [];
        if (has_capability('local/zoomattendance:viewownteacher', $context, $viewerid)) {
            $ids[$viewerid] = $viewerid;
        }
        if (has_capability('local/zoomattendance:viewnoneditingteachers', $context, $viewerid)) {
            $teachers = get_users_by_capability($context, 'local/zoomattendance:betrackedteacher', 'u.id');
            $editing = get_users_by_capability($context, self::EDITING_CAPABILITY, 'u.id');
            foreach (array_keys(array_diff_key($teachers, $editing)) as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Whether the viewer may see a user's Zoom times, as far as teacher visibility goes.
     *
     * Users who are not teachers in the course are always allowed here; the reports' own
     * capabilities decide about them.
     *
     * @param \context_course $context
     * @param int $userid
     * @param int|null $viewerid Defaults to the current user.
     * @return bool
     */
    public static function can_view(\context_course $context, int $userid, ?int $viewerid = null): bool {
        global $USER;
        $viewerid = $viewerid ?? (int) $USER->id;
        return $userid === $viewerid
            || !has_capability('local/zoomattendance:betrackedteacher', $context, $userid)
            || has_capability('local/zoomattendance:viewteacherreports', $context, $viewerid)
            || (has_capability('local/zoomattendance:viewnoneditingteachers', $context, $viewerid)
                && !self::is_editing($context, $userid));
    }
}
