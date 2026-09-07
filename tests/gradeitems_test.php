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
 * Tests for bbbext_advgrd\grades\gradeitems and the language strings core derives from it.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\grades\gradeitems;
use grading_manager;

/**
 * The item names declared here are not just data: core turns each one into a language-string
 * key, so adding or renaming an item silently breaks the UI unless the lang file keeps up.
 * These tests tie the two together.
 *
 * @covers \bbbext_advgrd\grades\gradeitems
 */
final class gradeitems_test extends advanced_testcase {
    /**
     * Every advanced-grading item name needs a 'gradeitem:<name>' string - grading_manager::
     * available_areas() labels the grading-area picker with it, and renders the raw
     * [[gradeitem:...]] placeholder when it is absent.
     *
     * @return void
     */
    public function test_every_advancedgrading_itemname_has_a_label_string(): void {
        $sm = get_string_manager();
        foreach (gradeitems::get_advancedgrading_itemnames() as $itemname) {
            $this->assertTrue(
                $sm->string_exists("gradeitem:{$itemname}", 'bbbext_advgrd'),
                "Missing \$string['gradeitem:{$itemname}'] in lang/en/bbbext_advgrd.php"
            );
        }
    }

    /**
     * Every mapped item number needs a 'grade_<name>_name' string - course/moodleform_mod.php
     * and the completion form_trait use it to name the grade item in the activity settings.
     *
     * @return void
     */
    public function test_every_mapped_itemname_has_a_grade_name_string(): void {
        $sm = get_string_manager();
        foreach (gradeitems::get_itemname_mapping_for_component() as $itemname) {
            $this->assertTrue(
                $sm->string_exists("grade_{$itemname}_name", 'bbbext_advgrd'),
                "Missing \$string['grade_{$itemname}_name'] in lang/en/bbbext_advgrd.php"
            );
        }
    }

    /**
     * The end result core actually shows: a labelled grading area, with no unresolved
     * string placeholder in it.
     *
     * @return void
     */
    public function test_available_areas_resolves_to_a_real_label(): void {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $areas = grading_manager::available_areas('bbbext_advgrd');

        $this->assertArrayHasKey('participation', $areas);
        $this->assertStringNotContainsString('[[', $areas['participation']);
        $this->assertSame('BigBlueButton participation', $areas['participation']);
    }
}
