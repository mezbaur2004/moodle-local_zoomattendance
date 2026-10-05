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
 * Behat steps for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @category   test
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;

/**
 * Steps creating Zoom classes and opening the plugin's pages.
 *
 * mod_zoom creates meetings through the Zoom API, so the classes come from the plugin's own test
 * generator, which writes mod_zoom's tables as its report task would.
 */
class behat_local_zoomattendance extends behat_base {
    /**
     * Create a Zoom activity with one past class and the minutes each user attended it, then sync.
     *
     * @Given /^the Zoom activity "(?P<name>[^"]*)" in course "(?P<course>[^"]*)" had a class (?P<days>\d+) days ago attended by:$/
     * @param string $name
     * @param string $course Course shortname.
     * @param int $days
     * @param TableNode $table With user and minutes columns.
     */
    public function zoom_class_attended_by(string $name, string $course, int $days, TableNode $table): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $generator = testing_util::get_data_generator()->get_plugin_generator('local_zoomattendance');
        $start = time() - $days * DAYSECS;
        $cm = $generator->create_zoom(['course' => $courseid, 'name' => $name, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        foreach ($table->getHash() as $row) {
            $userid = $DB->get_field('user', 'id', ['username' => $row['user']], MUST_EXIST);
            $generator->create_participant($session, $start, $start + (int) $row['minutes'] * MINSECS, ['userid' => $userid]);
        }
        \local_zoomattendance\local\sync::sync_all();
    }

    /**
     * Open a course's Zoom attendance page.
     *
     * @Given /^I am on the Zoom attendance page of course "(?P<course>[^"]*)"$/
     * @param string $course Course shortname.
     */
    public function i_am_on_the_course_page(string $course): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $url = new moodle_url('/local/zoomattendance/course.php', ['id' => $courseid]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Open the current user's own Zoom attendance in a course.
     *
     * @Given /^I am on my Zoom attendance page of course "(?P<course>[^"]*)"$/
     * @param string $course Course shortname.
     */
    public function i_am_on_my_page(string $course): void {
        global $DB;
        $courseid = $DB->get_field('course', 'id', ['shortname' => $course], MUST_EXIST);
        $url = new moodle_url('/local/zoomattendance/user.php', ['course' => $courseid]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Open the attendance report of a Zoom activity.
     *
     * @Given /^I am on the Zoom attendance report of "(?P<name>[^"]*)"$/
     * @param string $name Activity name.
     */
    public function i_am_on_the_activity_report(string $name): void {
        global $DB;
        $zoomid = $DB->get_field('zoom', 'id', ['name' => $name], MUST_EXIST);
        $cm = get_coursemodule_from_instance('zoom', $zoomid, 0, false, MUST_EXIST);
        $url = new moodle_url('/local/zoomattendance/report.php', ['id' => $cm->id]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }
}
