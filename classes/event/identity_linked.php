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
 * Identity linked event.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\event;

/**
 * A user linked an unmatched Zoom participant to a user.
 */
class identity_linked extends identity_event {
    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventidentitylinked', 'local_zoomattendance');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' linked an unmatched Zoom participant to " .
            "the user with id '$this->relateduserid' in the course with id '$this->courseid' (link id '$this->objectid').";
    }

    /**
     * Validate the data.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
