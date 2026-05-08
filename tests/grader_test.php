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
 * Tests for bbbext_advgrd\local\grader.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\grader;
use moodle_exception;

/**
 * @covers \bbbext_advgrd\local\grader
 */
final class grader_test extends advanced_testcase {

    public function test_bootstrap_returns_full_context(): void {
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();
        $info = grader::bootstrap($bbb->id);
        $this->assertSame((int) $bbb->id, (int) $info['bbb']->id);
        $this->assertSame('bigbluebuttonbn', $info['cm']->modname);
        $this->assertSame(CONTEXT_MODULE, $info['context']->contextlevel);
        // Our mod_instance_helper auto-creates a config row on activity creation, defaulting
        // gradingmethod to 'none'. Bootstrap surfaces it.
        $this->assertNotNull($info['config']);
        $this->assertSame('none', $info['config']->gradingmethod);
    }

    public function test_get_grading_manager_throws_when_method_none(): void {
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['gradingmethod' => 'none']);

        $this->expectException(moodle_exception::class);
        grader::get_grading_manager($bbb->id);
    }

    public function test_get_grading_manager_sets_active_method(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['gradingmethod' => 'rubric']);

        $manager = grader::get_grading_manager($bbb->id);
        $this->assertSame('rubric', $manager->get_active_method());

        $row = $DB->get_record('grading_areas',
            ['component' => 'bbbext_advgrd', 'areaname' => 'participation']);
        $this->assertNotEmpty($row);
        $this->assertSame('rubric', $row->activemethod);
    }

    public function test_import_template_creates_definition_and_mappings(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id, ['gradingmethod' => 'rubric']);

        grader::import_template($bbb->id, 'coi');

        $manager = grader::get_grading_manager($bbb->id);
        $controller = $manager->get_controller('rubric');
        $this->assertTrue($controller->is_form_defined());
        // The revised CoI template ships with four criteria (two cognitive, two social).
        $this->assertSame(4, $DB->count_records('gradingform_rubric_criteria',
            ['definitionid' => $controller->get_definition()->id]));
        $this->assertGreaterThan(0, $DB->count_records('bbbext_advgrd_metric_map',
            ['configid' => $config->id]));
    }

    public function test_import_template_refuses_overwrite(): void {
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['gradingmethod' => 'rubric']);
        grader::import_template($bbb->id, 'coi');

        $this->expectException(moodle_exception::class);
        grader::import_template($bbb->id, 'coi');
    }

    public function test_save_metric_mappings_replaces_existing(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id);
        grader::import_template($bbb->id, 'coi');
        $before = $DB->count_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);
        $this->assertGreaterThan(0, $before);

        grader::save_metric_mappings((int) $config->id, [[
            'criterionid' => 99999, 'metric' => 'duration',
            'thresholds' => [2 => 1800, 1 => 600, 0 => 0], 'weight' => 2.0,
        ]]);

        $rows = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('duration', $row->metric);
        $this->assertEquals(2.0, (float) $row->weight);
    }

    public function test_save_metric_mappings_skips_none_metric(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id);

        grader::save_metric_mappings((int) $config->id, [
            ['criterionid' => 1, 'metric' => 'none', 'thresholds' => [], 'weight' => 1.0],
            ['criterionid' => 2, 'metric' => 'chats', 'thresholds' => [1 => 1], 'weight' => 1.0],
        ]);

        $this->assertSame(1, $DB->count_records('bbbext_advgrd_metric_map', ['configid' => $config->id]));
    }

    public function test_suggest_levels_uses_evidence(): void {
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['gradingmethod' => 'rubric']);
        grader::import_template($bbb->id, 'coi');
        $gen->seed_evidence($bbb->id, $user->id, [
            'duration' => 2700, 'talks' => 480, 'chats' => 9,
            'raisehand' => 4, 'polls' => 2, 'emojis' => 6,
        ]);

        $suggestions = grader::suggest_levels($bbb->id, $user->id);

        // The revised CoI template seeds three metric mappings: triggering→raisehand,
        // open-communication→talks, group-cohesion→duration. Each must produce a non-negative
        // suggestion given the seeded evidence.
        $this->assertCount(3, $suggestions);
        foreach ($suggestions as $cid => $score) {
            $this->assertIsInt($cid);
            $this->assertGreaterThanOrEqual(0, $score);
        }
    }

    public function test_record_grade_inserts_row_and_pushes_to_gradebook(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id);
        $admin = get_admin();
        $this->setUser($admin);

        grader::record_grade($bbb->id, $user->id, $admin->id, 87.5, null);

        $row = $DB->get_record('bbbext_advgrd_grade',
            ['configid' => $config->id, 'userid' => $user->id]);
        $this->assertNotEmpty($row);
        $this->assertEquals(87.5, (float) $row->finalscore);
        $this->assertSame((int) $admin->id, (int) $row->graderid);

        $grades = grade_get_grades($bbb->course, 'mod', 'bigbluebuttonbn', $bbb->id, [$user->id]);
        $item = reset($grades->items);
        $this->assertEquals(87.5, (float) $item->grades[$user->id]->grade);
    }

    public function test_record_grade_clamps_to_max(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id);
        $this->setAdminUser();

        grader::record_grade($bbb->id, $user->id, get_admin()->id, 250.0, null);

        $row = $DB->get_record('bbbext_advgrd_grade',
            ['userid' => $user->id]);
        $this->assertEquals(100.0, (float) $row->finalscore);
    }

    public function test_analytic_mode_creates_one_grade_item_per_template_group(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();
        $this->setAdminUser();
        $admin = get_admin();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['gradingmethod' => 'rubric', 'scoremode' => 'analytic']);
        grader::import_template($bbb->id, 'coi');

        // Drive a real rubric grading instance — top level on every criterion.
        $manager = grader::get_grading_manager($bbb->id);
        $controller = $manager->get_controller('rubric');
        $controller->set_grade_range(make_grades_menu(100), true);
        $instance = $controller->get_or_create_instance(null, $admin->id, $user->id);
        $crits = $DB->get_records('gradingform_rubric_criteria',
            ['definitionid' => $controller->get_definition()->id]);
        $rubricdata = ['criteria' => []];
        foreach ($crits as $c) {
            $top = $DB->get_record_sql(
                "SELECT id FROM {gradingform_rubric_levels}
                  WHERE criterionid = :cid ORDER BY score DESC",
                ['cid' => $c->id], IGNORE_MULTIPLE);
            $rubricdata['criteria'][$c->id] = [
                'levelid' => (int) $top->id, 'remark' => '', 'remarkformat' => FORMAT_HTML,
            ];
        }
        $rawscore = $instance->submit_and_get_grade($rubricdata, $user->id);
        grader::record_grade($bbb->id, $user->id, $admin->id, (float) $rawscore, (int) $instance->get_id());

        // The revised CoI template defines two analytic groups (cognitive, social), so we expect
        // 1 base grade item + 2 analytic items = 3 total.
        $items = $DB->get_records('grade_items', [
            'itemmodule' => 'bigbluebuttonbn',
            'iteminstance' => $bbb->id,
        ], 'itemnumber');
        $this->assertCount(3, $items, 'expected 1 base + 2 analytic grade items');

        $analyticitems = array_filter($items, fn($it) => (int) $it->itemnumber > 0);
        $this->assertCount(2, $analyticitems);
        foreach ($analyticitems as $it) {
            $g = $DB->get_record('grade_grades', ['itemid' => $it->id, 'userid' => $user->id]);
            $this->assertNotEmpty($g, "no grade row for itemnumber {$it->itemnumber}");
            $this->assertEquals(100.0, (float) $g->rawgrade);
        }
    }

    public function test_analytic_mode_skipped_when_passthrough_off(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();
        $this->setAdminUser();
        $admin = get_admin();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, [
            'gradingmethod' => 'rubric',
            'scoremode' => 'analytic',
            'passthroughtogradebook' => 0,
        ]);
        grader::import_template($bbb->id, 'coi');

        // Use a fake instanceid so push_analytic_subscores has a value to act on; passthrough off
        // should mean no gradebook items get created at all.
        grader::record_grade($bbb->id, $user->id, $admin->id, 50.0, 99999);

        $items = $DB->get_records('grade_items', [
            'itemmodule' => 'bigbluebuttonbn', 'iteminstance' => $bbb->id,
        ]);
        // The BBB module's own grade item (itemnumber=0) may exist from create_module; what matters
        // is that no analytic items (1..3) were created.
        foreach ($items as $it) {
            $this->assertNotContains((int) $it->itemnumber, [1, 2, 3]);
        }
    }

    public function test_record_grade_respects_passthrough_off(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id, ['passthroughtogradebook' => 0]);
        $this->setAdminUser();

        grader::record_grade($bbb->id, $user->id, get_admin()->id, 75.0, null);

        $grades = grade_get_grades($bbb->course, 'mod', 'bigbluebuttonbn', $bbb->id, [$user->id]);
        $item = reset($grades->items);
        $this->assertNull($item->grades[$user->id]->grade);
    }

    /**
     * Helper: create a course, BBB activity (grade=100), and an enrolled student.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function seed_bbb_with_user(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $user = $dg->create_user();
        $dg->enrol_user($user->id, $course->id, 'student');
        return [$bbb, $user];
    }
}
