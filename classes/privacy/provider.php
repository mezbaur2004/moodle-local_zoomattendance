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
 * activity (module context); identity links are stored per course (course context). Who
 * excluded an occurrence, set its window or made a link is recorded with the change; deleting
 * a user's data keeps the change and removes their id.
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
        $collection->add_database_table('local_zoomattendance_idmap', [
            'userid' => 'privacy:metadata:idmap:userid',
            'displayname' => 'privacy:metadata:idmap:displayname',
            'timecreated' => 'privacy:metadata:idmap:timecreated',
            'usermodified' => 'privacy:metadata:idmap:usermodified',
        ], 'privacy:metadata:idmap');
        $collection->add_database_table('local_zoomattendance_occ', [
            'usermodified' => 'privacy:metadata:occ:usermodified',
            'timemodified' => 'privacy:metadata:occ:timemodified',
        ], 'privacy:metadata:occ');
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
     * SQL joining occurrences to their module context.
     *
     * @return string
     */
    protected static function occurrence_context_join(): string {
        return "FROM {local_zoomattendance_occ} o
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
            "SELECT ctx.id
               FROM {local_zoomattendance_idmap} i
               JOIN {context} ctx ON ctx.instanceid = i.courseid AND ctx.contextlevel = :ctxlevel
              WHERE i.userid = :userid OR i.usermodified = :usermodified",
            ['ctxlevel' => CONTEXT_COURSE, 'userid' => $userid, 'usermodified' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id " . self::context_join() . " WHERE r.userid = :userid",
            ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id " . self::occurrence_context_join() . " WHERE o.usermodified = :userid",
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
        if ($context instanceof \context_course) {
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {local_zoomattendance_idmap} WHERE courseid = :courseid",
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT usermodified FROM {local_zoomattendance_idmap} WHERE courseid = :courseid AND usermodified > 0",
                ['courseid' => $context->instanceid]
            );
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT r.userid " . self::context_join() . " WHERE ctx.id = :contextid",
            ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'contextid' => $context->id]
        );
        $userlist->add_from_sql(
            'usermodified',
            "SELECT o.usermodified " . self::occurrence_context_join() . " WHERE ctx.id = :contextid AND o.usermodified > 0",
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
            if ($context instanceof \context_course) {
                self::export_links($context, $userid);
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $sql = "SELECT r.id, r.attendedsecs, r.firstjoin, r.lastleave, r.matchstrength,
                           o.timestart, o.timeend, o.actualsecs, cm.id AS cmid
                    " . self::context_join() . "
                     WHERE ctx.id = :contextid AND r.userid = :userid
                  ORDER BY o.timestart";
            self::export_changes($context, $userid);
            $rows = $DB->get_records_sql(
                $sql,
                ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'contextid' => $context->id, 'userid' => $userid]
            );
            if (!$rows) {
                continue;
            }
            $settings = settings::for_cm($context->instanceid);
            // A tracked teacher's export adds their status against the teacher thresholds.
            $teacher = settings::teacher_tracking() && has_capability('local/zoomattendance:betrackedteacher', $context, $userid)
                ? settings::teacher() : null;
            $data = [];
            foreach ($rows as $row) {
                $firstjoin = $row->firstjoin === null ? null : (int) $row->firstjoin;
                $record = (object) [
                    'occurrencestart' => transform::datetime($row->timestart),
                    'occurrenceend' => transform::datetime($row->timeend),
                    'attendedminutes' => round($row->attendedsecs / MINSECS, 1),
                    'firstjoin' => $row->firstjoin ? transform::datetime($row->firstjoin) : null,
                    'lastleave' => $row->lastleave ? transform::datetime($row->lastleave) : null,
                    'status' => status::evaluate(
                        (int) $row->attendedsecs,
                        $firstjoin,
                        (int) $row->timestart,
                        $settings->denominator_for($row),
                        $settings
                    ),
                ];
                if ($teacher) {
                    $record->teacherstatus = status::evaluate(
                        (int) $row->attendedsecs,
                        $firstjoin,
                        (int) $row->timestart,
                        $teacher->denominator_for($row),
                        $teacher
                    );
                }
                $data[] = $record;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_zoomattendance')],
                (object) ['occurrences' => $data]
            );
        }
    }

    /**
     * Export the identity links naming a user in a course, and the links they made.
     *
     * @param \context_course $context
     * @param int $userid
     */
    protected static function export_links(\context_course $context, int $userid): void {
        global $DB;
        $links = $DB->get_records(
            'local_zoomattendance_idmap',
            ['courseid' => $context->instanceid, 'userid' => $userid],
            'timecreated'
        );
        if ($links) {
            $data = [];
            foreach ($links as $link) {
                $data[] = (object) [
                    'zoomname' => $link->displayname,
                    'linkedon' => transform::datetime($link->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_zoomattendance'), get_string('identitylinks', 'local_zoomattendance')],
                (object) ['links' => $data]
            );
        }
        $made = $DB->get_records(
            'local_zoomattendance_idmap',
            ['courseid' => $context->instanceid, 'usermodified' => $userid],
            'timecreated'
        );
        if ($made) {
            $data = [];
            foreach ($made as $link) {
                $data[] = (object) [
                    'zoomname' => $link->displayname,
                    'linkedtoyourself' => transform::yesno((int) $link->userid === $userid),
                    'linkedon' => transform::datetime($link->timecreated),
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_zoomattendance'), get_string('linksmade', 'local_zoomattendance')],
                (object) ['links' => $data]
            );
        }
    }

    /**
     * Export the occurrence changes (exclusions, windows) a user made in a Zoom activity.
     *
     * @param \context_module $context
     * @param int $userid
     */
    protected static function export_changes(\context_module $context, int $userid): void {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT o.id, o.timestart, o.timeend, o.status, o.source, o.timemodified
             " . self::occurrence_context_join() . "
              WHERE ctx.id = :contextid AND o.usermodified = :userid
           ORDER BY o.timestart",
            ['modname' => 'zoom', 'ctxlevel' => CONTEXT_MODULE, 'contextid' => $context->id, 'userid' => $userid]
        );
        if (!$rows) {
            return;
        }
        $data = [];
        foreach ($rows as $row) {
            $data[] = (object) [
                'occurrencestart' => transform::datetime($row->timestart),
                'occurrenceend' => transform::datetime($row->timeend),
                'excluded' => transform::yesno((int) $row->status === \local_zoomattendance\local\sync::STATUS_EXCLUDED),
                'source' => $row->source,
                'changedon' => transform::datetime($row->timemodified),
            ];
        }
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_zoomattendance'), get_string('changesmade', 'local_zoomattendance')],
            (object) ['occurrences' => $data]
        );
    }

    /**
     * Delete all results for a module context, or all identity links for a course context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context instanceof \context_course) {
            $DB->delete_records('local_zoomattendance_idmap', ['courseid' => $context->instanceid]);
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ['modname' => 'zoom', 'cmid' => $context->instanceid];
        $DB->delete_records_select('local_zoomattendance_result', 'occurrenceid IN (' . self::occurrences_sql() . ')', $params);
        $DB->set_field_select('local_zoomattendance_occ', 'usermodified', 0, 'id IN (' . self::occurrences_sql() . ')', $params);
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
            if ($context instanceof \context_course) {
                $DB->delete_records('local_zoomattendance_idmap', ['courseid' => $context->instanceid, 'userid' => $userid]);
                $DB->set_field(
                    'local_zoomattendance_idmap',
                    'usermodified',
                    0,
                    ['courseid' => $context->instanceid, 'usermodified' => $userid]
                );
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $params = ['modname' => 'zoom', 'cmid' => $context->instanceid, 'userid' => $userid];
            $DB->delete_records_select(
                'local_zoomattendance_result',
                'userid = :userid AND occurrenceid IN (' . self::occurrences_sql() . ')',
                $params
            );
            $DB->set_field_select(
                'local_zoomattendance_occ',
                'usermodified',
                0,
                'usermodified = :userid AND id IN (' . self::occurrences_sql() . ')',
                $params
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
        if (!$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        if ($context instanceof \context_course) {
            $DB->delete_records_select(
                'local_zoomattendance_idmap',
                "userid $insql AND courseid = :courseid",
                $params + ['courseid' => $context->instanceid]
            );
            $DB->set_field_select(
                'local_zoomattendance_idmap',
                'usermodified',
                0,
                "usermodified $insql AND courseid = :courseid",
                $params + ['courseid' => $context->instanceid]
            );
            return;
        }
        if (!$context instanceof \context_module) {
            return;
        }
        $params += ['modname' => 'zoom', 'cmid' => $context->instanceid];
        $DB->delete_records_select(
            'local_zoomattendance_result',
            "userid $insql AND occurrenceid IN (" . self::occurrences_sql() . ')',
            $params
        );
        $DB->set_field_select(
            'local_zoomattendance_occ',
            'usermodified',
            0,
            "usermodified $insql AND id IN (" . self::occurrences_sql() . ')',
            $params
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
