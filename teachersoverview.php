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
 * Teacher attendance across courses: every teacher for managers, or one's own figures.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\teacher_filter;
use local_zoomattendance\local\settings;
use local_zoomattendance\local\status;
use local_zoomattendance\local\teacher_overview;
use local_zoomattendance\local\teacher_summary;
use local_zoomattendance\output\renderer;

$mine = optional_param('mine', 0, PARAM_BOOL);
$download = optional_param('download', '', PARAM_ALPHA);
$from = optional_param('fromts', usergetmidnight(time() - 30 * DAYSECS), PARAM_INT);
$to = optional_param('tots', usergetmidnight(time()), PARAM_INT);
$categoryid = optional_param('category', 0, PARAM_INT);

require_login();
$systemcontext = context_system::instance();
$capability = $mine ? 'local/zoomattendance:viewownteacher' : 'local/zoomattendance:viewteacherreports';
if (!teacher_overview::courses((int) $USER->id, $capability)) {
    if (!$mine && teacher_overview::courses((int) $USER->id, 'local/zoomattendance:viewownteacher')) {
        redirect(new moodle_url('/local/zoomattendance/teachersoverview.php', ['mine' => 1]));
    }
    throw new required_capability_exception($systemcontext, $capability, 'nopermissions', '');
}

$url = new moodle_url('/local/zoomattendance/teachersoverview.php', $mine ? ['mine' => 1] : []);
$PAGE->set_context($systemcontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$title = get_string($mine ? 'myteaching' : 'teachersoverview', 'local_zoomattendance');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$form = new teacher_filter($url, ['categories' => $mine ? [] : core_course_category::make_categories_list()], 'get');
$form->set_data(['mine' => $mine, 'from' => $from, 'to' => $to, 'category' => $categoryid]);
if ($data = $form->get_data()) {
    $from = (int) $data->from;
    $to = (int) $data->to;
    $categoryid = (int) ($data->category ?? 0);
}

$rows = settings::teacher_tracking()
    ? teacher_overview::rows((int) $USER->id, (bool) $mine, $from, $to + DAYSECS, $categoryid)
    : [];

$columns = [];
if (!$mine) {
    $columns['teacher'] = get_string('teacher', 'local_zoomattendance');
}
$columns += [
    'course' => get_string('course'),
    'expected' => get_string('sessionsexpected', 'local_zoomattendance'),
    status::PRESENT => get_string('status_present', 'local_zoomattendance'),
    status::PARTIAL => get_string('status_partial', 'local_zoomattendance'),
    status::ABSENT => get_string('status_absent', 'local_zoomattendance'),
    'notheld' => get_string('status_notheld', 'local_zoomattendance'),
    'overall' => get_string('courseoverall', 'local_zoomattendance'),
    'latestarts' => get_string('latestarts', 'local_zoomattendance'),
    'earlyleaves' => get_string('earlyleaves', 'local_zoomattendance'),
    'excluded' => get_string('status_excluded', 'local_zoomattendance'),
    'excludedbyself' => get_string('excludedbyself', 'local_zoomattendance'),
    'selflinked' => get_string('selflinked', 'local_zoomattendance'),
];

if ($download !== '') {
    $records = [];
    foreach ($rows as $row) {
        $record = [];
        if (!$mine) {
            $record['teacher'] = fullname($row->user);
        }
        $record['course'] = format_string($row->course->fullname, true, ['escape' => false]);
        foreach (array_keys(teacher_summary::empty_stats()) as $key) {
            $record[$key] = $row->stats[$key];
        }
        $record['overall'] = renderer::overall($row->overall);
        $records[] = array_merge(array_fill_keys(array_keys($columns), ''), $record);
    }
    \core\dataformat::download_data('teacherattendance-' . userdate($from, '%Y%m%d'), $download, $columns, $records);
    die();
}

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();

if (!settings::teacher_tracking()) {
    echo $output->notification(get_string('teachertrackingoff', 'local_zoomattendance'), 'info');
    echo $output->footer();
    die();
}
$form->display();
$thresholds = settings::teacher();
echo html_writer::tag('p', get_string('teacherthresholdsinfo', 'local_zoomattendance', (object) [
    'present' => $thresholds->presentpct,
    'grace' => $thresholds->lategracemins,
    'partial' => $thresholds->latepct,
]));
if (!$rows) {
    echo $output->notification(get_string('noteacherdata', 'local_zoomattendance'), 'info');
} else {
    echo html_writer::tag('p', get_string('teachersoverview_help', 'local_zoomattendance'), ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = array_values($columns);
    foreach ($rows as $row) {
        $cells = [];
        if (!$mine) {
            $cells[] = fullname($row->user);
        }
        $cells[] = html_writer::link(
            new moodle_url('/local/zoomattendance/teachers.php', ['id' => $row->course->id]),
            format_string($row->course->fullname, true, ['context' => context_course::instance($row->course->id)])
        );
        foreach (['expected', status::PRESENT, status::PARTIAL, status::ABSENT, 'notheld'] as $key) {
            $cells[] = $row->stats[$key];
        }
        $cells[] = renderer::overall($row->overall);
        foreach (['latestarts', 'earlyleaves', 'excluded', 'excludedbyself', 'selflinked'] as $key) {
            $cells[] = $row->stats[$key];
        }
        $table->data[] = $cells;
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo $output->download_dataformat_selector(
        get_string('download'),
        $url->out_omit_querystring(),
        'download',
        ['mine' => (int) $mine, 'fromts' => $from, 'tots' => $to, 'category' => $categoryid]
    );
}
echo $output->footer();
