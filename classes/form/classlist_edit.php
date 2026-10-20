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
 * Form asking why someone is added to a class's list, or removed from it.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The reason for a change to a class's list, which the list and logs show with it.
 *
 * Custom data: action (add or remove).
 */
class classlist_edit extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $hidden = [
            'id' => PARAM_INT,
            'occurrence' => PARAM_INT,
            'user' => PARAM_INT,
            'action' => PARAM_ALPHA,
            'kind' => PARAM_ALPHA,
        ];
        foreach ($hidden as $name => $type) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, $type);
        }
        $label = get_string('classlistreason', 'local_zoomattendance');
        $mform->addElement('text', 'reason', $label, ['size' => 60, 'maxlength' => 255]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');
        $mform->addRule('reason', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('reason', 'classlistreason', 'local_zoomattendance');
        $remove = ($this->_customdata['action'] ?? '') === 'remove';
        $this->add_action_buttons(true, get_string($remove ? 'classlistremove' : 'classlistadd', 'local_zoomattendance'));
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
        if (trim((string) ($data['reason'] ?? '')) === '') {
            $errors['reason'] = get_string('required');
        }
        return $errors;
    }
}
