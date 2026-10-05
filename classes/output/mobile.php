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
 * Moodle app views.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\output;

use local_zoomattendance\local\course_summary;
use local_zoomattendance\local\headcount;
use local_zoomattendance\local\settings;
use local_zoomattendance\local\status;

/**
 * Views for the Moodle app (see db/mobile.php).
 *
 * All text goes to the app as data and is shown with Angular interpolation, never as markup, so
 * names typed by Zoom participants cannot inject anything.
 */
class mobile {
    /** @var int Classes listed at most. */
    public const LIMIT = 30;

    /**
     * Courses where the "Zoom attendance" tab appears: the user's courses with a Zoom activity
     * where they see their own attendance or the reports.
     *
     * @param array $args
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $DB, $USER;
        $withzoom = array_flip($DB->get_fieldset_sql('SELECT DISTINCT course FROM {zoom}'));
        $courses = [];
        foreach (enrol_get_users_courses((int) $USER->id, true, 'id') as $course) {
            if (!isset($withzoom[$course->id])) {
                continue;
            }
            $context = \context_course::instance($course->id);
            if (has_any_capability(['local/zoomattendance:viewown', 'local/zoomattendance:viewreports'], $context)) {
                $courses[] = (int) $course->id;
            }
        }
        return ['restrict' => ['courses' => $courses], 'javascript' => ''];
    }

    /**
     * The course tab.
     *
     * @param array $args With courseid.
     * @return array
     */
    public static function mobile_course_view(array $args): array {
        $course = get_course((int) $args['courseid']);
        require_login($course, false, null, false, true);
        $data = self::course_data($course);
        return [
            'templates' => [['id' => 'main', 'html' => self::course_template()]],
            'javascript' => '',
            'otherdata' => [
                'overall' => $data['overall'],
                'overallcolor' => $data['overallcolor'],
                'mine' => json_encode($data['mine']),
                'classes' => json_encode($data['classes']),
                'empty' => (int) $data['empty'],
            ],
        ];
    }

    /**
     * What the course tab shows the current user.
     *
     * @param \stdClass $course
     * @return array With overall ('' when none), overallcolor, mine and classes (lists of rows
     *     with name, date, label, color and detail) and empty.
     */
    public static function course_data(\stdClass $course): array {
        global $USER;
        $context = \context_course::instance($course->id);
        $masked = (bool) get_config('zoom', 'maskparticipantdata');
        $data = ['overall' => '', 'overallcolor' => 'medium', 'mine' => [], 'classes' => []];
        $format = get_string('strftimedatetimeshort', 'langconfig');

        if (has_capability('local/zoomattendance:viewown', $context)) {
            $userid = (int) $USER->id;
            $summary = course_summary::build($course, 0, $userid, 'local/zoomattendance:viewown');
            if (isset($summary->overall[$userid]) && $summary->overall[$userid]->percentage() !== null) {
                $pct = $summary->overall[$userid]->percentage();
                $data['overall'] = renderer::percentage($pct);
                $data['overallcolor'] = self::color($pct, settings::site_defaults());
            }
            foreach (self::columns($summary) as $occurrenceid => [$cmname, $occurrence]) {
                $cell = $summary->cells[$userid][$occurrenceid] ?? null;
                if (!$cell) {
                    continue;
                }
                $data['mine'][] = [
                    'name' => $cmname,
                    'date' => userdate($occurrence->timestart, $format),
                    'label' => renderer::status_text($cell->status, $cell->percentage),
                    'color' => self::status_color($cell->status),
                    'detail' => '',
                ];
            }
            $data['mine'] = array_slice(array_reverse($data['mine']), 0, self::LIMIT);
        }

        if (!$masked && has_capability('local/zoomattendance:viewreports', $context)) {
            $visible = headcount::visible_cells($course);
            if ($visible && $visible['summary']) {
                $counts = headcount::from_cells($visible['cells']);
                foreach (self::columns($visible['summary']) as $occurrenceid => [$cmname, $occurrence]) {
                    if (empty($counts[$occurrenceid]['expected'])) {
                        continue;
                    }
                    $a = headcount::string_data($counts[$occurrenceid]);
                    $data['classes'][] = [
                        'name' => $cmname,
                        'date' => userdate($occurrence->timestart, $format),
                        'label' => get_string('headcount_present', 'local_zoomattendance', $a),
                        'color' => self::color(100 * $a->overall / $a->expected, settings::site_defaults()),
                        'detail' => get_string('headcount_rest', 'local_zoomattendance', $a),
                    ];
                }
                $data['classes'] = array_slice(array_reverse($data['classes']), 0, self::LIMIT);
            }
        }
        $data['empty'] = $data['overall'] === '' && !$data['mine'] && !$data['classes'];
        return $data;
    }

    /**
     * Evaluated classes of a summary, oldest first.
     *
     * @param course_summary $summary
     * @return array occurrence id => [activity name, occurrence]
     */
    protected static function columns(course_summary $summary): array {
        $columns = [];
        foreach ($summary->activities as $activity) {
            $name = format_string($activity->cm->name, true, ['context' => \context_module::instance($activity->cm->id)]);
            foreach ($activity->columns as $occurrenceid => $occurrence) {
                $columns[$occurrenceid] = [$name, $occurrence];
            }
        }
        uasort($columns, function ($a, $b) {
            return (int) $a[1]->timestart <=> (int) $b[1]->timestart;
        });
        return $columns;
    }

    /**
     * App colour of a percentage, as the web pages colour it.
     *
     * @param float $pct
     * @param settings $settings
     * @return string An Ionic colour.
     */
    public static function color(float $pct, settings $settings): string {
        if ($pct >= $settings->presentpct) {
            return 'success';
        }
        return $pct >= $settings->latepct ? 'warning' : 'danger';
    }

    /**
     * App colour of a status.
     *
     * @param string|null $status
     * @return string An Ionic colour.
     */
    protected static function status_color(?string $status): string {
        $colors = [status::PRESENT => 'success', status::PARTIAL => 'warning', status::ABSENT => 'danger'];
        return $colors[$status] ?? 'medium';
    }

    /**
     * The course tab template: data only, through Angular interpolation.
     *
     * @return string
     */
    protected static function course_template(): string {
        return <<<'HTML'
<ion-list>
    <ion-item *ngIf="CONTENT_OTHERDATA.empty">
        <ion-label>{{ 'plugin.local_zoomattendance.nouserdata' | translate }}</ion-label>
    </ion-item>
    <ion-item *ngIf="CONTENT_OTHERDATA.overall">
        <ion-label><h2>{{ 'plugin.local_zoomattendance.courseoverall' | translate }}</h2></ion-label>
        <ion-badge slot="end" [color]="CONTENT_OTHERDATA.overallcolor">{{ CONTENT_OTHERDATA.overall }}</ion-badge>
    </ion-item>
    <ng-container *ngIf="CONTENT_OTHERDATA.mine.length">
        <ion-item-divider><ion-label>{{ 'plugin.local_zoomattendance.myattendance' | translate }}</ion-label></ion-item-divider>
        <ion-item *ngFor="let row of CONTENT_OTHERDATA.mine">
            <ion-label><h3>{{ row.name }}</h3><p>{{ row.date }}</p></ion-label>
            <ion-badge slot="end" [color]="row.color">{{ row.label }}</ion-badge>
        </ion-item>
    </ng-container>
    <ng-container *ngIf="CONTENT_OTHERDATA.classes.length">
        <ion-item-divider><ion-label>{{ 'plugin.local_zoomattendance.students' | translate }}</ion-label></ion-item-divider>
        <ion-item *ngFor="let row of CONTENT_OTHERDATA.classes">
            <ion-label><h3>{{ row.name }}</h3><p>{{ row.date }}</p><p>{{ row.detail }}</p></ion-label>
            <ion-badge slot="end" [color]="row.color">{{ row.label }}</ion-badge>
        </ion-item>
    </ng-container>
</ion-list>
HTML;
    }
}
