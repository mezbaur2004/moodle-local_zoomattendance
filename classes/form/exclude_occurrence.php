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
 * Form asking why a class is excluded.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The reason for excluding a class, which reports and logs show with it.
 */
class exclude_occurrence extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'occurrence'] as $name) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, PARAM_INT);
        }
        $label = get_string('excludereason', 'local_zoomattendance');
        $mform->addElement('text', 'reason', $label, ['size' => 60, 'maxlength' => 255]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', null, 'required', null, 'client');
        $mform->addRule('reason', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('reason', 'excludereason', 'local_zoomattendance');
        $this->add_action_buttons(true, get_string('exclude', 'local_zoomattendance'));
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
