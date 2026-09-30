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
 * Navigation callbacks for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add attendance links to a Zoom activity's settings navigation.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_zoomattendance_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE, $USER;
    if (!$context instanceof context_module || !$PAGE->cm || $PAGE->cm->modname !== 'zoom') {
        return;
    }
    $node = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$node) {
        return;
    }
    $cmid = $PAGE->cm->id;
    if (has_capability('local/zoomattendance:viewreports', $context)) {
        $node->add(
            get_string('attendancereport', 'local_zoomattendance'),
            new moodle_url('/local/zoomattendance/report.php', ['id' => $cmid]),
            navigation_node::TYPE_SETTING,
            null,
            'local_zoomattendance_report'
        );
    } else if (has_capability('local/zoomattendance:viewown', $context)) {
        $node->add(
            get_string('myattendance', 'local_zoomattendance'),
            new moodle_url('/local/zoomattendance/user.php', ['course' => $PAGE->cm->course, 'user' => $USER->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_zoomattendance_own'
        );
    }
    if (has_capability('local/zoomattendance:manage', $context)) {
        $node->add(
            get_string('attendancesettings', 'local_zoomattendance'),
            new moodle_url('/local/zoomattendance/activitysettings.php', ['id' => $cmid]),
            navigation_node::TYPE_SETTING,
            null,
            'local_zoomattendance_settings'
        );
    }
}

/**
 * Add the course attendance summary to course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_zoomattendance_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context) {
    global $DB, $USER;
    $canviewall = has_capability('local/zoomattendance:viewreports', $context);
    $canviewown = has_capability('local/zoomattendance:viewown', $context);
    if ((!$canviewall && !$canviewown) || !$DB->record_exists('zoom', ['course' => $course->id])) {
        return;
    }
    if ($canviewall) {
        $url = new moodle_url('/local/zoomattendance/course.php', ['id' => $course->id]);
        $label = get_string('courseattendance', 'local_zoomattendance');
    } else {
        $url = new moodle_url('/local/zoomattendance/user.php', ['course' => $course->id, 'user' => $USER->id]);
        $label = get_string('myattendance', 'local_zoomattendance');
    }
    $navigation->add(
        $label,
        $url,
        navigation_node::TYPE_SETTING,
        null,
        \local_zoomattendance\hook_callbacks::NODE_KEY,
        new pix_icon('i/report', '')
    );
}

/**
 * Add a link to a user's Zoom attendance on their course profile.
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return bool
 */
function local_zoomattendance_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;
    $added = false;
    if (
        $iscurrentuser && \local_zoomattendance\local\settings::teacher_tracking()
            && \local_zoomattendance\local\teacher_overview::has_courses((int) $user->id, 'local/zoomattendance:viewownteacher')
    ) {
        $tree->add_node(new core_user\output\myprofile\node(
            'reports',
            'local_zoomattendance_teaching',
            get_string('myteaching', 'local_zoomattendance'),
            null,
            new moodle_url('/local/zoomattendance/teachersoverview.php', ['mine' => 1])
        ));
        $added = true;
    }
    if (empty($course) || $course->id == SITEID || !$DB->record_exists('zoom', ['course' => $course->id])) {
        return $added;
    }
    $context = context_course::instance($course->id);
    if (
        !has_capability('local/zoomattendance:viewreports', $context)
            && !($iscurrentuser && has_capability('local/zoomattendance:viewown', $context))
    ) {
        return $added;
    }
    // Teachers must not see other teachers' Zoom times (user.php refuses them too).
    if (
        !$iscurrentuser && has_capability('local/zoomattendance:betrackedteacher', $context, $user->id)
            && !has_capability('local/zoomattendance:viewteacherreports', $context)
    ) {
        return $added;
    }
    $url = new moodle_url('/local/zoomattendance/user.php', ['course' => $course->id, 'user' => $user->id]);
    $tree->add_node(new core_user\output\myprofile\node(
        'reports',
        'local_zoomattendance',
        get_string('zoomattendance', 'local_zoomattendance'),
        null,
        $url
    ));
    return true;
}

/**
 * Add the teacher attendance list to a category's navigation.
 *
 * @param navigation_node $parentnode
 * @param context_coursecat $context
 */
function local_zoomattendance_extend_navigation_category_settings(navigation_node $parentnode, context_coursecat $context) {
    if (
        !\local_zoomattendance\local\settings::teacher_tracking()
            || !has_capability('local/zoomattendance:viewteacherreports', $context)
    ) {
        return;
    }
    $parentnode->add(
        get_string('teachersoverview', 'local_zoomattendance'),
        new moodle_url('/local/zoomattendance/teachersoverview.php', ['category' => $context->instanceid]),
        navigation_node::TYPE_SETTING,
        null,
        'local_zoomattendance_teachers',
        new pix_icon('i/report', '')
    );
}
