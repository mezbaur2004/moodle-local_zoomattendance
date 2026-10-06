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
 * Restore of Zoom attendance data with courses and Zoom activities.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores what backup_local_zoomattendance_plugin backed up.
 *
 * Restored classes are marked restored: the Zoom sessions they came from are not in the backup,
 * so the sync keeps their stored results instead of recomputing them from nothing.
 */
class restore_local_zoomattendance_plugin extends restore_local_plugin {
    /** @var int[] Restored class id => id in the backup, until attached to the activity. */
    protected $occurrences = [];
    /** @var string[] Class id in the backup => its key. */
    protected $occurrencekeys = [];

    /**
     * Paths of one Zoom activity's data.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element($this->get_namefor('setting'), $this->get_pathfor('/setting')),
            new restore_path_element($this->get_namefor('teacher'), $this->get_pathfor('/teachers/teacher')),
            new restore_path_element($this->get_namefor('occurrence'), $this->get_pathfor('/occurrences/occurrence')),
            new restore_path_element($this->get_namefor('result'), $this->get_pathfor('/occurrences/occurrence/results/result')),
            new restore_path_element($this->get_namefor('roster'), $this->get_pathfor('/occurrences/occurrence/rosters/roster')),
        ];
    }

    /**
     * Paths of the course's data.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element($this->get_namefor('link'), $this->get_pathfor('/links/link')),
        ];
    }

    /**
     * Per-activity settings.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_setting($data) {
        global $DB;
        $data = (object) $data;
        $cmid = (int) $this->task->get_moduleid();
        if ($DB->record_exists('local_zoomattendance_setting', ['cmid' => $cmid])) {
            return;
        }
        $data->cmid = $cmid;
        unset($data->id);
        $DB->insert_record('local_zoomattendance_setting', $data);
    }

    /**
     * A responsible teacher.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_teacher($data) {
        global $DB;
        $data = (object) $data;
        $userid = $this->get_mappingid('user', $data->userid);
        $cmid = (int) $this->task->get_moduleid();
        if (!$userid || $DB->record_exists('local_zoomattendance_teacher', ['cmid' => $cmid, 'userid' => $userid])) {
            return;
        }
        $DB->insert_record('local_zoomattendance_teacher', (object) [
            'cmid' => $cmid,
            'userid' => $userid,
            'timecreated' => (int) $data->timecreated,
            'usermodified' => (int) $this->get_mappingid('user', $data->usermodified, 0),
        ]);
    }

    /**
     * A class that is over, with its stored figures.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_occurrence($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        // The Zoom activity itself is restored after this step: the class is attached to it in
        // after_restore_module(), under a temporary key that cannot collide meanwhile.
        $this->occurrencekeys[$oldid] = $data->occurrencekey;
        $data->zoomid = 0;
        $data->occurrencekey = 'tmp:' . sha1($this->get_restoreid() . '|' . $oldid);
        $data->usermodified = (int) $this->get_mappingid('user', $data->usermodified, 0);
        $data->restored = time();
        // Times stay as recorded, even when the course dates move: attendance is a record of
        // when the class took place.
        unset($data->id);
        $newid = $DB->insert_record('local_zoomattendance_occ', $data);
        $this->set_mapping('local_zoomattendance_occurrence', $oldid, $newid);
        $this->occurrences[$newid] = $oldid;
    }

    /**
     * Attach the restored classes to the restored Zoom activity.
     */
    public function after_restore_module() {
        global $DB;
        $zoomid = (int) $this->task->get_activityid();
        foreach ($this->occurrences as $newid => $oldid) {
            // A key of its own: a restored class never takes the key of a scheduled one. The sync
            // leaves out a scheduled class at the same time instead (sync::snapshot_schedule()).
            $key = 'r:' . sha1($this->get_restoreid() . '|' . $oldid . '|' . $this->occurrencekeys[$oldid]);
            $DB->update_record('local_zoomattendance_occ', (object) ['id' => $newid, 'zoomid' => $zoomid, 'occurrencekey' => $key]);
        }
        $this->occurrences = [];
        $this->occurrencekeys = [];
    }

    /**
     * A participant's stored figures in a class.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_result($data) {
        global $DB;
        $data = (object) $data;
        $data->occurrenceid = $this->get_new_parentid('local_zoomattendance_occurrence');
        if (!empty($data->userid)) {
            $userid = $this->get_mappingid('user', $data->userid);
            if (!$userid) {
                return;
            }
            $data->userid = $userid;
            $data->identitykey = 'u:' . $userid;
        } else {
            $data->userid = null;
        }
        unset($data->id);
        $DB->insert_record('local_zoomattendance_result', $data);
    }

    /**
     * A user expected at a class.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_roster($data) {
        global $DB;
        $data = (object) $data;
        $userid = $this->get_mappingid('user', $data->userid);
        if (!$userid) {
            return;
        }
        // Groups the user was in, as restored (groups that were not restored are left out).
        $groupids = [];
        foreach (explode(',', (string) ($data->groupids ?? '')) as $groupid) {
            if ((int) $groupid && ($new = $this->get_mappingid('group', (int) $groupid))) {
                $groupids[] = (int) $new;
            }
        }
        $DB->insert_record('local_zoomattendance_roster', (object) [
            'occurrenceid' => $this->get_new_parentid('local_zoomattendance_occurrence'),
            'userid' => $userid,
            'kind' => $data->kind,
            'groupids' => $groupids ? ',' . implode(',', $groupids) . ',' : '',
            'timecreated' => (int) $data->timecreated,
        ]);
    }

    /**
     * An identity link of the course.
     *
     * @param array $data
     */
    public function process_local_zoomattendance_link($data) {
        global $DB;
        $data = (object) $data;
        $userid = $this->get_mappingid('user', $data->userid);
        $courseid = (int) $this->task->get_courseid();
        $exists = $DB->record_exists('local_zoomattendance_idmap', ['courseid' => $courseid, 'identitykey' => $data->identitykey]);
        if (!$userid || $exists) {
            return;
        }
        $DB->insert_record('local_zoomattendance_idmap', (object) [
            'courseid' => $courseid,
            'identitykey' => $data->identitykey,
            'userid' => $userid,
            'displayname' => $data->displayname,
            'timecreated' => (int) $data->timecreated,
            'usermodified' => (int) $this->get_mappingid('user', $data->usermodified, 0),
        ]);
    }
}
