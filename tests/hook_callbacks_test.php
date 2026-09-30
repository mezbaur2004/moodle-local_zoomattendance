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
 * Hook callback tests.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

use core\navigation\views\secondary;

#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
/**
 * Hook callback tests.
 *
 * @covers \local_zoomattendance\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\core\hook\navigation\secondary_extend::class)) {
            $this->markTestSkipped('The secondary navigation hook exists from Moodle 4.4.');
        }
    }

    /**
     * Keys of a secondary navigation, in order.
     *
     * @param secondary $secondary
     * @return array
     */
    protected function keys(secondary $secondary): array {
        return array_values($secondary->children->get_key_list());
    }

    /**
     * Run the callback on a dummy secondary navigation with the given node keys.
     *
     * @param string[] $keys
     * @return array The keys afterwards.
     */
    protected function move(array $keys): array {
        global $PAGE;
        $secondary = new secondary($PAGE);
        foreach ($keys as $key) {
            $secondary->add($key, '#', secondary::TYPE_SETTING, null, $key);
        }
        hook_callbacks::extend_secondary_navigation(new \core\hook\navigation\secondary_extend($secondary));
        return $this->keys($secondary);
    }

    public function test_link_is_shown_after_grades_in_a_course(): void {
        global $PAGE;
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $dg->get_plugin_generator('local_zoomattendance')->create_zoom(['course' => $course->id]);
        $this->setUser($dg->create_and_enrol($course, 'editingteacher'));

        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
        $secondary = new secondary($PAGE);
        $secondary->initialise();

        $keys = $this->keys($secondary);
        $index = array_search(hook_callbacks::NODE_KEY, $keys, true);
        $this->assertNotFalse($index);
        $this->assertSame('grades', $keys[$index - 1]);
        $this->assertFalse($secondary->children->get(hook_callbacks::NODE_KEY)->forceintomoremenu);
    }

    public function test_position_fallbacks(): void {
        $this->resetAfterTest();
        $node = hook_callbacks::NODE_KEY;
        $this->assertSame(
            ['coursehome', 'participants', 'grades', $node, 'coursereports'],
            $this->move(['coursehome', 'participants', 'grades', 'coursereports', $node])
        );
        $this->assertSame(
            ['coursehome', 'participants', $node, 'coursereports'],
            $this->move(['coursehome', 'participants', 'coursereports', $node])
        );
        $this->assertSame(['coursehome', $node, 'coursereports'], $this->move(['coursehome', 'coursereports', $node]));
        $this->assertSame(['coursehome', 'grades', $node], $this->move(['coursehome', $node, 'grades']));
        $this->assertSame(['other', $node], $this->move(['other', $node]));
        $this->assertSame(['coursehome', 'grades'], $this->move(['coursehome', 'grades']));
    }
}
