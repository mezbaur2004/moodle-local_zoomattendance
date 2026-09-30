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
 * Read-only access to mod_zoom data.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local\source;

/**
 * All SQL against mod_zoom's tables (and the calendar events it writes) lives here, so a
 * mod_zoom schema change only affects this class. See docs/ARCHITECTURE.md, Part A.
 */
class zoom_source {
    /**
     * Zoom instances with their course module and any per-activity override.
     *
     * @param int|null $courseid Limit to one course.
     * @param int|null $zoomid Limit to one instance.
     * @return \stdClass[] Keyed by zoom id. Override columns are prefixed with "s_".
     */
    public static function get_instances(?int $courseid = null, ?int $zoomid = null): array {
        global $DB;
        $where = ['cm.deletioninprogress = 0'];
        $params = ['modname' => 'zoom'];
        if ($courseid !== null) {
            $where[] = 'z.course = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($zoomid !== null) {
            $where[] = 'z.id = :zoomid';
            $params['zoomid'] = $zoomid;
        }
        $sql = "SELECT z.id, z.course, z.name, z.meeting_id, z.start_time, z.duration, z.recurring,
                       z.recurrence_type, cm.id AS cmid,
                       s.id AS s_id, s.enabled AS s_enabled, s.presentpct AS s_presentpct, s.latepct AS s_latepct,
                       s.lategracemins AS s_lategracemins, s.denominator AS s_denominator
                  FROM {zoom} z
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = z.id
             LEFT JOIN {local_zoomattendance_setting} s ON s.cmid = cm.id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY z.id";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Extract the override row from a get_instances() record.
     *
     * @param \stdClass $instance
     * @return \stdClass|null
     */
    public static function override_from_instance(\stdClass $instance): ?\stdClass {
        if (empty($instance->s_id)) {
            return null;
        }
        return (object) [
            'enabled' => $instance->s_enabled,
            'presentpct' => $instance->s_presentpct,
            'latepct' => $instance->s_latepct,
            'lategracemins' => $instance->s_lategracemins,
            'denominator' => $instance->s_denominator,
        ];
    }

    /**
     * Calendar events mod_zoom wrote for the occurrences of a recurring meeting.
     *
     * mod_zoom stores occurrences only here (event.uuid = Zoom occurrence_id) and may delete
     * them later, so callers snapshot them.
     *
     * @param int $zoomid
     * @return \stdClass[] With uuid, timestart, timeduration.
     */
    public static function get_calendar_occurrences(int $zoomid): array {
        global $DB;
        $select = "modulename = :modname AND instance = :zoomid AND uuid IS NOT NULL AND uuid <> ''";
        return $DB->get_records_select(
            'event',
            $select,
            ['modname' => 'zoom', 'zoomid' => $zoomid],
            'timestart',
            'id, uuid, timestart, timeduration'
        );
    }

    /**
     * Sessions of an instance with a fingerprint of their participant rows.
     *
     * @param int $zoomid
     * @return \stdClass[] Keyed by details id, with uuid, start_time, end_time, fingerprint.
     */
    public static function get_sessions(int $zoomid): array {
        global $DB;
        $sql = "SELECT d.id, d.uuid, d.start_time, d.end_time,
                       COUNT(p.id) AS cnt, COALESCE(MIN(p.id), 0) AS minid, COALESCE(MAX(p.id), 0) AS maxid,
                       COALESCE(SUM(COALESCE(p.userid, 0)), 0) AS usersum
                  FROM {zoom_meeting_details} d
             LEFT JOIN {zoom_meeting_participants} p ON p.detailsid = d.id
                 WHERE d.zoomid = :zoomid
              GROUP BY d.id, d.uuid, d.start_time, d.end_time";
        $sessions = $DB->get_records_sql($sql, ['zoomid' => $zoomid]);
        foreach ($sessions as $session) {
            $session->fingerprint = sha1(implode('|', [$session->start_time, $session->end_time, $session->cnt,
                $session->minid, $session->maxid, $session->usersum]));
        }
        return $sessions;
    }

    /**
     * Participant segments for a set of sessions.
     *
     * @param int[] $detailsids
     * @return \stdClass[]
     */
    public static function get_participants(array $detailsids): array {
        global $DB;
        if (!$detailsids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($detailsids, SQL_PARAMS_NAMED);
        $sql = "SELECT id, detailsid, userid, zoomuserid, uuid, user_email, name, join_time, leave_time
                  FROM {zoom_meeting_participants}
                 WHERE detailsid $insql
              ORDER BY join_time, id";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Other activities sharing this meeting id. mod_zoom attaches report data to only one of
     * them (get_meeting_reports uses IGNORE_MULTIPLE).
     *
     * @param \stdClass $instance A get_instances() record.
     * @return int Number of other zoom rows with the same non-zero meeting id.
     */
    public static function count_shared_meeting_id(\stdClass $instance): int {
        global $DB;
        if (empty($instance->meeting_id)) {
            return 0;
        }
        return $DB->count_records_select(
            'zoom',
            'meeting_id = :mid AND id <> :id',
            ['mid' => $instance->meeting_id, 'id' => $instance->id]
        );
    }
}
