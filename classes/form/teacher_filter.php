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
 * Filter for the teacher attendance list.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Date range and category filter.
 *
 * Custom data: 'categories' (id => name; empty to hide the category filter).
 */
class teacher_filter extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'mine');
        $mform->setType('mine', PARAM_BOOL);
        $mform->addElement('date_selector', 'from', get_string('filterfrom', 'local_zoomattendance'));
        $mform->addElement('date_selector', 'to', get_string('filterto', 'local_zoomattendance'));
        if (!empty($this->_customdata['categories'])) {
            $mform->addElement(
                'autocomplete',
                'category',
                get_string('category'),
                [0 => get_string('all')] + $this->_customdata['categories']
            );
            $mform->setType('category', PARAM_INT);
        }
        $this->add_action_buttons(false, get_string('filter'));
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
        if ($data['to'] < $data['from']) {
            $errors['to'] = get_string('errordaterange', 'local_zoomattendance');
        }
        return $errors;
    }
}
