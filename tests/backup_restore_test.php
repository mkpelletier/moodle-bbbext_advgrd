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
 * Backup/restore tests for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use bbbext_advgrd\local\annotations;
use bbbext_advgrd\local\grader;
use context_module;
use restore_date_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Proves the advanced-grading extension data rides the parent BBB activity through a full
 * backup + restore-to-a-different-course cycle, with all foreign keys remapped.
 *
 * @covers \backup_bbbext_advgrd_subplugin
 * @covers \restore_bbbext_advgrd_subplugin
 */
final class backup_restore_test extends restore_date_testcase {
    /**
     * A rubric-graded activity with config, metric mappings, a real grade (with grading
     * instance) and an annotation (with an attached file) survives restore to a new course.
     */
    public function test_advgrd_data_survives_restore_to_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $student = $dg->create_user();
        $teacher = $dg->create_user();
        $dg->enrol_user($student->id, $course->id, 'student');
        $dg->enrol_user($teacher->id, $course->id, 'editingteacher');

        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bbb->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $dg->get_plugin_generator('bbbext_advgrd');

        // 1. Config + rubric definition + criterion/metric mappings (import_template seeds both).
        $config = $gen->create_config($bbb->id, ['gradingmethod' => 'rubric', 'scoremode' => 'analytic']);
        grader::import_template($bbb->id, 'coi');

        $origmaps = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);
        $this->assertNotEmpty($origmaps, 'template import should have seeded metric mappings');
        $origcriterionids = array_map(static fn($m) => (int) $m->criterionid, $origmaps);

        // 2. Drive a real rubric grading instance and record a grade (exercises the deferred
        // gradinginstanceid remap).
        $manager = grader::get_grading_manager($bbb->id);
        $controller = $manager->get_controller('rubric');
        $controller->set_grade_range(make_grades_menu(100), true);
        $instance = $controller->get_or_create_instance(null, $teacher->id, $student->id);
        $crits = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $controller->get_definition()->id]);
        $rubricdata = ['criteria' => []];
        foreach ($crits as $c) {
            $top = $DB->get_record_sql(
                "SELECT id FROM {gradingform_rubric_levels} WHERE criterionid = :cid ORDER BY score DESC",
                ['cid' => $c->id],
                IGNORE_MULTIPLE
            );
            $rubricdata['criteria'][$c->id] = ['levelid' => (int) $top->id, 'remark' => '', 'remarkformat' => FORMAT_HTML];
        }
        $rawscore = $instance->submit_and_get_grade($rubricdata, $student->id);
        grader::record_grade($bbb->id, $student->id, $teacher->id, (float) $rawscore, (int) $instance->get_id());

        $origgrade = $DB->get_record(
            'bbbext_advgrd_grade',
            ['configid' => $config->id, 'userid' => $student->id],
            '*',
            MUST_EXIST
        );
        $this->assertNotEmpty($origgrade->gradinginstanceid);
        $origcritcount = count($crits);
        $origfillingcount = $DB->count_records('gradingform_rubric_fillings', ['instanceid' => $instance->get_id()]);
        $this->assertGreaterThan(0, $origfillingcount);

        // 3. An annotation addressed to the student, with a file in its comment filearea.
        $ann = annotations::create(
            (int) $bbb->id,
            'rec-xyz',
            (int) $student->id,
            (int) $teacher->id,
            5000,
            '<p>Nice contribution at 5s.</p>',
            FORMAT_HTML,
            'praise',
            file_get_unused_draft_itemid(),
            $context
        );
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'bbbext_advgrd',
            'filearea'  => annotations::FILEAREA,
            'itemid'    => $ann->id,
            'filepath'  => '/',
            'filename'  => 'clip.txt',
        ], 'pretend-audio');

        // Back up, then restore into a brand-new course.
        $newcourseid = $this->backup_and_restore($course);

        $newbbb = $DB->get_record('bigbluebuttonbn', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals((int) $bbb->id, (int) $newbbb->id);
        $newcm = get_coursemodule_from_instance('bigbluebuttonbn', $newbbb->id, $newcourseid, false, MUST_EXIST);
        $newcontext = context_module::instance($newcm->id);

        // Config restored (this was previously lost entirely).
        $newconfig = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $newbbb->id], '*', MUST_EXIST);
        $this->assertSame('rubric', $newconfig->gradingmethod);
        $this->assertSame('analytic', $newconfig->scoremode);

        // Metric mapping ROWS restored.
        $newmaps = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $newconfig->id]);
        $this->assertCount(count($origmaps), $newmaps);
        foreach ($newmaps as $m) {
            $this->assertSame((int) $newbbb->id, (int) $m->bigbluebuttonbnid);
        }

        // Grade restored with evidence + final score intact and user references remapped.
        $newgrade = $DB->get_record('bbbext_advgrd_grade', ['configid' => $newconfig->id], '*', MUST_EXIST);
        $this->assertSame((int) $student->id, (int) $newgrade->userid);
        $this->assertSame((int) $teacher->id, (int) $newgrade->graderid);
        $this->assertSame((int) $newbbb->id, (int) $newgrade->bigbluebuttonbnid);
        $this->assertEquals($origgrade->evidence, $newgrade->evidence);
        $this->assertEquals($origgrade->finalscore, $newgrade->finalscore);

        // Annotation restored with remapped users, preserved body, and its attached file carried over.
        $newann = $DB->get_record('bbbext_advgrd_annotation', ['bigbluebuttonbnid' => $newbbb->id], '*', MUST_EXIST);
        $this->assertSame('rec-xyz', $newann->recordingid);
        $this->assertSame((int) $student->id, (int) $newann->targetuserid);
        $this->assertSame((int) $teacher->id, (int) $newann->graderid);
        $this->assertStringContainsString('Nice contribution', $newann->body);
        $this->assertTrue(
            $fs->file_exists($newcontext->id, 'bbbext_advgrd', annotations::FILEAREA, $newann->id, '/', 'clip.txt'),
            'annotation comment-filearea file should survive the restore'
        );

        // Grading definition fidelity (rubric + criterion remap + grading instance).
        // The rubric/guide definition is registered under component 'bbbext_advgrd', which core's
        // activity-grading backup (component = mod_<modname>) does NOT capture, so the subplugin
        // must back it up itself for these to hold.
        $newarea = $DB->get_record(
            'grading_areas',
            ['contextid' => $newcontext->id, 'component' => 'bbbext_advgrd', 'areaname' => 'participation'],
            '*',
            MUST_EXIST
        );
        $newdef = $DB->get_record('grading_definitions', ['areaid' => $newarea->id, 'method' => 'rubric'], '*', MUST_EXIST);
        $newcritids = array_map('intval', $DB->get_fieldset_select(
            'gradingform_rubric_criteria',
            'id',
            'definitionid = ?',
            [$newdef->id]
        ));
        foreach ($newmaps as $m) {
            $this->assertContains(
                (int) $m->criterionid,
                $newcritids,
                'metric_map.criterionid must point at the restored rubric definition (deferred remap)'
            );
            $this->assertNotContains((int) $m->criterionid, $origcriterionids);
        }

        // Rubric definition restored with all its criteria.
        $this->assertCount($origcritcount, $newcritids);

        // Grading instance remapped to a valid new row, still tied to the restored definition.
        $this->assertNotEmpty($newgrade->gradinginstanceid);
        $this->assertNotEquals((int) $origgrade->gradinginstanceid, (int) $newgrade->gradinginstanceid);
        $newinstance = $DB->get_record('grading_instances', ['id' => $newgrade->gradinginstanceid], '*', MUST_EXIST);
        $this->assertSame((int) $newdef->id, (int) $newinstance->definitionid);
        $this->assertSame((int) $student->id, (int) $newinstance->itemid);
        $this->assertSame((int) $teacher->id, (int) $newinstance->raterid);

        // Per-criterion rubric fillings survived and point at the restored criteria/levels.
        $newfillings = $DB->get_records('gradingform_rubric_fillings', ['instanceid' => $newinstance->id]);
        $this->assertCount($origfillingcount, $newfillings);
        foreach ($newfillings as $filling) {
            $this->assertContains((int) $filling->criterionid, $newcritids);
            $this->assertTrue($DB->record_exists('gradingform_rubric_levels', ['id' => $filling->levelid]));
        }
    }

    /**
     * A guide-graded activity's definition (criteria + comments) and metric mappings survive
     * restore, with metric-map criterion links remapped onto the restored guide criteria.
     */
    public function test_guide_definition_survives_restore_to_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $dg->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id, ['gradingmethod' => 'guide']);
        grader::import_template($bbb->id, 'coi');

        $origmaps = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);
        $this->assertNotEmpty($origmaps);
        $origcritids = array_map('intval', $DB->get_fieldset_select(
            'gradingform_guide_criteria',
            'id',
            'definitionid = ?',
            [grader::get_grading_manager($bbb->id)->get_controller('guide')->get_definition()->id]
        ));
        $this->assertNotEmpty($origcritids);

        $newcourseid = $this->backup_and_restore($course);

        $newbbb = $DB->get_record('bigbluebuttonbn', ['course' => $newcourseid], '*', MUST_EXIST);
        $newcm = get_coursemodule_from_instance('bigbluebuttonbn', $newbbb->id, $newcourseid, false, MUST_EXIST);
        $newcontext = context_module::instance($newcm->id);
        $newconfig = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $newbbb->id], '*', MUST_EXIST);
        $this->assertSame('guide', $newconfig->gradingmethod);

        $newarea = $DB->get_record(
            'grading_areas',
            ['contextid' => $newcontext->id, 'component' => 'bbbext_advgrd', 'areaname' => 'participation'],
            '*',
            MUST_EXIST
        );
        $newdef = $DB->get_record('grading_definitions', ['areaid' => $newarea->id, 'method' => 'guide'], '*', MUST_EXIST);
        $newcritids = array_map('intval', $DB->get_fieldset_select(
            'gradingform_guide_criteria',
            'id',
            'definitionid = ?',
            [$newdef->id]
        ));
        $this->assertCount(count($origcritids), $newcritids);

        $newmaps = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $newconfig->id]);
        $this->assertCount(count($origmaps), $newmaps);
        foreach ($newmaps as $m) {
            $this->assertContains((int) $m->criterionid, $newcritids);
        }
    }

    /**
     * Grading configuration (config + metric mappings) must ride along even when user data is
     * NOT included in the backup — it is teacher setup, not user data.
     */
    public function test_config_survives_restore_without_userinfo(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $dg->get_plugin_generator('bbbext_advgrd');
        $config = $gen->create_config($bbb->id, ['gradingmethod' => 'rubric']);
        grader::import_template($bbb->id, 'coi');
        $origmapcount = $DB->count_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);

        // Backup WITHOUT users, then restore into a new course.
        $newcourseid = $this->backup_and_restore_without_users($course);

        $newbbb = $DB->get_record('bigbluebuttonbn', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfig = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $newbbb->id], '*', MUST_EXIST);
        $this->assertSame('rubric', $newconfig->gradingmethod);
        $this->assertSame(
            $origmapcount,
            $DB->count_records('bbbext_advgrd_metric_map', ['configid' => $newconfig->id])
        );
    }

    /**
     * Backup a course with user data OFF and restore it into a fresh course.
     *
     * Mirrors restore_date_testcase::backup_and_restore(), but with backup_general_users = 0,
     * so we can assert configuration survives independently of user data.
     *
     * @param \stdClass $course
     * @return int The new course id.
     */
    protected function backup_and_restore_without_users($course): int {
        global $USER, $CFG;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        set_config('backup_general_users', 0, 'backup');

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(false);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $fp = get_file_packer('application/vnd.moodle.backup');
        $filepath = $CFG->dataroot . '/temp/backup/test-restore-nouser';
        $file->extract_to_pathname($fp, $filepath);
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname,
            $course->shortname . '_nouser',
            $course->category
        );
        $rc = new \restore_controller(
            'test-restore-nouser',
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }
}
