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
 * Base for changes a user makes to an occurrence.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\event;

/**
 * A user changed an occurrence of a Zoom activity: logged so managers can audit changes that
 * affect attendance figures.
 */
abstract class occurrence_event extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_zoomattendance_occ';
    }

    /**
     * Create the event for an occurrence.
     *
     * @param \stdClass $occurrence A local_zoomattendance_occ row.
     * @param \stdClass $cm The zoom course module.
     * @return self
     */
    public static function create_from_occurrence(\stdClass $occurrence, \stdClass $cm) {
        $other = ['timestart' => (int) $occurrence->timestart, 'timeend' => (int) $occurrence->timeend];
        if (!empty($occurrence->excludereason)) {
            $other['reason'] = (string) $occurrence->excludereason;
        }
        $event = static::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $occurrence->id,
            'other' => $other,
        ]);
        $event->add_record_snapshot('local_zoomattendance_occ', $occurrence);
        return $event;
    }

    /**
     * Link to the activity's attendance report.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/zoomattendance/report.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_zoomattendance_occ', 'restore' => \core\event\base::NOT_MAPPED];
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
