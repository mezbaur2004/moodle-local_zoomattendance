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
 * A version that changes whenever anything a report shows may have changed.
 *
 * Cached summaries include it in their key, so a change makes every older entry unused at once,
 * without having to know which entries it affects. Changing it is one cache write.
 */
class data_version {
    /**
     * The current version.
     *
     * @return string
     */
    public static function get(): string {
        $cache = \cache::make('local_zoomattendance', 'version');
        $version = $cache->get('data');
        if ($version === false) {
            $version = self::bump();
        }
        return (string) $version;
    }

    /**
     * Start a new version. Also usable as an admin setting or event callback.
     *
     * @return string The new version.
     */
    public static function bump(): string {
        $version = uniqid('', true);
        \cache::make('local_zoomattendance', 'version')->set('data', $version);
        return $version;
    }

    /**
     * A cached value for a key, built and stored when missing or older than the data.
     *
     * @param array $key What the value is built for; the version is added.
     * @param callable $build Builds the value.
     * @return mixed
     */
    public static function cached(array $key, callable $build) {
        $cache = \cache::make('local_zoomattendance', 'summaries');
        $key[] = self::get();
        $hash = sha1(json_encode($key));
        $value = $cache->get($hash);
        if ($value === false) {
            $value = $build();
            $cache->set($hash, $value);
        }
        return $value;
    }
}
