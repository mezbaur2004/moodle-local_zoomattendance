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
 * Admin settings for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Pedago Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_zoomattendance', new lang_string('pluginname', 'local_zoomattendance'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_zoomattendance/defaultenabled',
            new lang_string('defaultenabled', 'local_zoomattendance'),
            new lang_string('defaultenabled_desc', 'local_zoomattendance'),
            0
        ));

        $settings->add(new admin_setting_heading(
            'local_zoomattendance/thresholds',
            new lang_string('thresholds', 'local_zoomattendance'),
            new lang_string('thresholds_desc', 'local_zoomattendance')
        ));
        $percentages = array_combine(range(0, 100, 5), range(0, 100, 5));
        $settings->add(new admin_setting_configselect(
            'local_zoomattendance/presentpct',
            new lang_string('presentpct', 'local_zoomattendance'),
            new lang_string('presentpct_desc', 'local_zoomattendance'),
            75,
            $percentages
        ));
        $settings->add(new admin_setting_configselect(
            'local_zoomattendance/latepct',
            new lang_string('latepct', 'local_zoomattendance'),
            new lang_string('latepct_desc', 'local_zoomattendance'),
            50,
            $percentages
        ));
        $settings->add(new admin_setting_configtext(
            'local_zoomattendance/lategracemins',
            new lang_string('lategracemins', 'local_zoomattendance'),
            new lang_string('lategracemins_desc', 'local_zoomattendance'),
            10,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configselect(
            'local_zoomattendance/denominator',
            new lang_string('denominator', 'local_zoomattendance'),
            new lang_string('denominator_desc', 'local_zoomattendance'),
            'scheduled',
            [
                'scheduled' => new lang_string('denominator_scheduled', 'local_zoomattendance'),
                'actual' => new lang_string('denominator_actual', 'local_zoomattendance'),
            ]
        ));

        $settings->add(new admin_setting_heading(
            'local_zoomattendance/matching',
            new lang_string('matching', 'local_zoomattendance'),
            new lang_string('matching_desc', 'local_zoomattendance')
        ));
        $settings->add(new admin_setting_configtext(
            'local_zoomattendance/earlymarginmins',
            new lang_string('earlymarginmins', 'local_zoomattendance'),
            new lang_string('earlymarginmins_desc', 'local_zoomattendance'),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_zoomattendance/latemarginmins',
            new lang_string('latemarginmins', 'local_zoomattendance'),
            new lang_string('latemarginmins_desc', 'local_zoomattendance'),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_zoomattendance/clustergapmins',
            new lang_string('clustergapmins', 'local_zoomattendance'),
            new lang_string('clustergapmins_desc', 'local_zoomattendance'),
            30,
            PARAM_INT
        ));
    }
}
