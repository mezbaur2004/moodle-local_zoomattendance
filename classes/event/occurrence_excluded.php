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
 * Occurrence excluded event.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\event;

/**
 * A user excluded an occurrence of a Zoom activity.
 */
class occurrence_excluded extends occurrence_event {
    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventoccurrenceexcluded', 'local_zoomattendance');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        $reason = isset($this->other['reason']) ? " Reason: '" . s($this->other['reason']) . "'." : '';
        return "The user with id '$this->userid' excluded the occurrence with id '$this->objectid' " .
            "of the Zoom activity with course module id '$this->contextinstanceid'." . $reason;
    }
}
