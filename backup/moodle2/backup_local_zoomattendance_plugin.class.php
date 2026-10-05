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
 * Backup of Zoom attendance data with courses and Zoom activities.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds Zoom attendance data to course and activity backups.
 *
 * Settings and responsible teachers always go with a Zoom activity. With user data, its
 * classes go too, with their results and frozen lists of expected users, and the course's
 * identity links: mod_zoom does not back up its meeting reports, so these are the only copy of
 * the attendance in the backup.
 */
class backup_local_zoomattendance_plugin extends backup_local_plugin {
    /**
     * Data of one Zoom activity.
     *
     * @return backup_plugin_element|null
     */
    protected function define_module_plugin_structure() {
        if ($this->task->get_modulename() !== 'zoom') {
            return null;
        }
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $setting = new backup_nested_element('setting', ['id'], [
            'enabled', 'presentpct', 'latepct', 'lategracemins', 'denominator', 'timemodified',
        ]);
        $wrapper->add_child($setting);
        $setting->set_source_table('local_zoomattendance_setting', ['cmid' => backup::VAR_MODID]);

        if (!$this->get_setting_value('userinfo')) {
            return $plugin;
        }

        $teachers = new backup_nested_element('teachers');
        $teacher = new backup_nested_element('teacher', ['id'], ['userid', 'timecreated', 'usermodified']);
        $wrapper->add_child($teachers);
        $teachers->add_child($teacher);
        $teacher->set_source_table('local_zoomattendance_teacher', ['cmid' => backup::VAR_MODID]);
        $teacher->annotate_ids('user', 'userid');
        $teacher->annotate_ids('user', 'usermodified');

        $occurrences = new backup_nested_element('occurrences');
        $occurrence = new backup_nested_element('occurrence', ['id'], [
            'occurrencekey', 'source', 'timestart', 'timeend', 'status', 'actualsecs', 'timecomputed',
            'timecreated', 'timemodified', 'usermodified', 'excludereason', 'rosterfrozen',
        ]);
        $results = new backup_nested_element('results');
        $result = new backup_nested_element('result', ['id'], [
            'userid', 'identitykey', 'displayname', 'attendedsecs', 'firstjoin', 'lastleave', 'segments',
            'matchstrength', 'timemodified',
        ]);
        $rosters = new backup_nested_element('rosters');
        $roster = new backup_nested_element('roster', ['id'], ['userid', 'kind', 'timecreated']);
        $wrapper->add_child($occurrences);
        $occurrences->add_child($occurrence);
        $occurrence->add_child($results);
        $results->add_child($result);
        $occurrence->add_child($rosters);
        $rosters->add_child($roster);

        // Only classes that are over: future ones are rebuilt from the restored activity's schedule.
        $occurrence->set_source_sql(
            "SELECT *
               FROM {local_zoomattendance_occ}
              WHERE zoomid = ? AND timeend <= ?",
            [backup::VAR_ACTIVITYID, backup_helper::is_sqlparam(time())]
        );
        $result->set_source_table('local_zoomattendance_result', ['occurrenceid' => backup::VAR_PARENTID]);
        $roster->set_source_table('local_zoomattendance_roster', ['occurrenceid' => backup::VAR_PARENTID]);
        $occurrence->annotate_ids('user', 'usermodified');
        $result->annotate_ids('user', 'userid');
        $roster->annotate_ids('user', 'userid');
        return $plugin;
    }

    /**
     * The course's identity links, with user data.
     *
     * @return backup_plugin_element|null
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        if (!$this->get_setting_value('users')) {
            return $plugin;
        }
        $links = new backup_nested_element('links');
        $link = new backup_nested_element('link', ['id'], ['identitykey', 'userid', 'displayname', 'timecreated', 'usermodified']);
        $wrapper->add_child($links);
        $links->add_child($link);
        $link->set_source_table('local_zoomattendance_idmap', ['courseid' => backup::VAR_COURSEID]);
        $link->annotate_ids('user', 'userid');
        $link->annotate_ids('user', 'usermodified');
        return $plugin;
    }
}
