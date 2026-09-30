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
 * Zoom attendance summary for a course.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\local\course_summary;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/zoomattendance:viewreports', $context);

$url = new moodle_url('/local/zoomattendance/course.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('courseattendance', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$masked = (bool) get_config('zoom', 'maskparticipantdata');
$groupmode = groups_get_course_groupmode($course);
$groupid = (int) groups_get_course_group($course, true);
$nogroupaccess = $groupmode == SEPARATEGROUPS && !$groupid && !has_capability('moodle/site:accessallgroups', $context);

$summary = null;
if (!$masked && !$nogroupaccess) {
    $summary = course_summary::build($course, $groupid);
}

if ($download !== '' && $summary && $summary->activities) {
    $columns = ['fullname' => get_string('fullnameuser')];
    foreach ($summary->activities as $activity) {
        foreach ($activity->columns as $occurrenceid => $occurrence) {
            $columns['o' . $occurrenceid] = format_string($activity->cm->name, true, ['escape' => false]) . ' – ' .
                userdate($occurrence->timestart, get_string('strftimedatetimeshort', 'langconfig'));
        }
    }
    $columns['overall'] = get_string('courseoverall', 'local_zoomattendance');
    $rows = [];
    foreach ($summary->users as $userid => $user) {
        $record = ['fullname' => fullname($user)];
        foreach ($summary->activities as $activity) {
            foreach ($activity->columns as $occurrenceid => $occurrence) {
                $row = $summary->cells[$userid][$occurrenceid] ?? null;
                $record['o' . $occurrenceid] = $row ? renderer::status_text($row->status, $row->percentage) : '';
            }
        }
        $record['overall'] = renderer::overall($summary->overall[$userid] ?? null);
        $rows[] = $record;
    }
    \core\dataformat::download_data(clean_filename($course->shortname . '-zoomattendance'), $download, $columns, $rows);
    die();
}

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();
echo $output->heading(get_string('courseattendance', 'local_zoomattendance'));
groups_print_course_menu($course, $url);

if ($masked) {
    echo $output->notification(get_string('maskedinfo', 'local_zoomattendance'), 'info');
} else if ($nogroupaccess) {
    echo $output->notification(get_string('notingroup'), 'info');
} else if (!$summary->activities) {
    echo $output->notification(get_string('nooccurrencedata', 'local_zoomattendance'), 'info');
} else {
    echo html_writer::tag('p', get_string('coursesummary_help', 'local_zoomattendance'), ['class' => 'text-muted']);
    echo $output->course_table($summary);
    echo $output->download_dataformat_selector(
        get_string('download'),
        $url->out_omit_querystring(),
        'download',
        ['id' => $course->id, 'group' => $groupid]
    );
}
echo $output->footer();
