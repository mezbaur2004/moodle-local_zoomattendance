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
 * Privacy provider.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_zoomattendance\local\settings;
use local_zoomattendance\local\status;

/**
 * Privacy provider. Results are derived from mod_zoom participant data and stored per Zoom
 * activity (module context).
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_zoomattendance_result', [
            'userid' => 'privacy:metadata:result:userid',
            'displayname' => 'privacy:metadata:result:displayname',
            'attendedsecs' => 'privacy:metadata:result:attendedsecs',
            'firstjoin' => 'privacy:metadata:result:firstjoin',
            'lastleave' => 'privacy:metadata:result:lastleave',
            'matchstrength' => 'privacy:metadata:result:matchstrength',
        ], 'privacy:metadata:result');
        return $collection;
    }

    /**
     * SQL joining results to their module context.
     *
     * @return string
     */
    protected static function context_join(): string {
        return "FROM {local_zoomattendance_result} r
                JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
                JOIN {course_modules} cm ON cm.instance = o.zoomid
                JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel";
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id " . self::context_join() . " WHERE r.userid = :userid",
            ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT r.userid " . self::context_join() . " WHERE ctx.id = :contextid",
            ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'contextid' => $context->id]
        );
    }

    /**
     * Export a user's attendance.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $sql = "SELECT r.id, r.attendedsecs, r.firstjoin, r.lastleave, r.matchstrength,
                           o.timestart, o.timeend, o.actualsecs, cm.id AS cmid
                    " . self::context_join() . "
                     WHERE ctx.id = :contextid AND r.userid = :userid
                  ORDER BY o.timestart";
            $rows = $DB->get_records_sql(
                $sql,
                ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'contextid' => $context->id, 'userid' => $userid]
            );
            if (!$rows) {
                continue;
            }
            $settings = settings::for_cm($context->instanceid);
            $data = [];
            foreach ($rows as $row) {
                $denominator = $settings->denominator_for($row);
                $data[] = (object) [
                    'occurrencestart' => transform::datetime($row->timestart),
                    'occurrenceend' => transform::datetime($row->timeend),
                    'attendedminutes' => round($row->attendedsecs / MINSECS, 1),
                    'firstjoin' => $row->firstjoin ? transform::datetime($row->firstjoin) : null,
                    'lastleave' => $row->lastleave ? transform::datetime($row->lastleave) : null,
                    'status' => status::evaluate(
                        (int) $row->attendedsecs,
                        $row->firstjoin === null ? null : (int) $row->firstjoin,
                        (int) $row->timestart,
                        $denominator,
                        $settings
                    ),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_zoomattendance')],
                (object) ['occurrences' => $data]
            );
        }
    }

    /**
     * Delete all results for a module context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_module) {
            return;
        }
        $DB->delete_records_select(
            'local_zoomattendance_result',
            'occurrenceid IN (' . self::occurrences_sql() . ')',
            ['modname' => 'zoom', 'cmid' => $context->instanceid]
        );
    }

    /**
     * Delete a user's results in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $DB->delete_records_select(
                'local_zoomattendance_result',
                'userid = :userid AND occurrenceid IN (' . self::occurrences_sql() . ')',
                ['modname' => 'zoom', 'cmid' => $context->instanceid, 'userid' => $userid]
            );
        }
    }

    /**
     * Delete results for several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_module || !$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'local_zoomattendance_result',
            "userid $insql AND occurrenceid IN (" . self::occurrences_sql() . ')',
            $params + ['modname' => 'zoom', 'cmid' => $context->instanceid]
        );
    }

    /**
     * Subquery selecting the occurrence ids of a zoom course module.
     *
     * @return string
     */
    protected static function occurrences_sql(): string {
        return "SELECT o.id
                  FROM {local_zoomattendance_occ} o
                  JOIN {course_modules} cm ON cm.instance = o.zoomid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
    }
}
