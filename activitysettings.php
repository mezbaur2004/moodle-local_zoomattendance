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
 * Per-activity attendance settings.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_zoomattendance\form\activitysettings;
use local_zoomattendance\local\settings;
use local_zoomattendance\local\source\zoom_source;
use local_zoomattendance\local\sync;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'zoom');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('local/zoomattendance:manage', $context);

$url = new moodle_url('/local/zoomattendance/activitysettings.php', ['id' => $cm->id]);
$reporturl = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($cm->name) . ': ' . get_string('attendancesettings', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$record = $DB->get_record('local_zoomatt_settings', ['cmid' => $cm->id]);
$form = new activitysettings($url);
$current = ['id' => $cm->id];
foreach (['enabled', 'presentpct', 'latepct', 'lategracemins', 'denominator'] as $field) {
    $current[$field] = ($record && $record->$field !== null) ? $record->$field : '';
}
$form->set_data($current);

if ($form->is_cancelled()) {
    redirect($reporturl);
} else if ($data = $form->get_data()) {
    $new = (object) ['cmid' => $cm->id, 'timemodified' => time()];
    foreach (['enabled', 'presentpct', 'latepct', 'lategracemins'] as $field) {
        $new->$field = ($data->$field ?? '') === '' ? null : (int) $data->$field;
    }
    $new->denominator = ($data->denominator ?? '') === '' ? null : $data->denominator;
    if ($record) {
        $new->id = $record->id;
        $DB->update_record('local_zoomatt_settings', $new);
    } else {
        $DB->insert_record('local_zoomatt_settings', $new);
    }

    $message = get_string('changessaved');
    if (settings::for_cm($cm->id)->enabled) {
        $instances = zoom_source::get_instances(null, (int) $cm->instance);
        if ($instances && !sync::sync_instance(reset($instances))) {
            $message = get_string('recomputebusy', 'local_zoomattendance');
        }
    }
    redirect($reporturl, $message);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attendancesettings', 'local_zoomattendance'));
$form->display();
echo $OUTPUT->footer();
