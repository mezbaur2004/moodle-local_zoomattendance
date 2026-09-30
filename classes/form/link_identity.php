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
 * Form linking an unmatched Zoom participant to a user.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Pick the enrolled user an unmatched Zoom participant really is.
 *
 * Custom data: 'users' (userid => label) and 'zoomname'.
 */
class link_identity extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        // The key is validated by the page (manual::is_unmatched_key()) before the form is used.
        foreach (['id' => PARAM_INT, 'occurrence' => PARAM_INT, 'key' => PARAM_RAW_TRIMMED] as $name => $type) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, $type);
        }

        $mform->addElement(
            'static',
            'zoomname',
            get_string('zoomname', 'local_zoomattendance'),
            s($this->_customdata['zoomname'])
        );
        $mform->addElement(
            'autocomplete',
            'userid',
            get_string('linkuser', 'local_zoomattendance'),
            $this->_customdata['users'],
            // Multiple mode starts empty; a single select would preselect the first user.
            ['multiple' => true, 'noselectionstring' => get_string('choosedots')]
        );
        $mform->addRule('userid', null, 'required', null, 'client');
        $mform->addHelpButton('userid', 'linkuser', 'local_zoomattendance');

        $this->add_action_buttons(true, get_string('linktouser', 'local_zoomattendance'));
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
        $userids = (array) ($data['userid'] ?? []);
        if (count($userids) !== 1) {
            $errors['userid'] = get_string('erroroneuser', 'local_zoomattendance');
        } else if (!isset($this->_customdata['users'][reset($userids)])) {
            $errors['userid'] = get_string('errornotenrolled', 'local_zoomattendance');
        }
        return $errors;
    }

    /**
     * The chosen user id.
     *
     * @return int|null
     */
    public function get_userid(): ?int {
        $data = $this->get_data();
        return $data ? (int) reset($data->userid) : null;
    }
}
