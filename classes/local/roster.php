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
 * Frozen lists of the students and teachers expected at each class.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Freezes who was expected at a class once it is over.
 *
 * Until then the expected users come from the live enrolments and capabilities. Afterwards
 * the frozen list is used, so unenrolling or suspending a user, or changing their role, no
 * longer rewrites past attendance. A class is frozen once it ended longer ago than the Not held
 * delay, when its Zoom report has normally arrived.
 */
class roster {
    /** @var string Expected as a student (local/zoomattendance:betracked). */
    public const KIND_STUDENT = 'student';
    /** @var string Expected as a teacher (local/zoomattendance:betrackedteacher). */
    public const KIND_TEACHER = 'teacher';

    /**
     * Freeze the expected users of the instance's classes that ended long enough ago.
     *
     * @param \stdClass $instance A zoom_source::get_instances() record.
     * @param int|null $now Defaults to the current time.
     * @return int Number of classes frozen.
     */
    public static function freeze_due(\stdClass $instance, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $occurrences = $DB->get_records_select(
            'local_zoomattendance_occ',
            'zoomid = :zoomid AND rosterfrozen = 0 AND restored = 0 AND status <> :cancelled AND timeend <= :cutoff',
            [
                'zoomid' => $instance->id,
                'cancelled' => sync::STATUS_CANCELLED,
                'cutoff' => $now - settings::teacher_notheld_delay(),
            ]
        );
        if (!$occurrences) {
            return 0;
        }
        try {
            $cm = get_fast_modinfo($instance->course)->get_cm($instance->cmid);
        } catch (\moodle_exception $e) {
            return 0;
        }
        $attendance = new attendance($cm);
        $kinds = [
            self::KIND_STUDENT => $attendance->get_candidates(0, null, 'local/zoomattendance:betracked', false),
            self::KIND_TEACHER => (new teacher_attendance($attendance))->live_candidates(),
        ];

        $transaction = $DB->start_delegated_transaction();
        foreach ($occurrences as $occurrence) {
            $rows = [];
            foreach ($kinds as $kind => $candidates) {
                foreach ($candidates as $userid => $candidate) {
                    if (attendance::covers($candidate, $occurrence)) {
                        $rows[] = [
                            'occurrenceid' => $occurrence->id,
                            'userid' => $userid,
                            'kind' => $kind,
                            'timecreated' => $now,
                        ];
                    }
                }
            }
            if ($rows) {
                $DB->insert_records('local_zoomattendance_roster', $rows);
            }
            $DB->set_field('local_zoomattendance_occ', 'rosterfrozen', $now, ['id' => $occurrence->id]);
        }
        $transaction->allow_commit();
        return count($occurrences);
    }

    /**
     * Frozen expected users of an instance.
     *
     * @param int $zoomid
     * @param string $kind One of the KIND_* constants.
     * @return array occurrence id => [userid => true]
     */
    public static function load(int $zoomid, string $kind): array {
        global $DB;
        $rs = $DB->get_recordset_sql(
            "SELECT r.id, r.occurrenceid, r.userid
               FROM {local_zoomattendance_roster} r
               JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
              WHERE o.zoomid = :zoomid AND r.kind = :kind",
            ['zoomid' => $zoomid, 'kind' => $kind]
        );
        $roster = [];
        foreach ($rs as $row) {
            $roster[(int) $row->occurrenceid][(int) $row->userid] = true;
        }
        $rs->close();
        return $roster;
    }

    /**
     * Whether a user is on a frozen list of one of a course's classes.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function in_course(int $courseid, int $userid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {local_zoomattendance_roster} r
               JOIN {local_zoomattendance_occ} o ON o.id = r.occurrenceid
               JOIN {zoom} z ON z.id = o.zoomid
              WHERE z.course = :courseid AND r.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid]
        );
    }

    /**
     * Delete the frozen lists of some occurrences, before the occurrences themselves go.
     *
     * @param int[] $occurrenceids
     */
    public static function delete_for_occurrences(array $occurrenceids): void {
        global $DB;
        if ($occurrenceids) {
            $DB->delete_records_list('local_zoomattendance_roster', 'occurrenceid', $occurrenceids);
        }
    }
}
