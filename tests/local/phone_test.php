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
 * Tests for contact numbers.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

#[\PHPUnit\Framework\Attributes\CoversClass(phone::class)]
/**
 * Tests for contact numbers.
 *
 * @covers \local_zoomattendance\local\phone
 */
final class phone_test extends \advanced_testcase {
    public function test_every_way_of_writing_a_number_becomes_one_international_form(): void {
        $cases = [
            // Bangladesh, written every usual way.
            '01712345678' => '8801712345678',
            '+8801712345678' => '8801712345678',
            '8801712345678' => '8801712345678',
            '008801712345678' => '8801712345678',
            '01712-345678' => '8801712345678',
            '01712 345 678' => '8801712345678',
            '(01712) 345678' => '8801712345678',
            '+880 1712-345678' => '8801712345678',
            '+880 (0)1712345678' => '8801712345678',
            ' 01712.345.678 ' => '8801712345678',
            // The leading 0 forgotten.
            '1712345678' => '8801712345678',
            // A land line.
            '029876543' => '88029876543',
            // Other countries keep their code.
            '+447700900123' => '447700900123',
            '00447700900123' => '447700900123',
            '447700900123' => '447700900123',
            // Not phone numbers.
            '' => null,
            'N/A' => null,
            'call me' => null,
            '017123 ext 4' => null,
            '123' => null,
            '0' => null,
            '+0123456789' => null,
            '01712345678/01812345678' => null,
            '+12345678901234567' => null,
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, phone::normalise((string) $raw, '880'), "'$raw'");
        }
        // Without a country code only numbers written with one are phone numbers.
        $this->assertNull(phone::normalise('01712345678', ''));
        $this->assertNull(phone::normalise('1712345678', ''));
        $this->assertSame('8801712345678', phone::normalise('+8801712345678', ''));
        $this->assertSame('8801712345678', phone::normalise('8801712345678', ''));
    }

    public function test_numbers_follow_the_settings_once_each(): void {
        $this->resetAfterTest();
        $user = (object) ['phone1' => '+880 1712-345678', 'phone2' => '01712345678'];
        // The same number in both fields is shown once.
        $numbers = phone::numbers($user);
        $this->assertCount(1, $numbers);
        $this->assertSame('+8801712345678', $numbers[0]->text);
        $this->assertSame('8801712345678', $numbers[0]->digits);
        $this->assertSame('tel:+8801712345678', phone::call_url($numbers[0]));
        $this->assertSame('https://wa.me/8801712345678', phone::whatsapp_url($numbers[0]));

        // Mobile phone first, then Phone; a value that is not a number stays as typed.
        $user = (object) ['phone1' => 'Office: ask reception', 'phone2' => '01812345678'];
        $numbers = phone::numbers($user);
        $this->assertSame(['+8801812345678', 'Office: ask reception'], array_column($numbers, 'text'));
        $this->assertSame(['8801812345678', null], array_column($numbers, 'digits'));
        $this->assertSame('8801812345678, Office: ask reception', phone::download_text($numbers));

        set_config('phonefields', 'phone', 'local_zoomattendance');
        $this->assertSame(['Office: ask reception'], array_column(phone::numbers($user), 'text'));
        set_config('phonefields', 'mobile', 'local_zoomattendance');
        $this->assertSame(['+8801812345678'], array_column(phone::numbers($user), 'text'));

        // Another country code.
        set_config('phonecountrycode', '+91', 'local_zoomattendance');
        $this->assertSame('91', phone::country_code());
        $this->assertSame(['+911812345678'], array_column(phone::numbers($user), 'text'));
        $this->assertSame([], phone::numbers((object) ['phone1' => '', 'phone2' => '  ']));
        $this->assertSame([], phone::numbers((object) []));
    }

    public function test_managers_and_teachers_see_numbers_students_do_not(): void {
        global $DB;
        $this->resetAfterTest();
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $context = \context_course::instance($course->id);
        $manager = $dg->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, \context_system::instance()->id);
        $users = [
            'student' => [$dg->create_and_enrol($course, 'student'), false],
            'teacher' => [$dg->create_and_enrol($course, 'teacher'), true],
            'editingteacher' => [$dg->create_and_enrol($course, 'editingteacher'), true],
            'manager' => [$manager, true],
        ];
        $person = (object) ['phone2' => '01712345678'];
        foreach ($users as $role => [$user, $sees]) {
            $this->setUser($user);
            $this->assertSame($sees, phone::can_view($context), $role);
            $this->assertCount($sees ? 1 : 0, phone::for_viewer($person, $context), $role);
        }
    }

    public function test_numbers_are_shown_beside_names_in_every_table(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        set_config('defaultenabled', 1, 'local_zoomattendance');
        set_config('teachertracking', 1, 'local_zoomattendance');
        set_config('teachertrackingsince', 1, 'local_zoomattendance');
        $dg = $this->getDataGenerator();
        $generator = $dg->get_plugin_generator('local_zoomattendance');
        $course = $dg->create_course();
        $context = \context_course::instance($course->id);
        $student = $dg->create_and_enrol($course, 'student', ['phone1' => '+880 1712-345678', 'phone2' => '01712345678']);
        $teacher = $dg->create_and_enrol($course, 'teacher', ['phone1' => '', 'phone2' => '01812345678']);
        $coordinator = $dg->create_and_enrol($course, 'editingteacher');
        $start = time() - DAYSECS;
        $cm = $generator->create_zoom(['course' => $course->id, 'start_time' => $start, 'duration' => HOURSECS]);
        $session = $generator->create_session($cm, $start, $start + HOURSECS);
        foreach ([$student, $teacher] as $user) {
            $generator->create_participant($session, $start, $start + HOURSECS, ['userid' => $user->id]);
        }
        sync::sync_all();
        $PAGE->set_url('/');
        /** @var \local_zoomattendance\output\renderer $renderer */
        $renderer = $PAGE->get_renderer('local_zoomattendance');

        $this->setUser($coordinator);
        // Course report: the student, their number once, with links to call and to WhatsApp.
        $html = $renderer->course_table(course_summary::build($course));
        $this->assertSame(1, substr_count($html, '>+8801712345678<'));
        $this->assertStringContainsString('href="tel:+8801712345678"', $html);
        $this->assertStringContainsString('href="https://wa.me/8801712345678"', $html);
        $this->assertStringContainsString('aria-label="Message +8801712345678 on WhatsApp"', $html);

        // Class report.
        $attendance = new attendance(get_fast_modinfo($course)->get_cm($cm->id));
        $occurrences = $attendance->get_occurrences();
        $occurrence = reset($occurrences);
        $results = $attendance->get_results([$occurrence->id])[$occurrence->id] ?? [];
        $evaluation = $attendance->evaluate($occurrence, $attendance->get_candidates(0), $results, 0);
        $this->assertStringContainsString('href="tel:+8801712345678"', $renderer->occurrence_detail($attendance, $evaluation));

        // Teacher page, and the overview of the coordinator's teachers.
        $html = $renderer->teacher_table(teacher_summary::build($course), null, $context);
        $this->assertStringContainsString('href="https://wa.me/8801812345678"', $html);
        $this->assertStringNotContainsString('tel:', $renderer->teacher_table(teacher_summary::build($course)));
        $rows = teacher_overview::rows((int) $coordinator->id, true, 0, time());
        $phones = array_column(array_column($rows, 'user'), 'phone2', 'id');
        $this->assertSame('01812345678', $phones[$teacher->id]);

        // Without the capability, no numbers.
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(phone::CAPABILITY, CAP_PROHIBIT, $teacherrole, $context->id);
        $html = $renderer->course_table(course_summary::build($course));
        $this->assertStringNotContainsString('8801712345678', $html);
        $this->assertStringNotContainsString('tel:', $renderer->occurrence_detail($attendance, $evaluation));
        $this->assertStringNotContainsString('wa.me', $renderer->teacher_table(teacher_summary::build($course), null, $context));
    }
}
