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
 * Privacy provider tests.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_zoomattendance\local\sync;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Privacy provider tests.
 *
 * @covers \local_zoomattendance\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \cm_info */
    protected $cm;
    /** @var \stdClass */
    protected $user1;
    /** @var \stdClass */
    protected $user2;
    /** @var \stdClass */
    protected $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $this->user1 = $dg->create_and_enrol($course, 'student');
        $this->user2 = $dg->create_and_enrol($course, 'student');
        $start = time() - DAYSECS;
        $this->cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($this->cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $this->user1->id]);
        $generator->create_participant($session, $start, $start + 600, ['userid' => $this->user2->id]);
        $generator->create_participant($session, $start, $start + 600, ['name' => 'Guest']);
        sync::sync_all();
        $this->course = $course;
    }

    /**
     * Link a Zoom identity to user1 in the course.
     *
     * @return \context_course
     */
    protected function add_link(): \context_course {
        global $DB;
        $DB->insert_record('local_zoomattendance_idmap', (object) ['courseid' => $this->course->id,
            'identitykey' => 'z:' . sha1('e:phone@example.org'), 'userid' => $this->user1->id,
            'displayname' => 'Phone', 'timecreated' => time()]);
        return \context_course::instance($this->course->id);
    }

    public function test_identity_links_are_in_the_course_context(): void {
        global $DB;
        $coursecontext = $this->add_link();
        $contextids = function (int $userid): array {
            return array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids());
        };
        $this->assertContains((int) $coursecontext->id, $contextids($this->user1->id));
        $this->assertNotContains((int) $coursecontext->id, $contextids($this->user2->id));

        $userlist = new userlist($coursecontext, 'local_zoomattendance');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->user1->id], $userlist->get_userids());

        $this->export_context_data_for_user($this->user1->id, $coursecontext, 'local_zoomattendance');
        $data = writer::with_context($coursecontext)->get_data([get_string('pluginname', 'local_zoomattendance'),
            get_string('identitylinks', 'local_zoomattendance')]);
        $this->assertSame('Phone', $data->links[0]->zoomname);

        provider::delete_data_for_user(new approved_contextlist($this->user1, 'local_zoomattendance', [$coursecontext->id]));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_idmap'));
    }

    public function test_delete_identity_links_for_users_and_context(): void {
        global $DB;
        $coursecontext = $this->add_link();
        provider::delete_data_for_users(new approved_userlist($coursecontext, 'local_zoomattendance', [$this->user2->id]));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_idmap'));
        provider::delete_data_for_users(new approved_userlist($coursecontext, 'local_zoomattendance', [$this->user1->id]));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_idmap'));

        $this->add_link();
        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertSame(0, $DB->count_records('local_zoomattendance_idmap'));
    }

    public function test_get_contexts_and_users(): void {
        $context = \context_module::instance($this->cm->id);
        $this->assertEquals([$context->id], provider::get_contexts_for_userid($this->user1->id)->get_contextids());

        $userlist = new userlist($context, 'local_zoomattendance');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->user1->id, $this->user2->id], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        $context = \context_module::instance($this->cm->id);
        $this->export_context_data_for_user($this->user1->id, $context, 'local_zoomattendance');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_zoomattendance')]);
        $this->assertCount(1, $data->occurrences);
        $this->assertEquals(60, $data->occurrences[0]->attendedminutes);
        $this->assertSame('present', $data->occurrences[0]->status);
    }

    public function test_delete_data_for_user(): void {
        global $DB;
        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_user(new approved_contextlist($this->user1, 'local_zoomattendance', [$context->id]));
        $this->assertFalse($DB->record_exists('local_zoomattendance_result', ['userid' => $this->user1->id]));
        $this->assertTrue($DB->record_exists('local_zoomattendance_result', ['userid' => $this->user2->id]));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $context = \context_module::instance($this->cm->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_zoomattendance', [$this->user2->id]));
        $this->assertTrue($DB->record_exists('local_zoomattendance_result', ['userid' => $this->user1->id]));
        $this->assertFalse($DB->record_exists('local_zoomattendance_result', ['userid' => $this->user2->id]));
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context(\context_module::instance($this->cm->id));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_result'));
    }
}
