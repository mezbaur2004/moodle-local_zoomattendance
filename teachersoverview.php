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
use local_zoomattendance\output\renderer;

$mine = optional_param('mine', 0, PARAM_BOOL);
$download = optional_param('download', '', PARAM_ALPHA);
$from = optional_param('fromts', usergetmidnight(time() - 30 * DAYSECS), PARAM_INT);
$to = optional_param('tots', usergetmidnight(time()), PARAM_INT);
$categoryid = optional_param('category', 0, PARAM_INT);

require_login();
$systemcontext = context_system::instance();
$capability = $mine ? teacher_overview::MINE_CAPABILITIES : 'local/zoomattendance:viewteacherreports';
$hascourses = teacher_overview::has_courses((int) $USER->id, $capability);
if (!$mine && !$hascourses) {
    if (teacher_overview::has_courses((int) $USER->id, teacher_overview::MINE_CAPABILITIES)) {
        redirect(new moodle_url('/local/zoomattendance/teachersoverview.php', ['mine' => 1, 'fromts' => $from, 'tots' => $to]));
    }
    throw new required_capability_exception($systemcontext, 'local/zoomattendance:viewteacherreports', 'nopermissions', '');
}

$params = ['fromts' => $from, 'tots' => $to] + ($mine ? ['mine' => 1] : ['category' => $categoryid]);
$url = new moodle_url('/local/zoomattendance/teachersoverview.php', $params);
$PAGE->set_context($systemcontext);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
// Editing teachers' own view also lists their non-editing teachers.
$withteacher = !$mine || teacher_overview::has_courses((int) $USER->id, 'local/zoomattendance:viewnoneditingteachers');
$title = get_string($mine ? ($withteacher ? 'teachersmine' : 'myteachingall') : 'teachersoverview', 'local_zoomattendance');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$categories = $mine ? [] : teacher_overview::categories((int) $USER->id, $capability);
$form = new teacher_filter(
    new moodle_url('/local/zoomattendance/teachersoverview.php'),
    ['hidden' => ['mine' => PARAM_BOOL], 'categories' => $categories],
    'get'
);
$form->set_data(['mine' => $mine, 'from' => $from, 'to' => $to, 'category' => $categoryid]);
if ($data = $form->get_data()) {
    // Keep the filter in a plain URL so it can be bookmarked and reloaded.
    redirect(new moodle_url($url, ['fromts' => (int) $data->from, 'tots' => (int) $data->to]
        + ($mine ? [] : ['category' => (int) ($data->category ?? 0)])));
}

$rows = settings::teacher_tracking() && $hascourses
    ? teacher_overview::rows((int) $USER->id, (bool) $mine, $from, teacher_overview::day_end($to), $categoryid)
    : [];

// The page shows the main figures; the download also has the detail counts.
$columns = $withteacher ? ['teacher' => get_string('teacher', 'local_zoomattendance')] : [];
$columns += [
    'role' => get_string('role'),
    'course' => get_string('course'),
    'expected' => get_string('classes', 'local_zoomattendance'),
    status::PRESENT => get_string('status_present', 'local_zoomattendance'),
    status::PARTIAL => get_string('status_partial', 'local_zoomattendance'),
    status::ABSENT => get_string('status_absent', 'local_zoomattendance'),
    'overall' => get_string('teacheroverall', 'local_zoomattendance'),
    'joinedoverall' => get_string('whenjoined', 'local_zoomattendance'),
    'notes' => get_string('notes', 'local_zoomattendance'),
];

if ($download !== '') {
    $filecolumns = ($withteacher ? ['teacher' => $columns['teacher']] : []) + [
        'role' => get_string('role'),
        'course' => get_string('course'),
        'category' => get_string('category'),
        'expected' => get_string('classes', 'local_zoomattendance'),
        status::PRESENT => get_string('status_present', 'local_zoomattendance'),
        status::PARTIAL => get_string('status_partial', 'local_zoomattendance'),
        status::ABSENT => get_string('status_absent', 'local_zoomattendance'),
        'notheld' => get_string('ofwhichnotheld', 'local_zoomattendance'),
        'overall' => get_string('teacheroverall', 'local_zoomattendance'),
        'joined' => get_string('classesjoined', 'local_zoomattendance'),
        'joinedoverall' => get_string('whenjoined', 'local_zoomattendance'),
        'latestarts' => get_string('latestarts', 'local_zoomattendance'),
        'earlyleaves' => get_string('earlyleaves', 'local_zoomattendance'),
        'excluded' => get_string('status_excluded', 'local_zoomattendance'),
        'excludedbyself' => get_string('excludedbyself', 'local_zoomattendance'),
        'selflinked' => get_string('selflinked', 'local_zoomattendance'),
    ];
    $categorynames = core_course_category::make_categories_list();
    $records = [];
    foreach ($rows as $row) {
        $record = $withteacher ? ['teacher' => fullname($row->user)] : [];
        $record['role'] = $row->role;
        $record['course'] = format_string($row->course->fullname, true, ['escape' => false]);
        $record['category'] = $categorynames[$row->course->category] ?? '';
        foreach (array_keys(\local_zoomattendance\local\teacher_summary::empty_stats()) as $key) {
            $record[$key] = $row->stats[$key];
        }
        $record['overall'] = $row->overall ? round($row->overall->percentage(), 1) : '';
        $record['joinedoverall'] = $row->joined ? round($row->joined->percentage(), 1) : '';
        $records[] = array_merge(array_fill_keys(array_keys($filecolumns), ''), $record);
    }
    \core\dataformat::download_data(
        'teacherattendance-' . userdate($from, '%Y%m%d') . '-' . userdate($to, '%Y%m%d'),
        $download,
        $filecolumns,
        $records
    );
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
if (!$hascourses) {
    echo $output->notification(get_string('nomyteachingcourses', 'local_zoomattendance'), 'info');
    echo $output->footer();
    die();
}
$form->display();
$range = (object) [
    'from' => userdate($from, get_string('strftimedate', 'langconfig')),
    'to' => userdate($to, get_string('strftimedate', 'langconfig')),
];
echo html_writer::tag('p', get_string('showingrange', 'local_zoomattendance', $range), ['class' => 'text-muted']);
echo $output->teacher_legend(settings::teacher());
if (!$rows) {
    // The own view has no category filter.
    $empty = $mine ? 'noteacherdatarangemine' : 'noteacherdatarange';
    echo $output->notification(get_string($empty, 'local_zoomattendance', $range), 'info');
} else {
    echo html_writer::tag('p', get_string('teachersoverview_help', 'local_zoomattendance'), ['class' => 'text-muted']);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = array_values($columns);
    foreach ($rows as $row) {
        $cells = [];
        if ($withteacher) {
            $cells[] = fullname($row->user);
        }
        $cells[] = s($row->role);
        // The course page opens on the same date range.
        $cells[] = html_writer::link(
            new moodle_url('/local/zoomattendance/teachers.php', ['id' => $row->course->id, 'fromts' => $from, 'tots' => $to]),
            format_string($row->course->fullname, true, ['context' => context_course::instance($row->course->id)])
        );
        foreach (['expected', status::PRESENT, status::PARTIAL, status::ABSENT] as $key) {
            $cells[] = $row->stats[$key];
        }
        $cells[] = $output->overall_meter($row->overall, settings::teacher());
        $cells[] = $output->joined_meter($row->joined, $row->stats, settings::teacher());
        $cells[] = html_writer::span(s(implode(' · ', renderer::teacher_list_notes($row->stats))), 'small');
        $table->data[] = $cells;
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo $output->download_dataformat_selector(
        get_string('downloadtable', 'local_zoomattendance'),
        $url->out_omit_querystring(),
        'download',
        $params
    );
}
echo $output->footer();
