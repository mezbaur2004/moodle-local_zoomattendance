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
 * Form setting the window of an inferred occurrence.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Start and end of an occurrence whose window was inferred from Zoom sessions.
 */
class occurrence_window extends \moodleform {
    /** @var int Longest window a teacher may set, in seconds. */
    public const MAX_DURATION = DAYSECS;

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'occurrence'] as $name) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, PARAM_INT);
        }
        $mform->addElement(
            'date_time_selector',
            'timestart',
            get_string('windowstart', 'local_zoomattendance'),
            ['step' => 1]
        );
        $mform->addElement(
            'date_time_selector',
            'timeend',
            get_string('windowend', 'local_zoomattendance'),
            ['step' => 1]
        );
        $mform->addHelpButton('timestart', 'windowstart', 'local_zoomattendance');
        $this->add_action_buttons(true, get_string('savewindow', 'local_zoomattendance'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if ($data['timeend'] <= $data['timestart']) {
            $errors['timeend'] = get_string('errorwindoworder', 'local_zoomattendance');
        } else if ($data['timeend'] - $data['timestart'] > self::MAX_DURATION) {
            $errors['timeend'] = get_string('errorwindowlength', 'local_zoomattendance');
        }
        return $errors;
    }
}
