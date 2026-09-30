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

use local_zoomattendance\local\attendance;
use local_zoomattendance\local\status;
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

// Per activity: user id => [present, late, absent, sum of percentages, evaluated count].
$activities = [];
$users = [];
if (!$masked && !$nogroupaccess) {
    foreach (get_fast_modinfo($course)->get_instances_of('zoom') as $cm) {
        if (!$cm->uservisible || !has_capability('local/zoomattendance:viewreports', context_module::instance($cm->id))) {
            continue;
        }
        $attendance = new attendance($cm);
        if (!$attendance->settings->enabled) {
            continue;
        }
        $occurrences = $attendance->get_occurrences();
        $candidates = $attendance->get_candidates($groupid);
        $results = $attendance->get_results(array_keys($occurrences));
        $stats = [];
        foreach ($occurrences as $occurrence) {
            if ($attendance->state($occurrence) !== attendance::STATE_EVALUATED) {
                continue;
            }
            $evaluation = $attendance->evaluate($occurrence, $candidates, $results[$occurrence->id] ?? [], $groupid);
            foreach ($evaluation->expected as $userid => $row) {
                $users[$userid] = $row->user;
                $stats[$userid] = $stats[$userid] ?? [status::PRESENT => 0, status::LATE => 0, status::ABSENT => 0,
                    'pct' => 0.0, 'n' => 0];
                if (isset($stats[$userid][$row->status])) {
                    $stats[$userid][$row->status]++;
                    $stats[$userid]['pct'] += (float) $row->percentage;
                    $stats[$userid]['n']++;
                }
            }
        }
        $activities[$cm->id] = (object) ['cm' => $cm, 'stats' => $stats];
    }
}
uasort($users, function ($a, $b) {
    return strcmp(fullname($a), fullname($b));
});

if ($download !== '' && $activities) {
    $columns = ['fullname' => get_string('fullnameuser')];
    foreach ($activities as $cmid => $activity) {
        $columns['cm' . $cmid] = format_string($activity->cm->name);
    }
    $rows = [];
    foreach ($users as $userid => $user) {
        $record = ['fullname' => fullname($user)];
        foreach ($activities as $cmid => $activity) {
            $record['cm' . $cmid] = renderer::summary_cell($activity->stats[$userid] ?? null);
        }
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
} else if (!$activities) {
    echo $output->notification(get_string('noactivities', 'local_zoomattendance'), 'info');
} else {
    echo html_writer::tag('p', get_string('coursesummary_help', 'local_zoomattendance'), ['class' => 'text-muted']);
    $table = new html_table();
    $table->head = [get_string('fullnameuser')];
    foreach ($activities as $cmid => $activity) {
        $table->head[] = html_writer::link(
            new moodle_url('/local/zoomattendance/report.php', ['id' => $cmid]),
            format_string($activity->cm->name)
        );
    }
    foreach ($users as $userid => $user) {
        $row = [html_writer::link(new moodle_url(
            '/local/zoomattendance/user.php',
            ['course' => $course->id, 'user' => $userid]
        ), fullname($user))];
        foreach ($activities as $activity) {
            $row[] = renderer::summary_cell($activity->stats[$userid] ?? null);
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
    echo $output->download_dataformat_selector(
        get_string('download'),
        $url->out_omit_querystring(),
        'download',
        ['id' => $course->id, 'group' => $groupid]
    );
}
echo $output->footer();
