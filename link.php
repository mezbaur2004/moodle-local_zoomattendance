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
 * Link unmatched Zoom participants to users, and manage a course's links.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/user/lib.php');

use local_zoomattendance\form\link_identity;
use local_zoomattendance\local\manual;

$id = required_param('id', PARAM_INT);
// Keys look like "z:<sha1>"; no PARAM type keeps the colon, so read it raw and validate it below.
$key = optional_param('key', '', PARAM_RAW_TRIMMED);
$occurrenceid = optional_param('occurrence', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$coursecontext = context_course::instance($course->id);
// Links apply to every Zoom activity in the course.
require_capability('local/zoomattendance:manage', $coursecontext);

$url = new moodle_url('/local/zoomattendance/link.php', ['id' => $cm->id]);
$returnurl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
if ($occurrenceid) {
    $returnurl->param('occurrence', $occurrenceid);
}
$PAGE->set_url($key !== '' ? new moodle_url($url, ['key' => $key, 'occurrence' => $occurrenceid]) : $url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('identitylinks', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

if ($action === 'unlink') {
    require_sesskey();
    manual::unlink_identity($course->id, required_param('link', PARAM_INT));
    redirect($url, get_string('unlinked', 'local_zoomattendance'));
}

$form = null;
if ($key !== '') {
    if (!manual::is_unmatched_key($key)) {
        throw new moodle_exception('invalididentity', 'local_zoomattendance');
    }
    // The Zoom name of this identity, from any of the course's results.
    $zoomname = $DB->get_field_sql(
        "SELECT r.displayname
           FROM {local_zoomattendance_result} r
           JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
           JOIN {zoom} z ON z.id = o.zoomid
          WHERE z.course = :courseid AND r.identitykey = :identitykey",
        ['courseid' => $course->id, 'identitykey' => $key],
        IGNORE_MULTIPLE
    );
    if ($zoomname === false) {
        throw new moodle_exception('invalididentity', 'local_zoomattendance');
    }

    // Users who can be expected, as students or teachers, with the identity fields the viewer may see.
    $users = [];
    // Standard identity fields only: custom profile fields would need joins get_enrolled_users() cannot take.
    $identity = array_values(array_filter(\core_user\fields::get_identity_fields($coursecontext, false), function ($field) {
        return strpos($field, 'profile_field_') !== 0;
    }));
    $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', true)->selects;
    foreach ($identity as $field) {
        $fields .= ', u.' . $field;
    }
    foreach (['local/zoomattendance:betracked', 'local/zoomattendance:betrackedteacher'] as $capability) {
        $enrolled = get_enrolled_users($coursecontext, $capability, 0, 'u.id' . $fields, 'u.lastname, u.firstname');
        foreach ($enrolled as $user) {
            if (!manual::can_link_to($coursecontext, (int) $user->id)) {
                // Tracked teachers, for viewers who may not change teacher figures.
                continue;
            }
            $extra = array_filter(array_map(function ($field) use ($user) {
                return (string) ($user->$field ?? '');
            }, $identity));
            $users[$user->id] = fullname($user) . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
        }
    }
    core_collator::asort($users);

    $form = new link_identity(null, ['users' => $users, 'zoomname' => $zoomname]);
    $form->set_data(['id' => $cm->id, 'key' => $key, 'occurrence' => $occurrenceid]);
    if ($form->is_cancelled()) {
        redirect($returnurl);
    } else if ($userid = $form->get_userid()) {
        manual::link_identity($course->id, $key, $userid, $zoomname);
        redirect($returnurl, get_string('linked', 'local_zoomattendance'));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('identitylinks', 'local_zoomattendance'));

if ($form) {
    echo html_writer::tag('p', get_string('linkintro', 'local_zoomattendance'));
    $form->display();
}

echo $OUTPUT->heading(get_string('courselinks', 'local_zoomattendance'), 3);
$links = $DB->get_records('local_zoomattendance_idmap', ['courseid' => $course->id], 'timecreated DESC');
if (!$links) {
    echo $OUTPUT->notification(get_string('nolinks', 'local_zoomattendance'), 'info', false);
} else {
    $users = user_get_users_by_id(array_unique(array_column($links, 'userid')));
    $table = new html_table();
    $table->caption = get_string('courselinks', 'local_zoomattendance');
    $table->captionhide = true;
    $table->head = [
        get_string('zoomname', 'local_zoomattendance'),
        get_string('fullnameuser'),
        get_string('linkedon', 'local_zoomattendance'),
        get_string('actions'),
    ];
    foreach ($links as $link) {
        $user = $users[$link->userid] ?? null;
        $table->data[] = [
            s((string) $link->displayname),
            $user ? fullname($user) : '',
            userdate($link->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            manual::can_link_to($coursecontext, (int) $link->userid) ? html_writer::link(
                new moodle_url($url, ['action' => 'unlink', 'link' => $link->id, 'sesskey' => sesskey()]),
                get_string('unlink', 'local_zoomattendance')
            ) : '',
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::link($returnurl, get_string('backtoreport', 'local_zoomattendance'));
echo $OUTPUT->footer();
