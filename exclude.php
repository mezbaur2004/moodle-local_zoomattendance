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
 * Exclude a class from the figures, with a reason.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\exclude_occurrence;
use local_zoomattendance\local\manual;
use local_zoomattendance\local\sync;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$occurrenceid = required_param('occurrence', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!manual::can_exclude($context)) {
    require_capability('local/zoomattendance:manage', $context);
    require_capability('local/zoomattendance:excludetracked', $context);
}

$occurrence = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrenceid, 'zoomid' => $cm->instance], '*', MUST_EXIST);
$reporturl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
if (in_array((int) $occurrence->status, [sync::STATUS_CANCELLED, sync::STATUS_RESET, sync::STATUS_EXCLUDED], true)) {
    redirect($reporturl);
}

$url = new moodle_url('/local/zoomattendance/exclude.php', ['id' => $cm->id, 'occurrence' => $occurrence->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('exclude', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$form = new exclude_occurrence($url);
$form->set_data(['id' => $cm->id, 'occurrence' => $occurrence->id]);
if ($form->is_cancelled()) {
    redirect($reporturl);
} else if ($data = $form->get_data()) {
    manual::set_excluded($occurrence, true, $data->reason);
    redirect($reporturl, get_string('excluded', 'local_zoomattendance'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('excludeclass', 'local_zoomattendance', renderer::window($occurrence)));
echo html_writer::tag('p', get_string('excludeintro', 'local_zoomattendance'));
$form->display();
echo $OUTPUT->footer();
