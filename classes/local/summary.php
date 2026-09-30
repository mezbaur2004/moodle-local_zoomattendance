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
 * One participant's overall attendance over several occurrences.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Accumulates evaluated occurrences into one overall percentage.
 *
 * The percentage is attended time over the summed denominators, so longer occurrences weigh
 * more. There is no overall status: statuses describe single occurrences.
 */
class summary {
    /** @var int Evaluated occurrences. */
    public $count = 0;
    /** @var int Summed attended seconds. */
    public $attendedsecs = 0;
    /** @var int Summed denominators. */
    public $denominator = 0;

    /**
     * Add one evaluated occurrence.
     *
     * @param \stdClass $row Expected row from attendance::evaluate().
     * @param \stdClass $evaluation Its evaluation (for the denominator).
     */
    public function add(\stdClass $row, \stdClass $evaluation): void {
        if (!in_array($row->status, [status::PRESENT, status::PARTIAL, status::ABSENT], true)) {
            return;
        }
        $this->count++;
        $this->attendedsecs += (int) $row->attendedsecs;
        $this->denominator += (int) $evaluation->denominator;
    }

    /**
     * Overall percentage.
     *
     * @return float|null Null when nothing was evaluated.
     */
    public function percentage(): ?float {
        return $this->count ? calculator::percentage($this->attendedsecs, $this->denominator) : null;
    }
}
