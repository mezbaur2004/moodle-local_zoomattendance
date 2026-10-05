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
 * Moodle app support for local_zoomattendance.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_zoomattendance' => [
        'handlers' => [
            // A "Zoom attendance" tab in courses with Zoom attendance: a student's own attendance,
            // and for teachers how many students attended each class.
            'courseattendance' => [
                'delegate' => 'CoreCourseOptionsDelegate',
                'method' => 'mobile_course_view',
                'init' => 'mobile_init',
                'displaydata' => [
                    'title' => 'pluginname',
                    'class' => 'local-zoomattendance',
                ],
                'priority' => 50,
                'restricttoenrolledcourses' => true,
            ],
        ],
        'lang' => [
            ['pluginname', 'local_zoomattendance'],
            ['courseoverall', 'local_zoomattendance'],
            ['myattendance', 'local_zoomattendance'],
            ['students', 'local_zoomattendance'],
            ['nouserdata', 'local_zoomattendance'],
        ],
    ],
];
