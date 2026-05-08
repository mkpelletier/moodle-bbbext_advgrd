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
 * Quantity + Quality template — directly addresses the canonical participation tension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local\templates;

use bbbext_advgrd\local\metrics;

/**
 * Splits participation into a "quantity" axis (how often the student contributes) and a
 * "quality" axis (how substantive each contribution is) — the central design tension surfaced
 * in Bean & Peterson (1998) and Armstrong & Boud (1983), and revisited as a persistent issue
 * by Simon, Jiang & Fryer (2025).
 *
 * BBB engagement metrics map naturally to the quantity criteria; quality criteria are
 * deliberately metric-free because volume signals can't tell you whether a comment was
 * substantive (the paper is explicit about this trap).
 */
class quantity_quality extends template {
    /**
     * Stable id for the registry / picker URL.
     *
     * @return string
     */
    public static function id(): string {
        return 'quantity_quality';
    }

    /**
     * Localised display name shown in the templates picker.
     *
     * @return string
     */
    public static function name(): string {
        return get_string('tpl_qq_name', 'bbbext_advgrd');
    }

    /**
     * Localised description shown alongside the picker option.
     *
     * @return string
     */
    public static function description(): string {
        return get_string('tpl_qq_description', 'bbbext_advgrd');
    }

    /**
     * Bibliographic citation shown under the picker description.
     *
     * @return string
     */
    public static function citation(): string {
        return get_string('tpl_qq_citation', 'bbbext_advgrd');
    }

    /**
     * Localised name of the rubric/guide definition created on import.
     *
     * @return string
     */
    public static function definition_name(): string {
        return get_string('tpl_qq_definition_name', 'bbbext_advgrd');
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
        $quantity = $s('qq_group_quantity');
        $quality = $s('qq_group_quality');

        return [
            // Quantity axis — countable signals.
            [
                'group'      => 'quantity',
                'grouplabel' => $quantity,
                'criterion'  => $s('qq_crit_frequency'),
                'metric'     => metrics::METRIC_COMPOSITE,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('qq_lvl_frequency_0')],
                    ['score' => 1, 'definition' => $s('qq_lvl_frequency_1')],
                    ['score' => 2, 'definition' => $s('qq_lvl_frequency_2')],
                    ['score' => 3, 'definition' => $s('qq_lvl_frequency_3')],
                ],
                // Composite is 0..1; thresholds expressed at the same scale.
                'thresholds' => [3 => 0.7, 2 => 0.4, 1 => 0.15, 0 => 0],
            ],
            [
                'group'      => 'quantity',
                'grouplabel' => $quantity,
                'criterion'  => $s('qq_crit_presence'),
                'metric'     => metrics::METRIC_DURATION,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('qq_lvl_presence_0')],
                    ['score' => 1, 'definition' => $s('qq_lvl_presence_1')],
                    ['score' => 2, 'definition' => $s('qq_lvl_presence_2')],
                ],
                // Attendance thresholds in seconds (≥45 min, ≥15 min).
                'thresholds' => [2 => 2700, 1 => 900, 0 => 0],
            ],
            // Quality axis — judgement-only, no metric mapping.
            [
                'group'      => 'quality',
                'grouplabel' => $quality,
                'criterion'  => $s('qq_crit_depth'),
                'levels'     => [
                    ['score' => 0, 'definition' => $s('qq_lvl_depth_0')],
                    ['score' => 1, 'definition' => $s('qq_lvl_depth_1')],
                    ['score' => 2, 'definition' => $s('qq_lvl_depth_2')],
                    ['score' => 3, 'definition' => $s('qq_lvl_depth_3')],
                ],
            ],
            [
                'group'      => 'quality',
                'grouplabel' => $quality,
                'criterion'  => $s('qq_crit_listening'),
                'levels'     => [
                    ['score' => 0, 'definition' => $s('qq_lvl_listening_0')],
                    ['score' => 1, 'definition' => $s('qq_lvl_listening_1')],
                    ['score' => 2, 'definition' => $s('qq_lvl_listening_2')],
                    ['score' => 3, 'definition' => $s('qq_lvl_listening_3')],
                ],
            ],
        ];
    }
}
