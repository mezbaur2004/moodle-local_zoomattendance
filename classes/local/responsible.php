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
 * Teachers responsible for a Zoom activity.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * The teachers expected at an activity's classes. With none chosen, every teacher of the course
 * is expected, as before; choosing some limits teacher attendance to them.
 */
class responsible {
    /**
     * The responsible teachers of an activity.
     *
     * @param int $cmid
     * @return int[] User ids; empty when every teacher is expected.
     */
    public static function get(int $cmid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'local_zoomattendance_teacher',
            'userid',
            'cmid = :cmid',
            ['cmid' => $cmid]
        ));
    }

    /**
     * Replace the responsible teachers of an activity.
     *
     * @param int $cmid
     * @param int[] $userids Empty to expect every teacher again.
     */
    public static function set(int $cmid, array $userids): void {
        global $DB, $USER;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        $current = self::get($cmid);
        $transaction = $DB->start_delegated_transaction();
        $removed = array_diff($current, $userids);
        if ($removed) {
            [$insql, $params] = $DB->get_in_or_equal($removed, SQL_PARAMS_NAMED);
            $params['cmid'] = $cmid;
            $DB->delete_records_select('local_zoomattendance_teacher', "cmid = :cmid AND userid $insql", $params);
        }
        $now = time();
        foreach (array_diff($userids, $current) as $userid) {
            $DB->insert_record('local_zoomattendance_teacher', (object) [
                'cmid' => $cmid,
                'userid' => $userid,
                'timecreated' => $now,
                'usermodified' => (int) $USER->id,
            ]);
        }
        $transaction->allow_commit();
        data_version::bump_course((int) $DB->get_field('course_modules', 'course', ['id' => $cmid]));
    }
}
