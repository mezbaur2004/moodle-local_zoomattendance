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
 * Test data generator for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates Zoom activities and mod_zoom report rows directly in the database.
 *
 * mod_zoom's own generator calls the Zoom API, so it cannot be used offline.
 */
class local_zoomattendance_generator extends component_generator_base {
    /** @var int Counter for unique meeting ids and uuids. */
    protected $counter = 0;

    /**
     * Reset the counter between tests.
     */
    public function reset() {
        $this->counter = 0;
    }

    /**
     * Create a Zoom activity (zoom row and course module) without calling the Zoom API.
     *
     * @param array $record Needs 'course'; zoom fields override the defaults.
     * @return cm_info
     */
    public function create_zoom(array $record): cm_info {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->counter++;
        $courseid = $record['course'];
        $zoom = (object) array_merge([
            'course' => $courseid,
            'name' => 'Zoom ' . $this->counter,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'meeting_id' => 900000 + $this->counter,
            'host_id' => 'host',
            'start_time' => 0,
            'duration' => 0,
            'recurring' => 0,
            'recurrence_type' => null,
            'webinar' => 0,
            'exists_on_zoom' => 1,
            'timemodified' => time(),
        ], array_diff_key($record, ['groupmode' => 1, 'visible' => 1]));
        $zoomid = $DB->insert_record('zoom', $zoom);

        $cm = (object) [
            'course' => $courseid,
            'module' => $DB->get_field('modules', 'id', ['name' => 'zoom'], MUST_EXIST),
            'instance' => $zoomid,
            'section' => 0,
            'visible' => $record['visible'] ?? 1,
            'visibleold' => 1,
            'groupmode' => $record['groupmode'] ?? NOGROUPS,
            'added' => time(),
        ];
        $cmid = add_course_module($cm);
        course_add_cm_to_section($courseid, $cmid, 0);
        rebuild_course_cache($courseid, true);
        return get_fast_modinfo($courseid)->get_cm($cmid);
    }

    /**
     * Create the calendar event mod_zoom writes for one occurrence of a recurring meeting.
     *
     * @param cm_info $cm
     * @param int $start
     * @param int $duration Seconds.
     * @param string|null $uuid Occurrence id.
     * @return stdClass The event row.
     */
    public function create_occurrence_event(cm_info $cm, int $start, int $duration, ?string $uuid = null): stdClass {
        global $DB;
        $this->counter++;
        $event = (object) [
            'name' => $cm->name,
            'description' => '',
            'format' => FORMAT_HTML,
            'courseid' => $cm->course,
            'groupid' => 0,
            'userid' => 0,
            'repeatid' => 0,
            'modulename' => 'zoom',
            'instance' => $cm->instance,
            'type' => 1,
            'eventtype' => 'zoom',
            'timestart' => $start,
            'timeduration' => $duration,
            'timesort' => $start,
            'visible' => 1,
            'uuid' => $uuid ?? ('occ' . $this->counter),
            'sequence' => 1,
            'timemodified' => time(),
        ];
        $event->id = $DB->insert_record('event', $event);
        return $event;
    }

    /**
     * Create a mod_zoom session (zoom_meeting_details row).
     *
     * @param cm_info $cm
     * @param int $start
     * @param int $end
     * @return stdClass
     */
    public function create_session(cm_info $cm, int $start, int $end): stdClass {
        global $DB;
        $this->counter++;
        $session = (object) [
            'uuid' => 'sess' . $this->counter,
            'meeting_id' => $DB->get_field('zoom', 'meeting_id', ['id' => $cm->instance]),
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'topic' => $cm->name,
            'total_minutes' => 0,
            'participants_count' => 0,
            'zoomid' => $cm->instance,
        ];
        $session->id = $DB->insert_record('zoom_meeting_details', $session);
        return $session;
    }

    /**
     * Create a participant segment (zoom_meeting_participants row).
     *
     * @param stdClass $session From create_session().
     * @param int $join
     * @param int $leave
     * @param array $record userid, name, user_email, uuid, zoomuserid.
     * @return stdClass
     */
    public function create_participant(stdClass $session, int $join, int $leave, array $record = []): stdClass {
        global $DB;
        $participant = (object) array_merge([
            'userid' => null,
            'zoomuserid' => 'zu' . ($record['userid'] ?? ($record['name'] ?? 'x')),
            'uuid' => null,
            'user_email' => null,
            'name' => 'Participant',
            'join_time' => $join,
            'leave_time' => $leave,
            'duration' => $leave - $join,
            'detailsid' => $session->id,
        ], $record);
        $participant->id = $DB->insert_record('zoom_meeting_participants', $participant);
        return $participant;
    }
}
