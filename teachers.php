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
 * Teacher attendance for a course.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\teacher_filter;
use local_zoomattendance\local\attendance;
use local_zoomattendance\local\settings;
use local_zoomattendance\local\teacher_access;
use local_zoomattendance\local\teacher_attendance;
use local_zoomattendance\local\teacher_overview;
use local_zoomattendance\local\teacher_summary;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
// Managers see every teacher, editing teachers also the non-editing teachers, and a teacher themself.
if (!teacher_access::can_view_any($context)) {
    require_capability('local/zoomattendance:viewownteacher', $context);
}
$visible = teacher_access::visible_teachers($context);
$canviewall = $visible === null;
$onlyown = !$canviewall && !has_capability('local/zoomattendance:viewnoneditingteachers', $context);
// Default range: from the course's first class (the course start date is often later or unset).
$from = optional_param('fromts', usergetmidnight(teacher_overview::first_class($course->id) ?: time() - 30 * DAYSECS), PARAM_INT);
$to = optional_param('tots', usergetmidnight(time()), PARAM_INT);

$url = new moodle_url('/local/zoomattendance/teachers.php', ['id' => $course->id, 'fromts' => $from, 'tots' => $to]);
$PAGE->set_url($url);
$title = get_string($onlyown ? 'myteaching' : 'teacherattendance', 'local_zoomattendance');
$PAGE->set_title($title . ': ' . format_string($course->shortname, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$form = new teacher_filter(new moodle_url('/local/zoomattendance/teachers.php'), ['hidden' => ['id' => PARAM_INT]], 'get');
$form->set_data(['id' => $course->id, 'from' => $from, 'to' => $to]);
if ($data = $form->get_data()) {
    // Keep the filter in a plain URL so it can be bookmarked and reloaded.
    redirect(new moodle_url($url, ['fromts' => (int) $data->from, 'tots' => (int) $data->to]));
}

$tracking = settings::teacher_tracking();
$summary = $tracking
    ? teacher_summary::build($course, $visible, $from, teacher_overview::day_end($to))
    : null;

if ($download !== '' && $summary && $summary->classes) {
    // One row per class and teacher, so the file sorts and filters well in a spreadsheet.
    $columns = [
        'teacher' => get_string('teacher', 'local_zoomattendance'),
        'role' => get_string('role'),
        'course' => get_string('course'),
        'class' => get_string('class', 'local_zoomattendance'),
        'date' => get_string('date'),
        'status' => get_string('status', 'local_zoomattendance'),
        'percentage' => get_string('percentage', 'local_zoomattendance'),
        'joined' => get_string('joinedclass', 'local_zoomattendance'),
        'latemins' => get_string('latemins', 'local_zoomattendance'),
        'earlymins' => get_string('earlymins', 'local_zoomattendance'),
        'note' => get_string('note', 'local_zoomattendance'),
    ];
    $rows = [];
    $timeformat = get_string('strftimedatetimeshort', 'langconfig');
    foreach ($summary->users as $userid => $user) {
        foreach ($summary->classes as $class) {
            $row = $summary->cells[$userid][$class->occurrence->id] ?? null;
            if (!$row) {
                continue;
            }
            $evaluated = $class->state === attendance::STATE_EVALUATED;
            $notes = array_filter(array_merge(
                [renderer::class_note($summary, $class->state, (int) $class->occurrence->id)],
                $evaluated && isset($summary->selflinked[$userid][$class->occurrence->id])
                    ? [get_string('selflinked', 'local_zoomattendance')] : []
            ));
            $rows[] = [
                'teacher' => fullname($user),
                'role' => $summary->roles[$userid] ?? '',
                'course' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                'class' => format_string($class->cm->name, true, ['escape' => false]),
                'date' => userdate($class->occurrence->timestart, $timeformat),
                'status' => get_string('status_' . ($evaluated ? $row->status : $class->state), 'local_zoomattendance'),
                'percentage' => $row->percentage === null ? '' : round($row->percentage, 1),
                // Whether the class counts towards "When joined".
                'joined' => teacher_attendance::counts($class->state)
                    ? get_string((int) $row->attendedsecs > 0 ? 'yes' : 'no') : '',
                'latemins' => $evaluated && $row->firstjoin !== null ? intdiv($row->latesecs, MINSECS) : '',
                'earlymins' => $evaluated && $row->lastleave !== null ? intdiv($row->earlysecs, MINSECS) : '',
                'note' => implode('; ', $notes),
            ];
        }
    }
    \core\dataformat::download_data(
        clean_filename($course->shortname . '-teacherattendance-' . userdate($from, '%Y%m%d') . '-' . userdate($to, '%Y%m%d')),
        $download,
        $columns,
        $rows
    );
    die();
}

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();
echo $output->heading($title);

if (!$tracking) {
    echo $output->notification(get_string('teachertrackingoff', 'local_zoomattendance'), 'info');
} else {
    $form->display();
    echo html_writer::tag('p', get_string('showingrange', 'local_zoomattendance', (object) [
        'from' => userdate($from, get_string('strftimedate', 'langconfig')),
        'to' => userdate($to, get_string('strftimedate', 'langconfig')),
    ]), ['class' => 'text-muted']);
    echo $output->teacher_legend(settings::teacher());
    if (!$summary->classes) {
        echo $output->notification(get_string($onlyown ? 'nomyclasses' : 'noteacherclasses', 'local_zoomattendance'), 'info');
    } else {
        echo html_writer::tag('p', get_string('teachersummary_help', 'local_zoomattendance'), ['class' => 'text-muted']);
        echo $output->teacher_table($summary);
        echo $output->download_dataformat_selector(
            get_string('downloadclasses', 'local_zoomattendance'),
            $url->out_omit_querystring(),
            'download',
            ['id' => $course->id, 'fromts' => $from, 'tots' => $to]
        );
    }
}
$links = [html_writer::link(
    new moodle_url('/local/zoomattendance/course.php', ['id' => $course->id]),
    get_string('courseattendance', 'local_zoomattendance')
)];
$links[] = html_writer::link(
    new moodle_url('/local/zoomattendance/teachersoverview.php', $canviewall ? [] : ['mine' => 1]),
    get_string($canviewall ? 'teachersoverview' : ($onlyown ? 'myteachingall' : 'teachersmine'), 'local_zoomattendance')
);
echo html_writer::tag('p', implode(' · ', $links));
echo $output->footer();
