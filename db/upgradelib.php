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
 * Upgrade helpers for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Unfreeze the classes 0.4.0 froze long after they ended with no student on the list.
 *
 * 0.4.0 froze every past class on its first sync, from whoever could see the activity that day,
 * so a class of a hidden activity got an empty student list for good. The sync freezes these
 * classes again if their activity is visible, and otherwise leaves them live.
 *
 * @return int Number of classes unfrozen.
 */
function local_zoomattendance_unfreeze_late_empty(): int {
    global $DB;
    $ids = $DB->get_fieldset_sql(
        "SELECT o.id
           FROM {local_zoomattendance_occ} o
          WHERE o.rosterfrozen > 0 AND o.rosterfrozen - o.timeend > :late
            AND NOT EXISTS (SELECT 1
                              FROM {local_zoomattendance_roster} r
                             WHERE r.occurrenceid = o.id AND r.kind = :student)",
        ['late' => 6 * HOURSECS, 'student' => 'student']
    );
    foreach (array_chunk($ids, 500) as $chunk) {
        $DB->delete_records_list('local_zoomattendance_roster', 'occurrenceid', $chunk);
        [$insql, $params] = $DB->get_in_or_equal($chunk);
        $DB->execute("UPDATE {local_zoomattendance_occ} SET rosterfrozen = 0 WHERE id $insql", $params);
    }
    return count($ids);
}

/**
 * Restored classes are never recomputed, but 0.4.0 could mark them for recompute, which made
 * every sync visit their activity again. Clear the mark.
 */
function local_zoomattendance_clear_restored_recompute(): void {
    global $DB;
    $DB->execute("UPDATE {local_zoomattendance_occ} SET timecomputed = restored WHERE restored > 0 AND timecomputed = 0");
}
