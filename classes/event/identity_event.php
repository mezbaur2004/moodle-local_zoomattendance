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
 * Base for changes to identity links.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\event;

/**
 * A user linked an unmatched Zoom participant to a user in a course, or removed the link.
 */
abstract class identity_event extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_zoomattendance_idmap';
    }

    /**
     * Create the event for a link.
     *
     * @param \stdClass $link A local_zoomattendance_idmap row.
     * @return self
     */
    public static function create_from_link(\stdClass $link) {
        $event = static::create([
            'context' => \context_course::instance($link->courseid),
            'objectid' => $link->id,
            'relateduserid' => $link->userid,
        ]);
        $event->add_record_snapshot('local_zoomattendance_idmap', $link);
        return $event;
    }

    /**
     * Link to the course attendance summary.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/zoomattendance/course.php', ['id' => $this->courseid]);
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_zoomattendance_idmap', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other fields mapping for restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
