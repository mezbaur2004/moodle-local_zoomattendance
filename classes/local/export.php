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
 * Safe downloads.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Downloads that cannot run formulas in a spreadsheet.
 *
 * Zoom display names are typed by participants, so a name such as "=HYPERLINK(...)" would
 * become a live formula when the file is opened. Text starting with a formula character gets a
 * leading apostrophe, which spreadsheets show as plain text.
 */
class export {
    /** @var string Characters a spreadsheet may read as the start of a formula. */
    protected const FORMULA_START = "=+-@\t\r";

    /**
     * One cell, made safe.
     *
     * @param mixed $value
     * @return mixed Numbers and empty values unchanged.
     */
    public static function cell($value) {
        if (is_string($value) && $value !== '' && strpos(self::FORMULA_START, $value[0]) !== false) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Send a download with every text cell made safe.
     *
     * @param string $filename Without extension.
     * @param string $dataformat
     * @param string[] $columns column key => heading.
     * @param array[] $rows Each column key => value.
     */
    public static function download(string $filename, string $dataformat, array $columns, array $rows): void {
        $safe = [];
        foreach ($rows as $row) {
            $safe[] = array_map([self::class, 'cell'], $row);
        }
        \core\dataformat::download_data($filename, $dataformat, $columns, $safe);
    }
}
