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
 * Base for changes a user makes to a class list.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\event;

/**
 * A user added someone to a class's list, or removed them from it: logged so managers can audit
 * changes that affect attendance figures.
 */
abstract class classlist_event extends occurrence_event {
    /**
     * Create the event for a change to a class's list.
     *
     * @param \stdClass $occurrence A local_zoomattendance_occ row.
     * @param \cm_info|\stdClass $cm The zoom course module.
     * @param int $userid Who was added or removed.
     * @param string $kind student or teacher.
     * @param string $reason
     * @return self
     */
    public static function create_for_user(\stdClass $occurrence, $cm, int $userid, string $kind, string $reason) {
        $event = static::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $occurrence->id,
            'relateduserid' => $userid,
            'other' => [
                'timestart' => (int) $occurrence->timestart,
                'timeend' => (int) $occurrence->timeend,
                'kind' => $kind,
                'reason' => $reason,
            ],
        ]);
        $event->add_record_snapshot('local_zoomattendance_occ', $occurrence);
        return $event;
    }

    /**
     * Link to the class list.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/zoomattendance/classlist.php', [
            'id' => $this->contextinstanceid,
            'occurrence' => $this->objectid,
        ]);
    }

    /**
     * Validation.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
