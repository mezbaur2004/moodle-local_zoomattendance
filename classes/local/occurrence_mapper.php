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
 * Maps mod_zoom sessions to occurrences.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Pure mapping logic between actual Zoom sessions and scheduled or inferred occurrences.
 */
class occurrence_mapper {
    /**
     * Map each session to the scheduled occurrence it overlaps most.
     *
     * A session is a candidate for an occurrence when its span overlaps the occurrence window
     * widened by the margins. Ties go to the occurrence whose start is nearest.
     *
     * @param \stdClass[] $sessions Keyed by details id, with start_time and end_time.
     * @param \stdClass[] $occurrences Keyed by id, with timestart and timeend.
     * @param int $earlymargin Seconds before the window start that still count.
     * @param int $latemargin Seconds after the window end that still count.
     * @return array details id => occurrence id, for mapped sessions only.
     */
    public static function map_to_scheduled(array $sessions, array $occurrences, int $earlymargin, int $latemargin): array {
        $map = [];
        foreach ($sessions as $detailsid => $session) {
            [$start, $end] = self::span($session);
            $best = null;
            $bestoverlap = -1;
            $bestdistance = PHP_INT_MAX;
            foreach ($occurrences as $occurrenceid => $occurrence) {
                $from = (int) $occurrence->timestart - $earlymargin;
                $to = (int) $occurrence->timeend + $latemargin;
                if ($end < $from || $start > $to) {
                    continue;
                }
                $overlap = max(0, min($end, $to) - max($start, $from));
                $distance = abs($start - (int) $occurrence->timestart);
                if ($overlap > $bestoverlap || ($overlap === $bestoverlap && $distance < $bestdistance)) {
                    $best = $occurrenceid;
                    $bestoverlap = $overlap;
                    $bestdistance = $distance;
                }
            }
            if ($best !== null) {
                $map[$detailsid] = $best;
            }
        }
        return $map;
    }

    /**
     * Group sessions into inferred occurrences: consecutive sessions join a cluster when the
     * gap after the cluster's end is at most $gap seconds.
     *
     * @param \stdClass[] $sessions Keyed by details id, with uuid, start_time and end_time.
     * @param int $gap Maximum gap in seconds.
     * @return \stdClass[] Keyed by occurrence key, with timestart, timeend and detailsids.
     */
    public static function cluster(array $sessions, int $gap): array {
        uasort($sessions, function ($a, $b) {
            return [(int) $a->start_time, (int) $a->id] <=> [(int) $b->start_time, (int) $b->id];
        });

        $clusters = [];
        $current = null;
        foreach ($sessions as $detailsid => $session) {
            [$start, $end] = self::span($session);
            if ($current && $start <= $current->timeend + $gap) {
                $current->timeend = max($current->timeend, $end);
                $current->detailsids[] = $detailsid;
                continue;
            }
            if ($current) {
                $clusters[$current->key] = $current;
            }
            $current = (object) [
                'key' => 's:' . sha1((string) $session->uuid),
                'timestart' => $start,
                'timeend' => $end,
                'detailsids' => [$detailsid],
            ];
        }
        if ($current) {
            $clusters[$current->key] = $current;
        }
        return $clusters;
    }

    /**
     * Session span with a non-negative length.
     *
     * @param \stdClass $session
     * @return int[] [start, end]
     */
    public static function span(\stdClass $session): array {
        $start = (int) $session->start_time;
        return [$start, max($start, (int) $session->end_time)];
    }
}
