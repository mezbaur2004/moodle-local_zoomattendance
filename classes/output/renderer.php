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
 * Renderer for attendance reports.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\output;

use html_table;
use html_writer;
use local_zoomattendance\local\attendance;
use local_zoomattendance\local\course_summary;
use local_zoomattendance\local\status;
use local_zoomattendance\local\summary;
use local_zoomattendance\local\sync;
use local_zoomattendance\local\teacher_attendance;
use local_zoomattendance\local\teacher_summary;
use moodle_url;

/**
 * Renders attendance tables.
 */
class renderer extends \plugin_renderer_base {
    /**
     * Seconds as h:mm.
     *
     * @param int|null $secs
     * @return string
     */
    public static function duration(?int $secs): string {
        if ($secs === null) {
            return '';
        }
        $minutes = intdiv($secs + 30, MINSECS);
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Percentage with one decimal.
     *
     * @param float|null $pct
     * @return string
     */
    public static function percentage(?float $pct): string {
        return $pct === null ? '' : format_float($pct, 1) . '%';
    }

    /**
     * Status and percentage as plain text, for downloads.
     *
     * @param string|null $status A status constant.
     * @param float|null $pct
     * @return string
     */
    public static function status_text(?string $status, ?float $pct): string {
        if ($status === null) {
            return '';
        }
        $label = get_string('status_' . $status, 'local_zoomattendance');
        if ($pct === null) {
            return $label;
        }
        return get_string('summarycell', 'local_zoomattendance', (object) [
            'status' => $label,
            'pct' => self::percentage($pct),
        ]);
    }

    /**
     * Status badge followed by the percentage.
     *
     * @param string|null $status A status constant.
     * @param float|null $pct
     * @return string
     */
    public function status_cell(?string $status, ?float $pct): string {
        if ($status === null) {
            return '';
        }
        return html_writer::span(
            trim($this->badge($status) . ' ' . self::percentage($pct)),
            'local-zoomattendance-status text-nowrap'
        );
    }

    /**
     * Overall percentage.
     *
     * @param summary|null $summary
     * @return string
     */
    public static function overall(?summary $summary): string {
        return $summary ? self::percentage($summary->percentage()) : '';
    }

    /**
     * Course attendance table: one column per evaluated occurrence, grouped by activity, and
     * the course overall percentage.
     *
     * @param course_summary $summary
     * @return string
     */
    public function course_table(course_summary $summary): string {
        $courseid = null;
        $top = [html_writer::tag('th', get_string('fullnameuser'), ['rowspan' => 2, 'class' => 'local-zoomattendance-name'])];
        $dates = [];
        foreach ($summary->activities as $cmid => $activity) {
            $courseid = $activity->cm->course;
            $top[] = html_writer::tag('th', html_writer::link(
                new moodle_url('/local/zoomattendance/report.php', ['id' => $cmid]),
                format_string($activity->cm->name)
            ), ['colspan' => count($activity->columns), 'class' => 'text-center']);
            foreach ($activity->columns as $occurrence) {
                $dates[] = html_writer::tag('th', html_writer::link(
                    new moodle_url('/local/zoomattendance/report.php', ['id' => $cmid, 'occurrence' => $occurrence->id]),
                    userdate($occurrence->timestart, get_string('strftimedatetimeshort', 'langconfig'))
                ), ['class' => 'text-nowrap']);
            }
        }
        $top[] = html_writer::tag('th', get_string('courseoverall', 'local_zoomattendance'), ['rowspan' => 2]);
        $head = html_writer::tag('tr', implode('', $top)) . html_writer::tag('tr', implode('', $dates));

        $body = '';
        foreach ($summary->users as $userid => $user) {
            $cells = [html_writer::tag('th', html_writer::link(new moodle_url(
                '/local/zoomattendance/user.php',
                ['course' => $courseid, 'user' => $userid]
            ), fullname($user)), ['class' => 'local-zoomattendance-name', 'scope' => 'row'])];
            foreach ($summary->activities as $activity) {
                foreach ($activity->columns as $occurrenceid => $occurrence) {
                    $row = $summary->cells[$userid][$occurrenceid] ?? null;
                    $cells[] = html_writer::tag('td', $row ? $this->status_cell($row->status, $row->percentage) : '–');
                }
            }
            $cells[] = html_writer::tag('td', self::overall($summary->overall[$userid] ?? null));
            $body .= html_writer::tag('tr', implode('', $cells));
        }

        $table = html_writer::tag('table', html_writer::tag('thead', $head) . html_writer::tag('tbody', $body), [
            'class' => 'generaltable table-sm local-zoomattendance-course',
        ]);
        return html_writer::div($table, 'table-responsive local-zoomattendance-scroll');
    }

    /**
     * Teacher attendance for one course: one row per class, in date order, and one column per
     * teacher, with each teacher's attendance and counts at the bottom.
     *
     * @param teacher_summary $summary
     * @return string
     */
    public function teacher_table(teacher_summary $summary): string {
        $head = [
            html_writer::tag('th', get_string('date'), ['scope' => 'col']),
            html_writer::tag('th', get_string('class', 'local_zoomattendance'), ['scope' => 'col']),
        ];
        foreach ($summary->users as $user) {
            $head[] = html_writer::tag('th', fullname($user), ['scope' => 'col']);
        }

        $body = '';
        foreach ($summary->classes as $class) {
            $occurrence = $class->occurrence;
            $label = html_writer::link(
                new moodle_url('/local/zoomattendance/report.php', ['id' => $class->cm->id, 'occurrence' => $occurrence->id]),
                format_string($class->cm->name)
            );
            $note = self::class_note($summary, $class->state, (int) $occurrence->id);
            if ($note !== '') {
                $label .= html_writer::div(s($note), 'small text-muted');
            }
            $cells = [
                html_writer::tag('th', s(self::window($occurrence)), ['scope' => 'row']),
                html_writer::tag('td', $label),
            ];
            foreach ($summary->users as $userid => $user) {
                $row = $summary->cells[$userid][$occurrence->id] ?? null;
                $cells[] = html_writer::tag('td', $row
                    ? $this->teacher_cell($row, $class->state, isset($summary->selflinked[$userid][$occurrence->id]))
                    : html_writer::span('–', '', ['title' => get_string('notexpectedteacher', 'local_zoomattendance')]));
            }
            $body .= html_writer::tag('tr', implode('', $cells));
        }

        $overall = [html_writer::tag('th', get_string('teacheroverall', 'local_zoomattendance'), [
            'scope' => 'row',
            'colspan' => 2,
        ])];
        $counts = [html_writer::tag('th', get_string('classescounted', 'local_zoomattendance'), [
            'scope' => 'row',
            'colspan' => 2,
        ])];
        foreach ($summary->users as $userid => $user) {
            $percentage = self::overall($summary->overall[$userid] ?? null) ?: '–';
            $overall[] = html_writer::tag('td', html_writer::tag('strong', $percentage));
            $counts[] = html_writer::tag('td', s(self::teacher_counts($summary->stats[$userid] ?? teacher_summary::empty_stats())));
        }
        $foot = html_writer::tag('tr', implode('', $overall)) . html_writer::tag('tr', implode('', $counts));

        $table = html_writer::tag('table', html_writer::tag('thead', html_writer::tag('tr', implode('', $head))) .
            html_writer::tag('tbody', $body) . html_writer::tag('tfoot', $foot), [
            'class' => 'generaltable table-sm local-zoomattendance-teachers',
        ]);
        return html_writer::div($table, 'table-responsive');
    }

    /**
     * What each teacher status means, with the site's teacher thresholds.
     *
     * @param \local_zoomattendance\local\settings $settings Teacher thresholds.
     * @return string
     */
    public function teacher_legend(\local_zoomattendance\local\settings $settings): string {
        $a = (object) [
            'present' => $settings->presentpct,
            'partial' => $settings->latepct,
            'grace' => $settings->lategracemins,
        ];
        $items = [
            status::PRESENT => get_string('legend_present', 'local_zoomattendance', $a),
            status::PARTIAL => get_string('legend_partial', 'local_zoomattendance', $a),
            status::ABSENT => get_string('legend_absent', 'local_zoomattendance', $a),
            teacher_attendance::STATE_NOTHELD => get_string('legend_notheld', 'local_zoomattendance'),
            attendance::STATE_EXCLUDED => get_string('legend_excluded', 'local_zoomattendance'),
            teacher_attendance::STATE_AWAITING => get_string('legend_awaiting', 'local_zoomattendance'),
            attendance::STATE_RESET => get_string('legend_reset', 'local_zoomattendance'),
        ];
        $list = '';
        foreach ($items as $status => $text) {
            $list .= html_writer::tag('li', $this->badge($status) . ' ' . s($text));
        }
        $list .= html_writer::tag('li', s(get_string('legend_minutes', 'local_zoomattendance', $a)));
        $list .= html_writer::tag('li', s(get_string('legend_selflinked', 'local_zoomattendance')));
        return html_writer::tag('details', html_writer::tag('summary', get_string('legend', 'local_zoomattendance')) .
            html_writer::tag('ul', $list, ['class' => 'list-unstyled mt-2 mb-0']), ['class' => 'mb-3']);
    }

    /**
     * Note under a class: not held, awaiting, reset, or who excluded it.
     *
     * @param teacher_summary $summary
     * @param string $state
     * @param int $occurrenceid
     * @return string Plain text.
     */
    public static function class_note(teacher_summary $summary, string $state, int $occurrenceid): string {
        switch ($state) {
            case teacher_attendance::STATE_NOTHELD:
                return get_string('note_notheld', 'local_zoomattendance');
            case teacher_attendance::STATE_AWAITING:
                return get_string('note_awaiting', 'local_zoomattendance');
            case attendance::STATE_RESET:
                return get_string('note_reset', 'local_zoomattendance');
            case attendance::STATE_EXCLUDED:
                $by = $summary->excludedby[$occurrenceid] ?? null;
                return $by ? get_string('excludedby', 'local_zoomattendance', fullname($by))
                    : get_string('status_excluded', 'local_zoomattendance');
        }
        return '';
    }

    /**
     * One teacher cell: a status badge and percentage, then late start, early leave and
     * self-link notes. Not held, excluded, awaiting and reset classes show their own badge.
     *
     * @param \stdClass $row From teacher_attendance::evaluate().
     * @param string $state The occurrence's teacher state.
     * @param bool $selflinked Whether the teacher's time here includes an identity they linked to themself.
     * @return string
     */
    public function teacher_cell(\stdClass $row, string $state, bool $selflinked): string {
        if ($state !== attendance::STATE_EVALUATED) {
            return $this->badge($state);
        }
        $output = $this->status_cell($row->status, $row->percentage);
        $notes = self::teacher_notes($row, $selflinked);
        if ($notes) {
            $output .= html_writer::div(s(implode(' · ', $notes)), 'small text-muted');
        }
        return $output;
    }

    /**
     * A teacher's counts as a sentence.
     *
     * @param int[] $stats From teacher_summary::empty_stats().
     * @return string
     */
    public static function teacher_counts(array $stats): string {
        return get_string('teachercounts', 'local_zoomattendance', (object) [
            'classes' => $stats['expected'],
            'present' => $stats[status::PRESENT],
            'partial' => $stats[status::PARTIAL],
            'absent' => $stats[status::ABSENT],
            'notheld' => $stats['notheld'],
        ]);
    }

    /**
     * Late start, early leave and self-link notes for a teacher row.
     *
     * @param \stdClass $row
     * @param bool $selflinked Whether the time includes an identity the teacher linked to themself.
     * @return string[] Plain text.
     */
    public static function teacher_notes(\stdClass $row, bool $selflinked): array {
        $notes = [];
        if ($row->latesecs >= MINSECS) {
            $notes[] = get_string('latestartmins', 'local_zoomattendance', intdiv($row->latesecs, MINSECS));
        }
        if ($row->earlysecs >= MINSECS) {
            $notes[] = get_string('earlyleavemins', 'local_zoomattendance', intdiv($row->earlysecs, MINSECS));
        }
        if ($selflinked) {
            $notes[] = get_string('selflinked', 'local_zoomattendance');
        }
        return $notes;
    }

    /**
     * Status or state label as a badge.
     *
     * @param string|null $status A status or attendance state constant.
     * @return string
     */
    public function badge(?string $status): string {
        if ($status === null) {
            return '';
        }
        $classes = [
            status::PRESENT => 'success',
            status::PARTIAL => 'warning',
            status::ABSENT => 'danger',
            status::INVALID => 'secondary',
            attendance::STATE_UPCOMING => 'info',
            attendance::STATE_NODATA => 'secondary',
            attendance::STATE_CANCELLED => 'secondary',
            attendance::STATE_EXCLUDED => 'secondary',
            attendance::STATE_RESET => 'secondary',
            attendance::STATE_EVALUATED => 'primary',
            teacher_attendance::STATE_NOTHELD => 'danger',
            teacher_attendance::STATE_AWAITING => 'secondary',
        ];
        $variant = $classes[$status] ?? 'secondary';
        return html_writer::span(
            get_string('status_' . $status, 'local_zoomattendance'),
            // Light badges need dark text to stay readable.
            "badge badge-{$variant} bg-{$variant} " . ($variant === 'secondary' ? 'text-dark' : 'text-white')
        );
    }

    /**
     * Occurrence window as text.
     *
     * @param \stdClass $occurrence
     * @return string
     */
    public static function window(\stdClass $occurrence): string {
        // Date, then both times in the same (user's) time format.
        $date = userdate($occurrence->timestart, get_string('strftimedatefullshort', 'langconfig'));
        $start = userdate($occurrence->timestart, get_string('strftimetime', 'langconfig'));
        $end = userdate($occurrence->timeend, get_string('strftimetime', 'langconfig'));
        return "$date, $start – $end";
    }

    /**
     * Occurrence list for one activity.
     *
     * @param attendance $attendance
     * @param \stdClass[] $evaluations From attendance::evaluate(), keyed by occurrence id.
     * @param moodle_url $baseurl
     * @param bool $canmanage
     * @param bool $masked Participant data is masked: no links to details.
     * @return string
     */
    public function occurrence_list(
        attendance $attendance,
        array $evaluations,
        moodle_url $baseurl,
        bool $canmanage,
        bool $masked
    ): string {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable local-zoomattendance-occurrences';
        $table->head = [
            get_string('occurrence', 'local_zoomattendance'),
            get_string('source', 'local_zoomattendance'),
            get_string('state', 'local_zoomattendance'),
            get_string('sessions', 'local_zoomattendance'),
            get_string('expected', 'local_zoomattendance'),
            get_string('status_present', 'local_zoomattendance'),
            get_string('status_partial', 'local_zoomattendance'),
            get_string('status_absent', 'local_zoomattendance'),
            get_string('notexpected', 'local_zoomattendance'),
            get_string('unmatched', 'local_zoomattendance'),
        ];
        if ($canmanage) {
            $table->head[] = get_string('actions');
        }
        foreach ($evaluations as $evaluation) {
            $occurrence = $evaluation->occurrence;
            $label = s(self::window($occurrence));
            if (!$masked) {
                $label = html_writer::link(new moodle_url($baseurl, ['occurrence' => $occurrence->id]), $label);
            }
            $evaluated = $evaluation->state === attendance::STATE_EVALUATED;
            $row = [
                $label,
                get_string('source_' . $occurrence->source, 'local_zoomattendance'),
                $this->badge($evaluation->state),
                $attendance->session_count($occurrence),
                count($evaluation->expected),
                $evaluated ? $evaluation->counts[status::PRESENT] : '',
                $evaluated ? $evaluation->counts[status::PARTIAL] : '',
                $evaluated ? $evaluation->counts[status::ABSENT] : '',
                count($evaluation->notexpected),
                count($evaluation->unmatched),
            ];
            if ($canmanage) {
                $actions = [$this->exclude_toggle($occurrence, $baseurl)];
                if (\local_zoomattendance\local\manual::can_set_window($occurrence)) {
                    $actions[] = html_writer::link(
                        new moodle_url('/local/zoomattendance/window.php', [
                            'id' => $attendance->cm->id,
                            'occurrence' => $occurrence->id,
                        ]),
                        get_string('setwindow', 'local_zoomattendance')
                    );
                }
                $row[] = implode(' · ', array_filter($actions));
            }
            $table->data[] = $row;
        }
        return html_writer::table($table);
    }

    /**
     * Exclude / include action link.
     *
     * @param \stdClass $occurrence
     * @param moodle_url $baseurl
     * @return string
     */
    protected function exclude_toggle(\stdClass $occurrence, moodle_url $baseurl): string {
        $status = (int) $occurrence->status;
        // Cancelled occurrences, and those from before a Zoom data reset, cannot be excluded.
        if (in_array($status, [sync::STATUS_CANCELLED, sync::STATUS_RESET], true)) {
            return '';
        }
        $action = $status === sync::STATUS_EXCLUDED ? 'include' : 'exclude';
        $url = new moodle_url($baseurl, ['action' => $action, 'target' => $occurrence->id, 'sesskey' => sesskey()]);
        return html_writer::link($url, get_string($action, 'local_zoomattendance'));
    }

    /**
     * Detail tables for one occurrence.
     *
     * @param attendance $attendance
     * @param \stdClass $evaluation
     * @param bool $canlink Whether the viewer may link unmatched participants to users.
     * @return string
     */
    public function occurrence_detail(attendance $attendance, \stdClass $evaluation, bool $canlink = false): string {
        $output = $this->heading(get_string('expectedusers', 'local_zoomattendance'), 4);
        $output .= $this->user_table($attendance, $evaluation->expected, true);
        if ($evaluation->notexpected) {
            $output .= $this->heading(get_string('notexpectedusers', 'local_zoomattendance'), 4);
            $output .= html_writer::tag('p', get_string('notexpected_help', 'local_zoomattendance'), ['class' => 'text-muted']);
            $output .= $this->user_table($attendance, $evaluation->notexpected, false);
        }
        if ($evaluation->unmatched) {
            $output .= $this->heading(get_string('unmatchedparticipants', 'local_zoomattendance'), 4);
            $output .= html_writer::tag('p', get_string('unmatched_help', 'local_zoomattendance'), ['class' => 'text-muted']);
            $table = new html_table();
            $table->head = [
                get_string('zoomname', 'local_zoomattendance'),
                get_string('firstjoin', 'local_zoomattendance'),
                get_string('lastleave', 'local_zoomattendance'),
                get_string('attended', 'local_zoomattendance'),
                get_string('percentage', 'local_zoomattendance'),
            ];
            if ($canlink) {
                $table->head[] = get_string('actions');
            }
            foreach ($evaluation->unmatched as $row) {
                $cells = [
                    format_string($row->displayname, true, ['context' => $attendance->context]),
                    self::time($row->firstjoin),
                    self::time($row->lastleave),
                    self::duration($row->attendedsecs),
                    self::percentage($row->percentage),
                ];
                if ($canlink) {
                    $cells[] = html_writer::link(
                        new moodle_url('/local/zoomattendance/link.php', [
                            'id' => $attendance->cm->id,
                            'key' => $row->result->identitykey,
                            'occurrence' => $evaluation->occurrence->id,
                        ]),
                        get_string('linktouser', 'local_zoomattendance')
                    );
                }
                $table->data[] = $cells;
            }
            $output .= html_writer::table($table);
        }
        return $output;
    }

    /**
     * Table of matched users.
     *
     * @param attendance $attendance
     * @param \stdClass[] $rows
     * @param bool $withstatus
     * @return string
     */
    protected function user_table(attendance $attendance, array $rows, bool $withstatus): string {
        if (!$rows) {
            return $this->notification(get_string('nousers', 'local_zoomattendance'), 'info', false);
        }
        $identityfields = $attendance->identity_fields();
        $table = new html_table();
        $table->head = [get_string('fullnameuser')];
        foreach ($identityfields as $field) {
            $table->head[] = \core_user\fields::get_display_name($field);
        }
        $table->head = array_merge($table->head, [
            get_string('firstjoin', 'local_zoomattendance'),
            get_string('lastleave', 'local_zoomattendance'),
            get_string('attended', 'local_zoomattendance'),
            get_string('percentage', 'local_zoomattendance'),
        ]);
        if ($withstatus) {
            $table->head[] = get_string('status', 'local_zoomattendance');
        }
        uasort($rows, function ($a, $b) {
            return strcmp(fullname($a->user), fullname($b->user));
        });
        foreach ($rows as $row) {
            $userurl = new moodle_url(
                '/local/zoomattendance/user.php',
                ['course' => $attendance->cm->course, 'user' => $row->user->id]
            );
            $name = html_writer::link($userurl, fullname($row->user));
            if ($row->weakmatch) {
                $name .= ' ' . html_writer::span(
                    get_string('weakmatch', 'local_zoomattendance'),
                    'badge badge-light bg-light text-dark',
                    ['title' => get_string('weakmatch_help', 'local_zoomattendance')]
                );
            }
            if ($row->manualmatch) {
                $name .= ' ' . html_writer::span(
                    get_string('manualmatch', 'local_zoomattendance'),
                    'badge badge-info bg-info text-white',
                    ['title' => get_string('manualmatch_help', 'local_zoomattendance')]
                );
            }
            // Teachers only reach this list for managers; their figures are on the teacher pages.
            if (!$withstatus && $attendance->is_teacher((int) $row->user->id)) {
                $name .= ' ' . html_writer::span(
                    get_string('teacher', 'local_zoomattendance'),
                    'badge badge-secondary bg-secondary text-dark',
                    ['title' => get_string('teacherbadge_help', 'local_zoomattendance')]
                );
            }
            $cells = [$name];
            foreach ($identityfields as $field) {
                $cells[] = s($row->user->$field ?? '');
            }
            $cells = array_merge($cells, [
                self::time($row->firstjoin),
                self::time($row->lastleave),
                self::duration($row->attendedsecs),
                self::percentage($row->percentage),
            ]);
            if ($withstatus) {
                $cells[] = $this->badge($row->status);
            }
            $table->data[] = $cells;
        }
        return html_writer::table($table);
    }

    /**
     * Time of day.
     *
     * @param int|null $time
     * @return string
     */
    public static function time(?int $time): string {
        return $time === null ? '' : userdate($time, get_string('strftimetime', 'langconfig'));
    }
}
