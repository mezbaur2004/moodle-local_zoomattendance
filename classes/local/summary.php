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
 * Accumulates evaluated occurrences into one overall status and percentage.
 *
 * The percentage is attended time over the summed denominators, so longer occurrences weigh
 * more. The status applies the given thresholds to that percentage; late joins (judged by
 * each occurrence's own late period) count when they happened in more than half of the
 * occurrences. With one occurrence and its own thresholds the result equals that
 * occurrence's status.
 */
class summary {
    /** @var int Evaluated occurrences. */
    public $count = 0;
    /** @var int[] Occurrences per status. */
    public $counts = [status::PRESENT => 0, status::LATE => 0, status::ABSENT => 0];
    /** @var int Summed attended seconds. */
    public $attendedsecs = 0;
    /** @var int Summed denominators. */
    public $denominator = 0;
    /** @var int Occurrences first joined after the late period. */
    public $latejoins = 0;

    /**
     * Add one evaluated occurrence.
     *
     * @param \stdClass $row Expected row from attendance::evaluate().
     * @param \stdClass $evaluation Its evaluation (occurrence and denominator).
     * @param settings $settings Effective settings of the occurrence's activity.
     */
    public function add(\stdClass $row, \stdClass $evaluation, settings $settings): void {
        if (!isset($this->counts[$row->status])) {
            return;
        }
        $this->count++;
        $this->counts[$row->status]++;
        $this->attendedsecs += (int) $row->attendedsecs;
        $this->denominator += (int) $evaluation->denominator;
        $graceend = (int) $evaluation->occurrence->timestart + $settings->lategracemins * MINSECS;
        if ($row->firstjoin !== null && $row->firstjoin > $graceend) {
            $this->latejoins++;
        }
    }

    /**
     * Overall percentage.
     *
     * @return float|null Null when nothing was evaluated.
     */
    public function percentage(): ?float {
        return $this->count ? calculator::percentage($this->attendedsecs, $this->denominator) : null;
    }

    /**
     * Overall status.
     *
     * @param settings $settings Thresholds to apply.
     * @return string|null A status constant, null when nothing was evaluated.
     */
    public function status(settings $settings): ?string {
        $pct = $this->percentage();
        if ($pct === null) {
            return null;
        }
        if ($this->attendedsecs <= 0 || $pct < $settings->latepct) {
            return status::ABSENT;
        }
        if ($pct >= $settings->presentpct && $this->latejoins * 2 <= $this->count) {
            return status::PRESENT;
        }
        return status::LATE;
    }
}
