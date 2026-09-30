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
 * Per-activity attendance settings form.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_zoomattendance\local\settings;

/**
 * Per-activity overrides. An empty choice inherits the site default.
 */
class activitysettings extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $defaults = settings::site_defaults();
        $inherit = function ($value) {
            return get_string('inherit', 'local_zoomattendance', $value);
        };

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('select', 'enabled', get_string('enabled', 'local_zoomattendance'), [
            '' => $inherit(get_string($defaults->enabled ? 'yes' : 'no')),
            1 => get_string('yes'),
            0 => get_string('no'),
        ]);

        $percentages = ['' => null];
        foreach (range(0, 100, 5) as $pct) {
            $percentages[$pct] = $pct . '%';
        }
        $percentages[''] = $inherit($defaults->presentpct . '%');
        $mform->addElement('select', 'presentpct', get_string('presentpct', 'local_zoomattendance'), $percentages);
        $mform->addHelpButton('presentpct', 'presentpct', 'local_zoomattendance');
        $percentages[''] = $inherit($defaults->latepct . '%');
        $mform->addElement('select', 'latepct', get_string('latepct', 'local_zoomattendance'), $percentages);
        $mform->addHelpButton('latepct', 'latepct', 'local_zoomattendance');

        $mform->addElement('text', 'lategracemins', get_string('lategracemins', 'local_zoomattendance'), ['size' => 4]);
        $mform->setType('lategracemins', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('lategracemins', 'lategracemins', 'local_zoomattendance');
        $mform->addElement('static', 'lategracemins_inherit', '', $inherit($defaults->lategracemins));

        $mform->addElement('select', 'denominator', get_string('denominator', 'local_zoomattendance'), [
            '' => $inherit(get_string('denominator_' . $defaults->denominator, 'local_zoomattendance')),
            settings::DENOMINATOR_SCHEDULED => get_string('denominator_scheduled', 'local_zoomattendance'),
            settings::DENOMINATOR_ACTUAL => get_string('denominator_actual', 'local_zoomattendance'),
        ]);
        $mform->addHelpButton('denominator', 'denominator', 'local_zoomattendance');

        $this->add_action_buttons();
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
        $grace = $data['lategracemins'] ?? '';
        if ($grace !== '' && (!ctype_digit((string) $grace) || (int) $grace > 1440)) {
            $errors['lategracemins'] = get_string('errorgrace', 'local_zoomattendance');
        }
        $defaults = settings::site_defaults();
        $present = ($data['presentpct'] ?? '') === '' ? $defaults->presentpct : (int) $data['presentpct'];
        $late = ($data['latepct'] ?? '') === '' ? $defaults->latepct : (int) $data['latepct'];
        if ($late > $present) {
            $errors['latepct'] = get_string('errorlateabovepresent', 'local_zoomattendance');
        }
        return $errors;
    }
}
