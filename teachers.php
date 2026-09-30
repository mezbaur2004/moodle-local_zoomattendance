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

use local_zoomattendance\local\settings;
use local_zoomattendance\local\teacher_summary;
use local_zoomattendance\output\renderer;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
// Managers see every teacher; a teacher sees only themself.
$canviewall = has_capability('local/zoomattendance:viewteacherreports', $context);
if (!$canviewall) {
    require_capability('local/zoomattendance:viewownteacher', $context);
}

$url = new moodle_url('/local/zoomattendance/teachers.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('teacherattendance', 'local_zoomattendance'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$tracking = settings::teacher_tracking();
$summary = $tracking ? teacher_summary::build($course, $canviewall ? null : (int) $USER->id) : null;

if ($download !== '' && $summary && $summary->activities) {
    $columns = ['fullname' => get_string('teacher', 'local_zoomattendance')];
    foreach ($summary->activities as $activity) {
        foreach ($activity->columns as $occurrenceid => $occurrence) {
            $columns['o' . $occurrenceid] = format_string($activity->cm->name, true, ['escape' => false]) . ' – ' .
                userdate($occurrence->timestart, get_string('strftimedatetimeshort', 'langconfig'));
            $note = renderer::column_note($summary, $activity->states[$occurrenceid], $occurrenceid);
            if ($note !== '') {
                $columns['o' . $occurrenceid] .= ' (' . $note . ')';
            }
        }
    }
    $columns['overall'] = get_string('courseoverall', 'local_zoomattendance');
    $rows = [];
    foreach ($summary->users as $userid => $user) {
        $record = ['fullname' => fullname($user)];
        foreach ($summary->activities as $activity) {
            foreach ($activity->columns as $occurrenceid => $occurrence) {
                $row = $summary->cells[$userid][$occurrenceid] ?? null;
                $record['o' . $occurrenceid] = $row ? renderer::teacher_text(
                    $row,
                    $activity->states[$occurrenceid],
                    isset($summary->selflinkers[$userid])
                ) : '';
            }
        }
        $record['overall'] = renderer::overall($summary->overall[$userid] ?? null);
        $rows[] = $record;
    }
    \core\dataformat::download_data(
        clean_filename($course->shortname . '-teacherattendance'),
        $download,
        $columns,
        $rows
    );
    die();
}

/** @var renderer $output */
$output = $PAGE->get_renderer('local_zoomattendance');
echo $output->header();
echo $output->heading(get_string('teacherattendance', 'local_zoomattendance'));

if (!$tracking) {
    echo $output->notification(get_string('teachertrackingoff', 'local_zoomattendance'), 'info');
} else {
    $thresholds = settings::teacher();
    echo html_writer::tag('p', get_string('teacherthresholdsinfo', 'local_zoomattendance', (object) [
        'present' => $thresholds->presentpct,
        'grace' => $thresholds->lategracemins,
        'partial' => $thresholds->latepct,
    ]));
    if (!$summary->activities) {
        echo $output->notification(get_string('noteacherdata', 'local_zoomattendance'), 'info');
    } else {
        echo html_writer::tag('p', get_string('teachersummary_help', 'local_zoomattendance'), ['class' => 'text-muted']);
        echo $output->teacher_table($summary);
        echo $output->download_dataformat_selector(
            get_string('download'),
            $url->out_omit_querystring(),
            'download',
            ['id' => $course->id]
        );
    }
}
$overview = new moodle_url('/local/zoomattendance/teachersoverview.php', $canviewall ? [] : ['mine' => 1]);
echo html_writer::tag('p', html_writer::link(
    $overview,
    get_string($canviewall ? 'teachersoverview' : 'myteaching', 'local_zoomattendance')
));
echo $output->footer();
