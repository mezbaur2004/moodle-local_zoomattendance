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
 * Version of the attendance data, for caches.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Versions that change whenever anything a report shows may have changed.
 *
 * There is a site-wide version (settings, anything not tied to one course), one per course, and
 * one that changes with either (get(), for caches spanning courses such as the block's).
 * Cached summaries store the version they were built from with the value, under a key that
 * does not change: a newer version rebuilds and overwrites the entry, so the cache holds one
 * entry per summary, however often the data changes. Changing a version is one cache write.
 */
class data_version {
    /** @var string Key of the version that changes with any other. */
    protected const ANY = 'data';
    /** @var string Key of the site-wide version. */
    protected const SITE = 'site';

    /**
     * A version, started when missing.
     *
     * @param string $key
     * @return string
     */
    protected static function read(string $key): string {
        $version = \cache::make('local_zoomattendance', 'version')->get($key);
        if ($version === false) {
            $version = self::write($key);
        }
        return (string) $version;
    }

    /**
     * Start a new version.
     *
     * @param string $key
     * @return string
     */
    protected static function write(string $key): string {
        $version = uniqid('', true);
        \cache::make('local_zoomattendance', 'version')->set($key, $version);
        return $version;
    }

    /**
     * The version that changes whenever anything changes, in any course.
     *
     * @return string
     */
    public static function get(): string {
        return self::read(self::ANY);
    }

    /**
     * The version of one course's data: changes with the site-wide version too.
     *
     * @param int $courseid
     * @return string
     */
    public static function for_course(int $courseid): string {
        return self::read(self::SITE) . '/' . self::read('c' . $courseid);
    }

    /**
     * Start a new site-wide version: every course's data may have changed. Also usable as an
     * admin setting or event callback (any arguments are ignored).
     *
     * @return string The new version.
     */
    public static function bump(): string {
        self::write(self::SITE);
        return self::write(self::ANY);
    }

    /**
     * Start a new version of one course's data.
     *
     * @param int $courseid
     */
    public static function bump_course(int $courseid): void {
        if ($courseid <= SITEID) {
            self::bump();
            return;
        }
        self::write('c' . $courseid);
        self::write(self::ANY);
    }

    /**
     * A cached value for a key, built and stored when missing or older than the data.
     *
     * @param array $key What the value is built for.
     * @param callable $build Builds the value.
     * @param int|null $courseid The course the value is about (null: any course).
     * @param string $stamp Anything else the value depends on, such as the time.
     * @return mixed
     */
    public static function cached(array $key, callable $build, ?int $courseid = null, string $stamp = '') {
        $cache = \cache::make('local_zoomattendance', 'summaries');
        $version = ($courseid === null ? self::get() : self::for_course($courseid)) . '|' . $stamp;
        $hash = sha1(json_encode($key));
        $entry = $cache->get($hash);
        if (is_array($entry) && ($entry['version'] ?? null) === $version) {
            return $entry['value'];
        }
        $value = $build();
        $cache->set($hash, ['version' => $version, 'value' => $value]);
        return $value;
    }
}
