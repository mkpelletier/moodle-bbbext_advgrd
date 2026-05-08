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
 * Grade item / advanced grading mappings for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bbbext_advgrd\grades;

use core_grades\local\gradeitem\advancedgrading_mapping;
use core_grades\local\gradeitem\itemnumber_mapping;

/**
 * Maps the plugin's grading area name ('participation') to a grade item number, so core's
 * advanced grading machinery (e.g., gradingform_*_controller::render_preview() →
 * get_min_max_score() → component_gradeitems::get_field_name_for_itemname()) can resolve our
 * area when rendering definition previews and grading forms.
 *
 * Item number 0 is the canonical "main" grade item that mirrors the BBB activity's grade column.
 * Analytic-mode item numbers 1..N are owned by grader::push_analytic_subscores() and aren't
 * registered here because they are dynamic (their count and labels depend on which template
 * the teacher imported); core grading doesn't need to enumerate them statically.
 */
class gradeitems implements advancedgrading_mapping, itemnumber_mapping {
    /**
     * itemname[itemnumber] mapping. Mirrors the area name used in self-registered grading_areas
     * rows (component=bbbext_advgrd, areaname=participation).
     *
     * @return array
     */
    public static function get_itemname_mapping_for_component(): array {
        return [
            0 => 'participation',
        ];
    }

    /**
     * The set of areas in this component that support advanced grading.
     *
     * @return array
     */
    public static function get_advancedgrading_itemnames(): array {
        return ['participation'];
    }
}
