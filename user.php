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
 * One user's Zoom attendance across a course.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\local\attendance;
use local_zoomattendance\local\course_summary;
use local_zoomattendance\output\renderer;

$courseid = required_param('course', PARAM_INT);
$userid = optional_param('user', $USER->id, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$coursecontext = context_course::instance($course->id);
$ownview = (int) $userid === (int) $USER->id;
$masked = (bool) get_config('zoom', 'maskparticipantdata');

if ($ownview) {
    require_capability('local/zoomattendance:viewown', $coursecontext);
} else {
    require_capability('local/zoomattendance:viewreports', $coursecontext);
    if ($masked) {
        throw new moodle_exception('maskedinfo', 'local_zoomattendance');
    }
    if (!groups_user_groups_visible($course, $userid)) {
        throw new required_capability_exception($coursecontext, 'moodle/site:accessallgroups', 'nopermissions', '');
    }
}
$user = core_user::get_user($userid, '*', MUST_EXIST);

$url = new moodle_url('/local/zoomattendance/user.php', ['course' => $course->id, 'user' => $user->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('userattendance', 'local_zoomattendance', fullname($user)));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();
echo $output->heading(get_string('userattendance', 'local_zoomattendance', fullname($user)));

$capability = $ownview ? 'local/zoomattendance:viewown' : 'local/zoomattendance:viewreports';
$coursesummary = course_summary::build($course, 0, $user->id, $capability);
if (isset($coursesummary->overall[$user->id])) {
    echo html_writer::tag('p', get_string('courseoverall', 'local_zoomattendance') . ': ' .
        renderer::overall($coursesummary->overall[$user->id]), ['class' => 'lead']);
}

$shown = 0;
foreach (get_fast_modinfo($course)->get_instances_of('zoom') as $cm) {
    if (!$cm->uservisible) {
        continue;
    }
    $context = context_module::instance($cm->id);
    if (!has_capability($capability, $context)) {
        continue;
    }
    $attendance = new attendance($cm);
    if (!$attendance->settings->enabled) {
        continue;
    }
    $occurrences = $attendance->get_occurrences();
    $candidates = $attendance->get_candidates(0, [$user->id]);
    $results = $attendance->get_results(array_keys($occurrences), $user->id);

    $table = new html_table();
    $table->head = [
        get_string('occurrence', 'local_zoomattendance'),
        get_string('firstjoin', 'local_zoomattendance'),
        get_string('lastleave', 'local_zoomattendance'),
        get_string('attended', 'local_zoomattendance'),
        get_string('percentage', 'local_zoomattendance'),
        get_string('status', 'local_zoomattendance'),
    ];
    foreach ($occurrences as $occurrence) {
        $evaluation = $attendance->evaluate($occurrence, $candidates, $results[$occurrence->id] ?? []);
        $row = $evaluation->expected[$user->id] ?? $evaluation->notexpected[$user->id] ?? null;
        if (!$row) {
            continue;
        }
        $label = $row->status ?? ($evaluation->state === attendance::STATE_EVALUATED ? null : $evaluation->state);
        $table->data[] = [
            s(renderer::window($occurrence)),
            renderer::time($row->firstjoin),
            renderer::time($row->lastleave),
            renderer::duration($row->attendedsecs),
            renderer::percentage($row->percentage),
            $label ? $output->badge($label) : get_string('notexpected', 'local_zoomattendance'),
        ];
    }
    if (!$table->data) {
        continue;
    }
    $shown++;
    echo $output->heading(format_string($cm->name, true, ['context' => $context]), 3);
    echo html_writer::table($table);
}

if (!$shown) {
    echo $output->notification(get_string('nouserdata', 'local_zoomattendance'), 'info');
}
echo $output->footer();
