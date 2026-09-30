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
 * Set or revert the window of an inferred occurrence.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\occurrence_window;
use local_zoomattendance\local\manual;
use local_zoomattendance\local\sync;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$occurrenceid = required_param('occurrence', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/zoomattendance:manage', $context);

$occurrence = $DB->get_record('local_zoomattendance_occ', ['id' => $occurrenceid, 'zoomid' => $cm->instance], '*', MUST_EXIST);
if (!manual::can_set_window($occurrence)) {
    throw new moodle_exception('errornotinferred', 'local_zoomattendance');
}

$url = new moodle_url('/local/zoomattendance/window.php', ['id' => $cm->id, 'occurrence' => $occurrence->id]);
$reporturl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('setwindow', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

if ($action === 'revert') {
    require_sesskey();
    $done = manual::revert_window($occurrence);
    redirect($reporturl, get_string($done ? 'windowreverted' : 'recomputebusy', 'local_zoomattendance'));
}

$form = new occurrence_window($url);
$form->set_data([
    'id' => $cm->id,
    'occurrence' => $occurrence->id,
    'timestart' => $occurrence->timestart,
    'timeend' => $occurrence->timeend,
]);
if ($form->is_cancelled()) {
    redirect($reporturl);
} else if ($data = $form->get_data()) {
    $done = manual::set_window($occurrence, (int) $data->timestart, (int) $data->timeend);
    redirect($reporturl, get_string($done ? 'windowsaved' : 'recomputebusy', 'local_zoomattendance'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setwindow', 'local_zoomattendance'));
echo html_writer::tag('p', get_string('setwindowintro', 'local_zoomattendance', (object) [
    'window' => renderer::window($occurrence),
    'source' => get_string('source_' . $occurrence->source, 'local_zoomattendance'),
]));
$form->display();
if ($occurrence->source === sync::SOURCE_MANUAL) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'revert', 'sesskey' => sesskey()]),
        get_string('revertwindow', 'local_zoomattendance'),
        'post'
    );
}
echo $OUTPUT->footer();
