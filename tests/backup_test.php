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
 * Tests for backup and restore.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance;

use local_zoomattendance\local\course_summary;
use local_zoomattendance\local\manual;
use local_zoomattendance\local\responsible;
use local_zoomattendance\local\status;
use local_zoomattendance\local\sync;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for backup and restore.
 *
 * @covers \backup_local_zoomattendance_plugin
 * @covers \restore_local_zoomattendance_plugin
 */
final class backup_test extends \advanced_testcase {
    /**
     * Back up a course and restore it as a new course.
     *
     * @param \stdClass $course
     * @param bool $users With user data.
     * @return int The new course id.
     */
    protected function backup_and_restore(\stdClass $course, bool $users): int {
        global $CFG, $USER;
        $CFG->keeptempdirectoriesonbackup = true;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value($users);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Copy', 'copy' . $course->id . (int) $users, $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    public function test_attendance_survives_backup_and_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $amy = $dg->create_and_enrol($course, 'student');
        $ben = $dg->create_and_enrol($course, 'student');
        $teacher = $dg->create_and_enrol($course, 'teacher');
        $start = time() - 3 * DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $amy->id]);
        $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $teacher->id]);
        $generator->create_participant($session, $start, $start + 50 * MINSECS, ['name' => 'Phone',
            'user_email' => 'phone@example.org']);
        sync::sync_all();
        $setting = (object) ['cmid' => $cm->id, 'presentpct' => 60, 'timemodified' => time()];
        $DB->insert_record('local_zoomattendance_setting', $setting);
        responsible::set((int) $cm->id, [(int) $teacher->id]);
        $DB->insert_record('local_zoomattendance_idmap', (object) ['courseid' => $course->id,
            'identitykey' => sync::unmatched_key((object) ['user_email' => 'phone@example.org', 'uuid' => '',
            'zoomuserid' => '', 'name' => 'Phone']), 'userid' => $ben->id, 'timecreated' => time()]);

        // With user data: the class, its figures, the frozen list, the settings and the links.
        $newcourse = get_course($this->backup_and_restore($course, true));
        $newcm = current(get_fast_modinfo($newcourse)->get_instances_of('zoom'));
        $this->assertNotEmpty($newcm);
        $this->assertEquals(60, $DB->get_field('local_zoomattendance_setting', 'presentpct', ['cmid' => $newcm->id]));
        $this->assertSame([(int) $teacher->id], responsible::get((int) $newcm->id));
        $this->assertSame(1, $DB->count_records('local_zoomattendance_idmap', ['courseid' => $newcourse->id]));
        $occurrence = $DB->get_record('local_zoomattendance_occ', ['zoomid' => $newcm->instance], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int) $occurrence->restored);
        // Kept at the time it took place, under a key of its own.
        $this->assertEquals($start, $occurrence->timestart);
        $this->assertStringStartsWith('r:', $occurrence->occurrencekey);
        $this->assertSame(3, $DB->count_records('local_zoomattendance_roster', ['occurrenceid' => $occurrence->id]));

        // The figures are kept, even after a sync of the new course, which has no Zoom data. The
        // restored class stands for the scheduled one at the same time: no second class.
        sync::sync_all(null, true);
        $this->assertSame(1, $DB->count_records('local_zoomattendance_occ', ['zoomid' => $newcm->instance]));
        $summary = course_summary::build($newcourse);
        $this->assertSame(status::PRESENT, $summary->cells[$amy->id][$occurrence->id]->status);
        $this->assertSame(status::ABSENT, $summary->cells[$ben->id][$occurrence->id]->status);

        // A new identity link in the new course recomputes its classes, but not the restored one:
        // it would stay marked, and every sync would visit the activity again.
        $this->setAdminUser();
        $DB->delete_records('local_zoomattendance_idmap', ['courseid' => $newcourse->id]);
        manual::link_identity((int) $newcourse->id, 'z:' . sha1('e:other@example.org'), (int) $ben->id, 'Other');
        $this->assertNotEquals(0, $DB->get_field('local_zoomattendance_occ', 'timecomputed', ['id' => $occurrence->id]));
        // One marked before this fix is cleared by the next sync.
        $DB->set_field('local_zoomattendance_occ', 'timecomputed', 0, ['id' => $occurrence->id]);
        sync::sync_all(null, true);
        $this->assertNotEquals(0, $DB->get_field('local_zoomattendance_occ', 'timecomputed', ['id' => $occurrence->id]));
        $this->assertSame(status::PRESENT, course_summary::build($newcourse)->cells[$amy->id][$occurrence->id]->status);

        // Without user data: only the settings.
        $bare = get_course($this->backup_and_restore($course, false));
        $barecm = current(get_fast_modinfo($bare)->get_instances_of('zoom'));
        $this->assertEquals(60, $DB->get_field('local_zoomattendance_setting', 'presentpct', ['cmid' => $barecm->id]));
        $this->assertFalse($DB->record_exists('local_zoomattendance_result', ['occurrenceid' => $occurrence->id + 1000]));
        $this->assertSame(0, $DB->count_records_select(
            'local_zoomattendance_occ',
            'zoomid = :zoomid AND restored > 0',
            ['zoomid' => $barecm->instance]
        ));
        $this->assertSame(0, $DB->count_records('local_zoomattendance_idmap', ['courseid' => $bare->id]));
    }

    public function test_restored_class_does_not_hide_a_class_at_another_time(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $dg->create_and_enrol($course, 'student');
        $start = time() - 3 * DAYSECS;
        $cm = $dg->get_plugin_generator('local_zoomattendance')->create_zoom(['course' => $course->id,
            'start_time' => $start, 'duration' => HOURSECS]);
        \local_zoomattendance\local\sync::sync_all();
        $newcourse = get_course($this->backup_and_restore($course, true));
        $newcm = current(get_fast_modinfo($newcourse)->get_instances_of('zoom'));

        // The copy's meeting is moved to next week (as when the course dates move).
        $DB->set_field('zoom', 'start_time', time() + 7 * DAYSECS, ['id' => $newcm->instance]);
        \local_zoomattendance\local\sync::sync_all(null, true);
        $keys = $DB->get_fieldset_select('local_zoomattendance_occ', 'occurrencekey', 'zoomid = ?', [$newcm->instance]);
        sort($keys);
        $this->assertCount(2, $keys);
        $this->assertStringStartsWith('r:', $keys[0]);
        $this->assertSame('single', $keys[1]);
    }
}
