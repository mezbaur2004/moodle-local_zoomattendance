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
 * Contact numbers from users' profiles.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * The Mobile phone and Phone profile fields, shown beside names to users with viewphone so they
 * can call or message the person.
 *
 * People type numbers in many ways (01712345678, +8801712345678, 8801712345678, 01712-345678),
 * so each is brought to one international form with the site's default country code: that form
 * is shown, dialled and used for WhatsApp. A value that is not a phone number stays as typed,
 * without call or WhatsApp links.
 */
class phone {
    /** @var string Capability to see contact numbers. */
    public const CAPABILITY = 'local/zoomattendance:viewphone';
    /** @var string Default country code: Bangladesh. */
    public const DEFAULT_COUNTRY_CODE = '880';
    /** @var string[] Setting value => profile fields, in the order shown. */
    public const FIELD_CHOICES = [
        'both' => ['phone2', 'phone1'],
        'mobile' => ['phone2'],
        'phone' => ['phone1'],
    ];
    /** @var int Shortest international number, in digits. */
    protected const MIN_DIGITS = 8;
    /** @var int Longest international number (E.164), in digits. */
    protected const MAX_DIGITS = 15;
    /** @var int Longest national number written without its leading 0. */
    protected const MAX_NATIONAL_DIGITS = 10;

    /**
     * Whether the current user sees contact numbers here.
     *
     * @param \context $context
     * @return bool
     */
    public static function can_view(\context $context): bool {
        return has_capability(self::CAPABILITY, $context);
    }

    /**
     * Profile fields to show, Mobile phone first by default.
     *
     * @return string[]
     */
    public static function fields(): array {
        $choice = (string) get_config('local_zoomattendance', 'phonefields');
        return self::FIELD_CHOICES[$choice] ?? self::FIELD_CHOICES['both'];
    }

    /**
     * Country code for numbers written without one.
     *
     * @return string Digits, or '' when none is set.
     */
    public static function country_code(): string {
        $code = get_config('local_zoomattendance', 'phonecountrycode');
        return preg_replace('/\D+/', '', $code === false ? self::DEFAULT_COUNTRY_CODE : (string) $code);
    }

    /**
     * A number in international form, digits only.
     *
     * In order: spaces, dashes, dots and brackets are dropped; a leading + or 00 means it is
     * already international; otherwise a leading 0 becomes the country code, a number of up to 10
     * digits gets the country code in front (its 0 forgotten), and a longer one is taken as
     * international without its +. A national 0 written after the country code, as in
     * +880 (0)1712..., is dropped.
     *
     * @param string $raw As typed.
     * @param string $countrycode Digits.
     * @return string|null Null when it is not a phone number.
     */
    public static function normalise(string $raw, string $countrycode): ?string {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[^\d\s+\-.()\/]/u', $raw)) {
            return null;
        }
        $international = strpos($raw, '+') === 0;
        $digits = preg_replace('/\D+/', '', $raw);
        if (!$international && strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
            $international = true;
        }
        if (!$international) {
            if (strpos($digits, '0') === 0) {
                if ($countrycode === '') {
                    return null;
                }
                $digits = $countrycode . substr($digits, 1);
            } else if (strlen($digits) <= self::MAX_NATIONAL_DIGITS) {
                if ($countrycode === '') {
                    return null;
                }
                $digits = $countrycode . $digits;
            }
        }
        if ($countrycode !== '' && strpos($digits, $countrycode . '0') === 0) {
            $digits = $countrycode . substr($digits, strlen($countrycode) + 1);
        }
        $length = strlen($digits);
        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS || strpos($digits, '0') === 0) {
            return null;
        }
        return $digits;
    }

    /**
     * A user's numbers, each once, in field order.
     *
     * @param \stdClass $user With the profile fields of fields(), where known.
     * @return \stdClass[] Each with text (shown: +digits, or the value as typed when it is not a
     *     phone number) and digits (international form, or null).
     */
    public static function numbers(\stdClass $user): array {
        $countrycode = self::country_code();
        $numbers = [];
        foreach (self::fields() as $field) {
            $raw = trim((string) ($user->$field ?? ''));
            if ($raw === '') {
                continue;
            }
            $digits = self::normalise($raw, $countrycode);
            $key = $digits ?? $raw;
            if (!isset($numbers[$key])) {
                $numbers[$key] = (object) ['text' => $digits === null ? $raw : '+' . $digits, 'digits' => $digits];
            }
        }
        return array_values($numbers);
    }

    /**
     * A user's numbers when the current user may see them.
     *
     * @param \stdClass $user
     * @param \context $context Where they are shown.
     * @return \stdClass[] See numbers(); empty without viewphone.
     */
    public static function for_viewer(\stdClass $user, \context $context): array {
        return self::can_view($context) ? self::numbers($user) : [];
    }

    /**
     * Numbers for downloads: in international form without the +, as WhatsApp and bulk messaging
     * tools take them, since spreadsheets read a leading + as a formula; values that are not phone
     * numbers as typed.
     *
     * @param \stdClass[] $numbers From numbers().
     * @return string
     */
    public static function download_text(array $numbers): string {
        return implode(', ', array_map(function ($number) {
            return $number->digits ?? $number->text;
        }, $numbers));
    }

    /**
     * Link to dial a number.
     *
     * @param \stdClass $number From numbers(), with digits.
     * @return string
     */
    public static function call_url(\stdClass $number): string {
        return 'tel:+' . $number->digits;
    }

    /**
     * Link to message a number on WhatsApp.
     *
     * @param \stdClass $number From numbers(), with digits.
     * @return string
     */
    public static function whatsapp_url(\stdClass $number): string {
        return 'https://wa.me/' . $number->digits;
    }
}
