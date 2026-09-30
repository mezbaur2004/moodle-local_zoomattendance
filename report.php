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
 * Attendance report for one Zoom activity.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\local\attendance;
use local_zoomattendance\local\source\zoom_source;
use local_zoomattendance\local\sync;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$occurrenceid = optional_param('occurrence', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/zoomattendance:viewreports', $context);
$canmanage = has_capability('local/zoomattendance:manage', $context);

$baseurl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
$url = $occurrenceid ? new moodle_url($baseurl, ['occurrence' => $occurrenceid]) : $baseurl;
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('attendancereport', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$attendance = new attendance($cm);

if ($action !== '') {
    require_sesskey();
    require_capability('local/zoomattendance:manage', $context);
    if ($action === 'recompute') {
        $done = sync::sync_instance($attendance->instance, true);
        redirect($url, get_string($done ? 'recomputed' : 'recomputebusy', 'local_zoomattendance'));
    }
    if ($action === 'exclude' || $action === 'include') {
        $target = $DB->get_record(
            'local_zoomattendance_occ',
            ['id' => required_param('target', PARAM_INT), 'zoomid' => $attendance->instance->id],
            '*',
            MUST_EXIST
        );
        if ((int) $target->status !== sync::STATUS_CANCELLED) {
            $DB->set_field(
                'local_zoomattendance_occ',
                'status',
                $action === 'exclude' ? sync::STATUS_EXCLUDED : sync::STATUS_ACTIVE,
                ['id' => $target->id]
            );
        }
        redirect($url);
    }
    throw new moodle_exception('invalidaction', 'local_zoomattendance');
}

$masked = (bool) get_config('zoom', 'maskparticipantdata');
$groupmode = groups_get_activity_groupmode($cm);
$groupid = (int) groups_get_activity_group($cm, true);
$nogroupaccess = $groupmode == SEPARATEGROUPS && !$groupid && !has_capability('moodle/site:accessallgroups', $context);

$occurrences = $attendance->get_occurrences();
if ($occurrenceid && !isset($occurrences[$occurrenceid])) {
    throw new moodle_exception('invalidoccurrence', 'local_zoomattendance');
}
$candidates = $nogroupaccess ? [] : $attendance->get_candidates($groupid);

if ($occurrenceid && !$masked && !$nogroupaccess) {
    $occurrence = $occurrences[$occurrenceid];
    $results = $attendance->get_results([$occurrenceid])[$occurrenceid] ?? [];
    $evaluation = $attendance->evaluate($occurrence, $candidates, $results, $groupid);

    if ($download !== '') {
        $identityfields = $attendance->identity_fields();
        $columns = ['fullname' => get_string('fullnameuser')];
        foreach ($identityfields as $field) {
            $columns[$field] = \core_user\fields::get_display_name($field);
        }
        $columns += [
            'group' => get_string('participantgroup', 'local_zoomattendance'),
            'firstjoin' => get_string('firstjoin', 'local_zoomattendance'),
            'lastleave' => get_string('lastleave', 'local_zoomattendance'),
            'attendedminutes' => get_string('attendedminutes', 'local_zoomattendance'),
            'percentage' => get_string('percentage', 'local_zoomattendance'),
            'status' => get_string('status', 'local_zoomattendance'),
        ];
        $rows = [];
        $sections = [
            'expected' => $evaluation->expected,
            'notexpected' => $evaluation->notexpected,
            'unmatched' => $evaluation->unmatched,
        ];
        $timeformat = get_string('strftimedatetimeshort', 'langconfig');
        foreach ($sections as $section => $sectionrows) {
            foreach ($sectionrows as $row) {
                $record = ['fullname' => !empty($row->user) ? fullname($row->user) : $row->displayname];
                foreach ($identityfields as $field) {
                    $record[$field] = !empty($row->user) ? ($row->user->$field ?? '') : '';
                }
                $record += [
                    'group' => get_string($section, 'local_zoomattendance'),
                    'firstjoin' => $row->firstjoin ? userdate($row->firstjoin, $timeformat) : '',
                    'lastleave' => $row->lastleave ? userdate($row->lastleave, $timeformat) : '',
                    'attendedminutes' => round($row->attendedsecs / MINSECS, 1),
                    'percentage' => $row->percentage === null ? '' : round($row->percentage, 1),
                    'status' => $row->status ? get_string('status_' . $row->status, 'local_zoomattendance') : '',
                ];
                $rows[] = $record;
            }
        }
        $filename = clean_filename($cm->name . '-' . userdate($occurrence->timestart, '%Y%m%d-%H%M'));
        \core\dataformat::download_data($filename, $download, $columns, $rows);
        die();
    }
}

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();
echo $output->heading(get_string('attendancereport', 'local_zoomattendance'));

if (!$attendance->settings->enabled) {
    echo $output->notification(get_string('notenabled', 'local_zoomattendance'), 'warning');
}
if (($shared = zoom_source::count_shared_meeting_id($attendance->instance)) > 0) {
    echo $output->notification(get_string('sharedmeetingid', 'local_zoomattendance', $shared), 'warning');
}
$thresholds = (object) [
    'present' => $attendance->settings->presentpct,
    'late' => $attendance->settings->latepct,
    'grace' => $attendance->settings->lategracemins,
    'denominator' => get_string('denominator_' . $attendance->settings->denominator, 'local_zoomattendance'),
];
echo html_writer::tag('p', get_string('thresholdsinfo', 'local_zoomattendance', $thresholds));
$lastcomputed = 0;
foreach ($occurrences as $occurrence) {
    $lastcomputed = max($lastcomputed, (int) $occurrence->timecomputed);
}
echo html_writer::tag('p', get_string(
    'lastcomputed',
    'local_zoomattendance',
    $lastcomputed ? userdate($lastcomputed) : get_string('never')
), ['class' => 'text-muted']);
if ($canmanage) {
    echo $output->single_button(
        new moodle_url($url, ['action' => 'recompute', 'sesskey' => sesskey()]),
        get_string('recompute', 'local_zoomattendance'),
        'post'
    );
}

groups_print_activity_menu($cm, $url);

if ($nogroupaccess) {
    echo $output->notification(get_string('notingroup'), 'info');
} else if ($occurrenceid && !$masked) {
    echo $output->heading(renderer::window($occurrences[$occurrenceid]), 3);
    echo html_writer::tag('p', $output->badge($evaluation->state) . ' ' .
        get_string('denominatorinfo', 'local_zoomattendance', renderer::duration($evaluation->denominator)));
    echo $output->occurrence_detail($attendance, $evaluation);
    echo $output->download_dataformat_selector(
        get_string('download'),
        $url->out_omit_querystring(),
        'download',
        ['id' => $cm->id, 'occurrence' => $occurrenceid, 'group' => $groupid]
    );
    echo html_writer::link($baseurl, get_string('backtooccurrences', 'local_zoomattendance'));
} else {
    if ($masked) {
        echo $output->notification(get_string('maskedinfo', 'local_zoomattendance'), 'info');
    }
    if (!$occurrences) {
        echo $output->notification(get_string('nooccurrences', 'local_zoomattendance'), 'info');
    } else {
        $results = $attendance->get_results(array_keys($occurrences));
        $evaluations = [];
        foreach ($occurrences as $occurrence) {
            $evaluations[$occurrence->id] = $attendance->evaluate(
                $occurrence,
                $candidates,
                $results[$occurrence->id] ?? [],
                $groupid
            );
        }
        echo $output->occurrence_list($attendance, $evaluations, $baseurl, $canmanage, $masked);
    }
    echo html_writer::link(
        new moodle_url('/mod/zoom/report.php', ['id' => $cm->id]),
        get_string('zoomsessionsreport', 'local_zoomattendance')
    );
}

echo $output->footer();
