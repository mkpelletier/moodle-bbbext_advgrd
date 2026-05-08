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
 * Inclusive multi-modal participation template.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local\templates;

use bbbext_advgrd\local\metrics;

/**
 * One criterion per modality — oral, written, responsive, group-work — so a student who is
 * strong on one modality is not penalised for being silent on another. Designed to address
 * the concerns surfaced by Shi & Tan (2020), Crosthwaite, Bailey & Meeker (2015), Tani (2008)
 * and O'Connor et al. (2017): vocal participation alone is a poor proxy for engagement, and
 * fairness across diverse learners requires acknowledging the multiple ways students show up
 * to a class.
 */
class inclusive extends template {
    /**
     * Stable id for the registry / picker URL.
     *
     * @return string
     */
    public static function id(): string {
        return 'inclusive';
    }

    /**
     * Localised display name shown in the templates picker.
     *
     * @return string
     */
    public static function name(): string {
        return get_string('tpl_inclusive_name', 'bbbext_advgrd');
    }

    /**
     * Localised description shown alongside the picker option.
     *
     * @return string
     */
    public static function description(): string {
        return get_string('tpl_inclusive_description', 'bbbext_advgrd');
    }

    /**
     * Bibliographic citation shown under the picker description.
     *
     * @return string
     */
    public static function citation(): string {
        return get_string('tpl_inclusive_citation', 'bbbext_advgrd');
    }

    /**
     * Localised name of the rubric/guide definition created on import.
     *
     * @return string
     */
    public static function definition_name(): string {
        return get_string('tpl_inclusive_definition_name', 'bbbext_advgrd');
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
        $modality = $s('inclusive_group_modality');

        return [
            [
                'group'      => 'oral',
                'grouplabel' => $modality,
                'criterion'  => $s('inclusive_crit_oral'),
                'metric'     => metrics::METRIC_TALKS,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('inclusive_lvl_oral_0')],
                    ['score' => 1, 'definition' => $s('inclusive_lvl_oral_1')],
                    ['score' => 2, 'definition' => $s('inclusive_lvl_oral_2')],
                    ['score' => 3, 'definition' => $s('inclusive_lvl_oral_3')],
                ],
                'thresholds' => [3 => 600, 2 => 240, 1 => 60, 0 => 0],
            ],
            [
                'group'      => 'written',
                'grouplabel' => $modality,
                'criterion'  => $s('inclusive_crit_written'),
                'metric'     => metrics::METRIC_CHATS,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('inclusive_lvl_written_0')],
                    ['score' => 1, 'definition' => $s('inclusive_lvl_written_1')],
                    ['score' => 2, 'definition' => $s('inclusive_lvl_written_2')],
                    ['score' => 3, 'definition' => $s('inclusive_lvl_written_3')],
                ],
                'thresholds' => [3 => 8, 2 => 4, 1 => 1, 0 => 0],
            ],
            [
                'group'      => 'responsive',
                'grouplabel' => $modality,
                'criterion'  => $s('inclusive_crit_responsive'),
                'metric'     => metrics::METRIC_RAISEHAND,
                'levels'     => [
                    ['score' => 0, 'definition' => $s('inclusive_lvl_responsive_0')],
                    ['score' => 1, 'definition' => $s('inclusive_lvl_responsive_1')],
                    ['score' => 2, 'definition' => $s('inclusive_lvl_responsive_2')],
                    ['score' => 3, 'definition' => $s('inclusive_lvl_responsive_3')],
                ],
                'thresholds' => [3 => 5, 2 => 2, 1 => 1, 0 => 0],
            ],
            [
                'group'      => 'group',
                'grouplabel' => $modality,
                'criterion'  => $s('inclusive_crit_groupwork'),
                'levels'     => [
                    ['score' => 0, 'definition' => $s('inclusive_lvl_groupwork_0')],
                    ['score' => 1, 'definition' => $s('inclusive_lvl_groupwork_1')],
                    ['score' => 2, 'definition' => $s('inclusive_lvl_groupwork_2')],
                    ['score' => 3, 'definition' => $s('inclusive_lvl_groupwork_3')],
                ],
            ],
        ];
    }
}
