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
 * Community of Inquiry template — focused on what's observable in a single live session.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local\templates;

use bbbext_advgrd\local\metrics;

/**
 * Anchored in Garrison, Anderson & Archer (2000), but deliberately scoped to behaviours a
 * teacher can actually observe in one BBB session — not the full 4-phase practical inquiry
 * cycle (which plays out across many sessions and is not appropriate as a single-class rubric).
 *
 * Two criteria sit under cognitive presence (triggering inquiry, connecting ideas) and two
 * under social presence (open communication, group cohesion). Teaching presence is omitted by
 * default because in most live sessions only the instructor exhibits it; teachers running
 * peer-led seminars can add a teaching-presence criterion via the standard rubric editor.
 */
class coi extends template {
    /**
     * Stable id for the registry / picker URL.
     *
     * @return string
     */
    public static function id(): string {
        return 'coi';
    }

    /**
     * Localised display name shown in the templates picker.
     *
     * @return string
     */
    public static function name(): string {
        return get_string('tpl_coi_name', 'bbbext_advgrd');
    }

    /**
     * Localised description shown alongside the picker option.
     *
     * @return string
     */
    public static function description(): string {
        return get_string('tpl_coi_description', 'bbbext_advgrd');
    }

    /**
     * Bibliographic citation shown under the picker description.
     *
     * @return string
     */
    public static function citation(): string {
        return get_string('tpl_coi_citation', 'bbbext_advgrd');
    }

    /**
     * Localised name of the rubric/guide definition created on import.
     *
     * @return string
     */
    public static function definition_name(): string {
        return get_string('tpl_coi_definition_name', 'bbbext_advgrd');
    }

    /**
     * Criteria blueprint for this template — see {@see template::blueprint()}.
     *
     * @return array
     */
    protected static function blueprint(): array {
        $s = function (string $key): string {
            return get_string($key, 'bbbext_advgrd');
        };
        $cognitive = $s('coi_presence_cognitive');
        $social = $s('coi_presence_social');

        return [
            // Cognitive presence — observable indicators in a single session.
            [
                'group'      => 'cognitive',
                'grouplabel' => $cognitive,
                'criterion'  => $s('coi_crit_triggering'),
                'metric'     => metrics::METRIC_RAISEHAND,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('coi_lvl_triggering_0')],
                    ['score' => 1, 'definition' => $s('coi_lvl_triggering_1')],
                    ['score' => 2, 'definition' => $s('coi_lvl_triggering_2')],
                    ['score' => 3, 'definition' => $s('coi_lvl_triggering_3')],
                ],
                'thresholds' => [3 => 4, 2 => 2, 1 => 1, 0 => 0],
            ],
            [
                'group'      => 'cognitive',
                'grouplabel' => $cognitive,
                'criterion'  => $s('coi_crit_connecting'),
                'levels'     => [
                    ['score' => 0, 'definition' => $s('coi_lvl_connecting_0')],
                    ['score' => 1, 'definition' => $s('coi_lvl_connecting_1')],
                    ['score' => 2, 'definition' => $s('coi_lvl_connecting_2')],
                    ['score' => 3, 'definition' => $s('coi_lvl_connecting_3')],
                ],
            ],
            // Social presence.
            [
                'group'      => 'social',
                'grouplabel' => $social,
                'criterion'  => $s('coi_crit_opencomm'),
                'metric'     => metrics::METRIC_TALKS,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('coi_lvl_opencomm_0')],
                    ['score' => 1, 'definition' => $s('coi_lvl_opencomm_1')],
                    ['score' => 2, 'definition' => $s('coi_lvl_opencomm_2')],
                    ['score' => 3, 'definition' => $s('coi_lvl_opencomm_3')],
                ],
                // Talk-time thresholds in seconds.
                'thresholds' => [3 => 600, 2 => 240, 1 => 60, 0 => 0],
            ],
            [
                'group'      => 'social',
                'grouplabel' => $social,
                'criterion'  => $s('coi_crit_cohesion'),
                'metric'     => metrics::METRIC_DURATION,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('coi_lvl_cohesion_0')],
                    ['score' => 1, 'definition' => $s('coi_lvl_cohesion_1')],
                    ['score' => 2, 'definition' => $s('coi_lvl_cohesion_2')],
                ],
                // Attendance thresholds in seconds.
                'thresholds' => [2 => 2700, 1 => 900, 0 => 0],
            ],
        ];
    }
}
