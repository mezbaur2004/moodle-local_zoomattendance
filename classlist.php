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
 * The list of who counts for a class: add or remove people by hand, with a reason.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\classlist_edit;
use local_zoomattendance\local\attendance;
use local_zoomattendance\local\roster;
use local_zoomattendance\local\settings;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$occurrenceid = required_param('occurrence', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('user', 0, PARAM_INT);
$kind = optional_param('kind', roster::KIND_STUDENT, PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/zoomattendance:viewreports', $context);
// Teachers' lists decide teachers' attendance: only while it is tracked, and only for users who may change it.
$canedit = [
    roster::KIND_STUDENT => roster::can_edit($context, roster::KIND_STUDENT),
    roster::KIND_TEACHER => settings::teacher_tracking() && roster::can_edit($context, roster::KIND_TEACHER),
];
if (!array_filter($canedit)) {
    require_capability('local/zoomattendance:manage', $context);
}

$occurrence = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrenceid, 'zoomid' => $cm->instance], '*', MUST_EXIST);
$url = new moodle_url('/local/zoomattendance/classlist.php', ['id' => $cm->id, 'occurrence' => $occurrence->id]);
$reporturl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id, 'occurrence' => $occurrence->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('classlistpage', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
$heading = get_string('classlistheading', 'local_zoomattendance', renderer::window($occurrence));

if ((int) $occurrence->rosterfrozen <= 0) {
    echo $output->header();
    echo $output->heading($heading);
    echo $output->notification(get_string('classlistnotfrozen', 'local_zoomattendance'), 'info');
    echo html_writer::link($reporturl, get_string('backtoclass', 'local_zoomattendance'));
    echo $output->footer();
    die();
}

$attendance = new attendance($cm);
$entries = roster::entries([(int) $occurrence->id])[(int) $occurrence->id] ?? [];
$attended = [];
foreach ($attendance->get_results([(int) $occurrence->id])[(int) $occurrence->id] ?? [] as $result) {
    if ($result->userid) {
        $attended[(int) $result->userid] = (int) $result->attendedsecs;
    }
}

// Adding or removing someone: ask why.
if ($action !== '') {
    if (!in_array($action, ['add', 'remove'], true) || empty($canedit[$kind])) {
        throw new required_capability_exception($context, 'local/zoomattendance:manage', 'nopermissions', '');
    }
    $entry = $entries[$kind][$userid] ?? null;
    $listed = $entry && !(int) $entry->removed;
    // Only people of this class: on its list, or Zoom matched them to it.
    if (($action === 'remove' && !$listed) || ($action === 'add' && ($listed || (!$entry && !isset($attended[$userid]))))) {
        redirect($url);
    }
    $user = core_user::get_user($userid, '*', MUST_EXIST);
    $form = new classlist_edit($url, ['action' => $action]);
    $form->set_data(['id' => $cm->id, 'occurrence' => $occurrence->id, 'user' => $userid, 'action' => $action, 'kind' => $kind]);
    if ($form->is_cancelled()) {
        redirect($url);
    } else if ($data = $form->get_data()) {
        if ($action === 'add') {
            roster::add_by_hand($occurrence, $cm, $userid, $kind, $data->reason);
        } else {
            roster::remove_by_hand($occurrence, $cm, $userid, $kind, $data->reason);
        }
        $done = $action === 'add' ? 'classlistadded' : 'classlistremoved';
        redirect($url, get_string($done, 'local_zoomattendance', fullname($user)));
    }
    echo $output->header();
    echo $output->heading(get_string('classlist' . $action . 'heading', 'local_zoomattendance', (object) [
        'name' => fullname($user),
        'kind' => get_string('kind' . $kind, 'local_zoomattendance'),
        'class' => renderer::window($occurrence),
    ]));
    echo html_writer::tag('p', get_string('classlist' . $action . 'intro', 'local_zoomattendance'));
    $form->display();
    echo $output->footer();
    die();
}

// The list.
$userids = array_keys($attended);
foreach ($entries as $rows) {
    $userids = array_merge($userids, array_keys($rows));
}
$users = $attendance->load_users(array_unique($userids));
$by = renderer::list_editors(array_merge([], ...array_map('array_values', array_values($entries))));

echo $output->header();
echo $output->heading($heading);
echo html_writer::tag('p', get_string('classlistintro', 'local_zoomattendance'), ['class' => 'text-muted']);

$listedids = [];
$removed = [];
foreach ([roster::KIND_STUDENT, roster::KIND_TEACHER] as $listkind) {
    // Teachers' list only for users who may change it: other teachers' attendance stays private.
    if ($listkind === roster::KIND_TEACHER && !$canedit[roster::KIND_TEACHER]) {
        continue;
    }
    $rows = [];
    foreach ($entries[$listkind] ?? [] as $entryuserid => $entry) {
        $listedids[$entryuserid] = true;
        if (!isset($users[$entryuserid])) {
            continue;
        }
        if ((int) $entry->removed) {
            $removed[] = [$listkind, $entry];
            continue;
        }
        $rows[] = [
            fullname($users[$entryuserid]),
            renderer::duration($attended[$entryuserid] ?? 0),
            s(renderer::list_note($entry, $by[(int) $entry->usermodified] ?? null)),
            $canedit[$listkind] ? $output->classlist_action($url, 'remove', $entryuserid, $listkind, 'classlistremove') : '',
        ];
    }
    echo $output->heading(get_string('classlist' . $listkind . 's', 'local_zoomattendance', count($rows)), 4);
    echo $output->classlist_table($rows, get_string('classlistsource', 'local_zoomattendance'));
}

// Removed by hand, for the lists the user may change.
if ($removed) {
    $rows = [];
    foreach ($removed as [$listkind, $entry]) {
        $rows[] = [
            fullname($users[(int) $entry->userid]) . ' (' . get_string('kind' . $listkind, 'local_zoomattendance') . ')',
            renderer::duration($attended[(int) $entry->userid] ?? 0),
            s(renderer::list_note($entry, $by[(int) $entry->usermodified] ?? null)),
            $canedit[$listkind] ? $output->classlist_action($url, 'add', (int) $entry->userid, $listkind, 'classlistaddback') : '',
        ];
    }
    echo $output->heading(get_string('classlistremovedusers', 'local_zoomattendance'), 4);
    echo $output->classlist_table($rows, get_string('classlistsource', 'local_zoomattendance'));
}

// Joined, but on no list: who they can be added as, from their role in the course now.
$rows = [];
foreach ($attended as $attendeeid => $secs) {
    if (isset($listedids[$attendeeid]) || !isset($users[$attendeeid])) {
        continue;
    }
    $actions = [];
    foreach ([roster::KIND_STUDENT => 'betracked', roster::KIND_TEACHER => 'betrackedteacher'] as $listkind => $capability) {
        $holds = is_enrolled($context, $attendeeid, 'local/zoomattendance:' . $capability, true);
        if ($listkind === roster::KIND_TEACHER && $holds && !$canedit[roster::KIND_TEACHER]) {
            // A teacher's presence is theirs to see only for users who may change teachers' lists.
            continue 2;
        }
        if ($holds && $canedit[$listkind]) {
            $actions[] = $output->classlist_action($url, 'add', $attendeeid, $listkind, 'classlistaddas' . $listkind);
        }
    }
    $rows[] = [
        fullname($users[$attendeeid]),
        renderer::duration($secs),
        '',
        $actions ? implode(' ', $actions)
            : html_writer::span(get_string('classlistnotenrolled', 'local_zoomattendance'), 'text-muted'),
    ];
}
echo $output->heading(get_string('classlistjoined', 'local_zoomattendance'), 4);
echo html_writer::tag('p', get_string('classlistjoined_help', 'local_zoomattendance'), ['class' => 'text-muted']);
echo $output->classlist_table($rows, '');

echo html_writer::link($reporturl, get_string('backtoclass', 'local_zoomattendance'));
echo $output->footer();
