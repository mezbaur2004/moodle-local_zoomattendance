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
 * Course attendance: one column per evaluated occurrence and a course overall.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Collects the evaluated occurrences of a course's tracked Zoom activities.
 */
class course_summary {
    /** @var \stdClass[] cmid => {cm, settings, columns: occurrence id => occurrence}. Only activities with columns. */
    public $activities = [];
    /** @var \stdClass[] userid => user, expected in at least one column, sorted by name. */
    public $users = [];
    /** @var \stdClass[][] userid => occurrence id => expected row from attendance::evaluate(). */
    public $cells = [];
    /** @var summary[] userid => overall attendance over every column. */
    public $overall = [];
    /** @var settings Thresholds for the overall status: the site defaults. */
    public $settings;

    /**
     * Build the summary.
     *
     * An activity is included when the viewer can see it and view its reports and tracking is
     * enabled; an occurrence becomes a column when it is evaluated (past, with session data,
     * not excluded or cancelled).
     *
     * @param \stdClass $course
     * @param int $groupid Current group (0 for all).
     * @param int|null $userid Only this user, for the user page.
     * @param string $capability Capability needed in each activity.
     * @return self
     */
    public static function build(
        \stdClass $course,
        int $groupid = 0,
        ?int $userid = null,
        string $capability = 'local/zoomattendance:viewreports'
    ): self {
        $summary = new self();
        $summary->settings = settings::site_defaults();
        foreach (get_fast_modinfo($course)->get_instances_of('zoom') as $cm) {
            if (!$cm->uservisible || !has_capability($capability, \context_module::instance($cm->id))) {
                continue;
            }
            $attendance = new attendance($cm);
            if (!$attendance->settings->enabled) {
                continue;
            }
            $occurrences = $attendance->get_occurrences();
            $candidates = $attendance->get_candidates($groupid, $userid === null ? null : [$userid]);
            $results = $attendance->get_results(array_keys($occurrences), $userid);
            $columns = [];
            foreach ($occurrences as $occurrence) {
                if ($attendance->state($occurrence) !== attendance::STATE_EVALUATED) {
                    continue;
                }
                $columns[$occurrence->id] = $occurrence;
                $evaluation = $attendance->evaluate($occurrence, $candidates, $results[$occurrence->id] ?? [], $groupid);
                foreach ($evaluation->expected as $id => $row) {
                    $summary->users[$id] = $row->user;
                    $summary->cells[$id][$occurrence->id] = $row;
                    $summary->overall[$id] = $summary->overall[$id] ?? new summary();
                    $summary->overall[$id]->add($row, $evaluation, $attendance->settings);
                }
            }
            if ($columns) {
                $summary->activities[$cm->id] = (object) [
                    'cm' => $cm,
                    'settings' => $attendance->settings,
                    'columns' => $columns,
                ];
            }
        }
        uasort($summary->users, function ($a, $b) {
            return strcmp(fullname($a), fullname($b));
        });
        return $summary;
    }
}
