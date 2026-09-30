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
 * Effective attendance settings.
 *
 * @package    local_zoomattendance
 * @copyright  2026 Mezbaur Are Rafi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomattendance\local;

/**
 * Effective settings for one activity: site defaults with per-activity overrides applied.
 */
class settings {
    /** @var string Measure against the scheduled window length. */
    public const DENOMINATOR_SCHEDULED = 'scheduled';
    /** @var string Measure against the time the meeting actually ran inside the window. */
    public const DENOMINATOR_ACTUAL = 'actual';

    /** @var bool Whether attendance is tracked for the activity. */
    public $enabled;
    /** @var int Minimum percentage for present. */
    public $presentpct;
    /** @var int Minimum percentage for partial; below it is absent. */
    public $latepct;
    /** @var int Minutes after the start a present participant may join. */
    public $lategracemins;
    /** @var string One of the DENOMINATOR_* constants. */
    public $denominator;

    /**
     * Site defaults.
     *
     * @return self
     */
    public static function site_defaults(): self {
        $config = get_config('local_zoomattendance');
        $settings = new self();
        $settings->enabled = !empty($config->defaultenabled);
        $settings->presentpct = isset($config->presentpct) ? (int) $config->presentpct : 75;
        $settings->latepct = isset($config->latepct) ? (int) $config->latepct : 50;
        $settings->lategracemins = isset($config->lategracemins) ? (int) $config->lategracemins : 10;
        $settings->denominator = ($config->denominator ?? '') === self::DENOMINATOR_ACTUAL
            ? self::DENOMINATOR_ACTUAL : self::DENOMINATOR_SCHEDULED;
        $settings->normalise();
        return $settings;
    }

    /**
     * Effective settings for a course module.
     *
     * @param int $cmid Course module id.
     * @return self
     */
    public static function for_cm(int $cmid): self {
        global $DB;
        return self::from_override($DB->get_record('local_zoomattendance_setting', ['cmid' => $cmid]) ?: null);
    }

    /**
     * Apply an override row (or none) to the site defaults.
     *
     * @param \stdClass|null $override A local_zoomattendance_setting row.
     * @return self
     */
    public static function from_override(?\stdClass $override): self {
        $settings = self::site_defaults();
        if ($override) {
            if ($override->enabled !== null) {
                $settings->enabled = (bool) $override->enabled;
            }
            foreach (['presentpct', 'latepct', 'lategracemins'] as $field) {
                if ($override->$field !== null) {
                    $settings->$field = (int) $override->$field;
                }
            }
            if (in_array($override->denominator, [self::DENOMINATOR_SCHEDULED, self::DENOMINATOR_ACTUAL], true)) {
                $settings->denominator = $override->denominator;
            }
        }
        $settings->normalise();
        return $settings;
    }

    /**
     * Keep thresholds in range and latepct no higher than presentpct.
     */
    protected function normalise(): void {
        $this->presentpct = max(0, min(100, $this->presentpct));
        $this->latepct = max(0, min($this->presentpct, $this->latepct));
        $this->lategracemins = max(0, $this->lategracemins);
    }

    /**
     * Seconds an occurrence's percentages are measured against.
     *
     * @param \stdClass $occurrence A local_zoomattendance_occ row.
     * @return int
     */
    public function denominator_for(\stdClass $occurrence): int {
        if ($this->denominator === self::DENOMINATOR_ACTUAL) {
            return (int) $occurrence->actualsecs;
        }
        return (int) $occurrence->timeend - (int) $occurrence->timestart;
    }

    /**
     * Whether teacher attendance is tracked (site setting).
     *
     * @return bool
     */
    public static function teacher_tracking(): bool {
        return !empty(get_config('local_zoomattendance', 'teachertracking'));
    }

    /**
     * Thresholds for teachers: site-level only, always against the scheduled window.
     *
     * The partial threshold is held in latepct, as for students, so status::evaluate() applies.
     *
     * @return self
     */
    public static function teacher(): self {
        $config = get_config('local_zoomattendance');
        $settings = new self();
        $settings->enabled = !empty($config->teachertracking);
        $settings->presentpct = isset($config->teacherpresentpct) ? (int) $config->teacherpresentpct : 90;
        $settings->latepct = isset($config->teacherpartialpct) ? (int) $config->teacherpartialpct : 50;
        $settings->lategracemins = isset($config->teachergracemins) ? (int) $config->teachergracemins : 5;
        $settings->denominator = self::DENOMINATOR_SCHEDULED;
        $settings->normalise();
        return $settings;
    }

    /**
     * Seconds after an occurrence ends, measured against mod_zoom's report watermark, before a
     * class without a session counts as not held.
     *
     * @return int
     */
    public static function teacher_notheld_delay(): int {
        $hours = get_config('local_zoomattendance', 'teachernotheldhours');
        return max(0, $hours === false || $hours === '' ? 24 : (int) $hours) * HOURSECS;
    }

    /**
     * When teacher tracking was last switched on. Classes ending before it are never marked not
     * held. Set now if missing, for example when the setting was changed outside the settings page.
     *
     * @return int
     */
    public static function teacher_tracking_since(): int {
        $since = (int) get_config('local_zoomattendance', 'teachertrackingsince');
        if (!$since) {
            $since = time();
            set_config('teachertrackingsince', $since, 'local_zoomattendance');
        }
        return $since;
    }

    /**
     * Settings page callback: remember when teacher tracking was switched on.
     */
    public static function teacher_tracking_updated(): void {
        if (self::teacher_tracking()) {
            set_config('teachertrackingsince', time(), 'local_zoomattendance');
        }
    }

    /**
     * Sync margins and cluster gap, in seconds.
     *
     * @return array [earlymargin, latemargin, clustergap]
     */
    public static function margins(): array {
        $config = get_config('local_zoomattendance');
        return [
            (int) ($config->earlymarginmins ?? 30) * MINSECS,
            (int) ($config->latemarginmins ?? 30) * MINSECS,
            (int) ($config->clustergapmins ?? 30) * MINSECS,
        ];
    }
}
