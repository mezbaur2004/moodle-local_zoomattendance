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
 * Hook callbacks.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

/**
 * Hook callbacks.
 */
class hook_callbacks {
    /** @var string Key of the course navigation node added by local_zoomattendance_extend_navigation_course(). */
    public const NODE_KEY = 'local_zoomattendance';

    /**
     * Move the attendance link in the course secondary navigation to right after Grades.
     *
     * Moodle shows at most five course navigation items and puts the rest under "More"; plugin
     * items come after the core ones, so the link would always end up there. This runs before
     * that limit is applied. Without Grades the link goes after Participants, then after the
     * course link. The hook exists from Moodle 4.4; on older versions the link stays in "More".
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function extend_secondary_navigation(\core\hook\navigation\secondary_extend $hook): void {
        $view = $hook->get_secondaryview();
        $node = $view->children->get(self::NODE_KEY);
        if (!$node) {
            return;
        }
        $keys = array_values(array_filter($view->children->get_key_list(), function ($key) {
            return $key !== self::NODE_KEY;
        }));
        foreach (['grades', 'participants', 'coursehome'] as $anchor) {
            $index = array_search($anchor, $keys, true);
            if ($index !== false) {
                $view->children->remove(self::NODE_KEY, $node->type);
                $view->add_node($node, $keys[$index + 1] ?? null);
                return;
            }
        }
    }
}
