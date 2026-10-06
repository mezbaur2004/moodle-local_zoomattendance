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
 * Upgrade steps for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_zoomattendance_upgrade($oldversion) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/local/zoomattendance/db/upgradelib.php');
    $dbman = $DB->get_manager();

    if ($oldversion < 2026100100) {
        // Define table local_zoomattendance_idmap to be created.
        $table = new xmldb_table('local_zoomattendance_idmap');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('identitykey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('displayname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('course_identity', XMLDB_INDEX_UNIQUE, ['courseid', 'identitykey']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100100, 'local', 'zoomattendance');
    }

    if ($oldversion < 2026100200) {
        // Record who excluded, included or set the window of an occurrence, and who made each identity link.
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        $table = new xmldb_table('local_zoomattendance_occ');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        $table = new xmldb_table('local_zoomattendance_idmap');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026100200, 'local', 'zoomattendance');
    }

    if ($oldversion < 2026100202) {
        // A teacher who joined is Partial from 10 % (was 50 %). Only a site still on the old
        // default is moved; a threshold an admin chose is kept.
        if (get_config('local_zoomattendance', 'teacherpartialpct') === '50') {
            set_config('teacherpartialpct', 10, 'local_zoomattendance');
        }
        upgrade_plugin_savepoint(true, 2026100202, 'local', 'zoomattendance');
    }

    if ($oldversion < 2026100602) {
        // Why an occurrence was excluded, when its expected users were frozen, and whether it was
        // restored from a backup.
        $table = new xmldb_table('local_zoomattendance_occ');
        $fields = [
            new xmldb_field('excludereason', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'usermodified'),
            new xmldb_field('rosterfrozen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'excludereason'),
            new xmldb_field('restored', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'rosterfrozen'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The students and teachers expected at each occurrence, frozen once it is over. The next
        // sync freezes every past occurrence from the current enrolments.
        $table = new xmldb_table('local_zoomattendance_roster');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('occurrenceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('occurrenceid', XMLDB_KEY_FOREIGN, ['occurrenceid'], 'local_zoomattendance_occ', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('occ_kind_user', XMLDB_INDEX_UNIQUE, ['occurrenceid', 'kind', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Teachers responsible for an activity.
        $table = new xmldb_table('local_zoomattendance_teacher');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('cmid_user', XMLDB_INDEX_UNIQUE, ['cmid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100602, 'local', 'zoomattendance');
    }

    if ($oldversion < 2026100700) {
        // The groups each user on a frozen list was in, for group views of past classes.
        $table = new xmldb_table('local_zoomattendance_roster');
        $field = new xmldb_field('groupids', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kind');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 0.4.0 froze every past class on its first sync, from whoever could see the activity
        // that day. Repair the classes of hidden activities that got an empty list.
        local_zoomattendance_unfreeze_late_empty();
        local_zoomattendance_clear_restored_recompute();
        // The next sync is a full pass, so every activity gets the new rules (activities sharing
        // a Zoom meeting, retention), not only those whose Zoom data changes.
        unset_config('syncstate', 'local_zoomattendance');

        upgrade_plugin_savepoint(true, 2026100700, 'local', 'zoomattendance');
    }

    return true;
}
