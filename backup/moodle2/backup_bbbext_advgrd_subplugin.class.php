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
 * Backup support for the BigBlueButton Advanced Grading extension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Attaches the advanced-grading extension data to a BigBlueButton activity backup.
 *
 * The parent module calls add_subplugin_structure('bbbext', $bigbluebuttonbn) in its backup
 * step, so every bbbext_ subplugin that owns data must provide this class or its rows are
 * silently dropped on backup/restore/import/duplicate.
 *
 * What we back up:
 *   - bbbext_advgrd_config      — per-instance grading setup (always; teacher configuration).
 *   - bbbext_advgrd_metric_map  — criterion/metric mappings (always; teacher configuration).
 *   - bbbext_advgrd_grade       — per-user grades + frozen evidence (userinfo only; user data).
 *   - bbbext_advgrd_annotation  — per-recording feedback comments + comment filearea
 *                                 (userinfo only; user data).
 *   - The advanced-grading definition itself — the rubric/guide the plugin registers under its
 *     OWN component (bbbext_advgrd / area 'participation'). Core's activity-grading backup only
 *     captures areas whose component is mod_<modname>, so it never touches ours; we back up the
 *     grading area, definition, rubric/guide criteria + levels + comments, and (with userinfo)
 *     grading instances and their fillings ourselves.
 *
 * Deliberately NOT backed up:
 *   - bbbext_advgrd_rec_probe   — a transient, server-bound cache of recording media URLs that
 *                                 is meaningless once restored (rebuilt on demand).
 *   - bbbext_advgrd_comlib      — the comment library is scoped to a user or a course, not to a
 *                                 single activity; riding it on the activity backup would
 *                                 duplicate it once per BBB instance in the course.
 */
class backup_bbbext_advgrd_subplugin extends backup_subplugin {
    /**
     * Define the extension structure hung off the bigbluebuttonbn activity element.
     *
     * @return backup_subplugin_element
     */
    protected function define_bigbluebuttonbn_subplugin_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Root wrapper for this subplugin (name must match get_recommended_name() so the
        // restore side's get_pathfor() resolves the same XML paths).
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $subplugin->add_child($wrapper);

        // Config, with its child metric mappings and grades. Element names are prefixed
        // (advgrd_*) because backup element names share one registry with the whole activity
        // tree — an unprefixed 'grade' would collide with the bigbluebuttonbn 'grade' field.
        $configs = new backup_nested_element('advgrd_configs');
        $config = new backup_nested_element('advgrd_config', ['id'], [
            'gradingmethod', 'scoremode', 'passthroughtogradebook', 'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($configs);
        $configs->add_child($config);

        $metricmaps = new backup_nested_element('advgrd_metric_maps');
        $metricmap = new backup_nested_element('advgrd_metric_map', ['id'], [
            'criterionid', 'metric', 'thresholds', 'weight',
        ]);
        $config->add_child($metricmaps);
        $metricmaps->add_child($metricmap);

        $grades = new backup_nested_element('advgrd_grades');
        $grade = new backup_nested_element('advgrd_grade', ['id'], [
            'userid', 'gradinginstanceid', 'rawscore', 'finalscore', 'evidence', 'graderid', 'timegraded',
        ]);
        $config->add_child($grades);
        $grades->add_child($grade);

        // Annotations hang directly off the activity: they are keyed by recording + student,
        // not by the grading config, and can exist even when no config row is present.
        $annotations = new backup_nested_element('advgrd_annotations');
        $annotation = new backup_nested_element('advgrd_annotation', ['id'], [
            'recordingid', 'targetuserid', 'graderid', 'timestampms', 'body', 'bodyformat',
            'commenttype', 'timecreated', 'timemodified',
        ]);
        $wrapper->add_child($annotations);
        $annotations->add_child($annotation);

        // The advanced-grading definition owned by component 'bbbext_advgrd'.
        $gareas = new backup_nested_element('advgrd_gradingareas');
        $garea = new backup_nested_element('advgrd_gradingarea', ['id'], ['areaname', 'activemethod']);

        $gdefs = new backup_nested_element('advgrd_gradingdefinitions');
        $gdef = new backup_nested_element('advgrd_gradingdefinition', ['id'], [
            'method', 'name', 'description', 'descriptionformat', 'status', 'copiedfromid',
            'timecreated', 'usercreated', 'timemodified', 'usermodified', 'timecopied', 'options',
        ]);

        $rcrits = new backup_nested_element('advgrd_rubriccriteria');
        $rcrit = new backup_nested_element('advgrd_rubriccriterion', ['id'], [
            'sortorder', 'description', 'descriptionformat',
        ]);
        $rlevels = new backup_nested_element('advgrd_rubriclevels');
        $rlevel = new backup_nested_element('advgrd_rubriclevel', ['id'], [
            'score', 'definition', 'definitionformat',
        ]);

        $gcrits = new backup_nested_element('advgrd_guidecriteria');
        $gcrit = new backup_nested_element('advgrd_guidecriterion', ['id'], [
            'sortorder', 'shortname', 'description', 'descriptionformat',
            'descriptionmarkers', 'descriptionmarkersformat', 'maxscore',
        ]);
        $gcomments = new backup_nested_element('advgrd_guidecomments');
        $gcomment = new backup_nested_element('advgrd_guidecomment', ['id'], [
            'sortorder', 'description', 'descriptionformat',
        ]);

        $ginstances = new backup_nested_element('advgrd_gradinginstances');
        $ginstance = new backup_nested_element('advgrd_gradinginstance', ['id'], [
            'raterid', 'itemid', 'rawgrade', 'status', 'feedback', 'feedbackformat', 'timemodified',
        ]);
        $rfillings = new backup_nested_element('advgrd_rubricfillings');
        $rfilling = new backup_nested_element('advgrd_rubricfilling', ['id'], [
            'criterionid', 'levelid', 'remark', 'remarkformat',
        ]);
        $gfillings = new backup_nested_element('advgrd_guidefillings');
        $gfilling = new backup_nested_element('advgrd_guidefilling', ['id'], [
            'criterionid', 'remark', 'remarkformat', 'score',
        ]);

        $wrapper->add_child($gareas);
        $gareas->add_child($garea);
        $garea->add_child($gdefs);
        $gdefs->add_child($gdef);
        $gdef->add_child($rcrits);
        $rcrits->add_child($rcrit);
        $rcrit->add_child($rlevels);
        $rlevels->add_child($rlevel);
        $gdef->add_child($gcrits);
        $gcrits->add_child($gcrit);
        $gdef->add_child($gcomments);
        $gcomments->add_child($gcomment);
        $gdef->add_child($ginstances);
        $ginstances->add_child($ginstance);
        $ginstance->add_child($rfillings);
        $rfillings->add_child($rfilling);
        $ginstance->add_child($gfillings);
        $gfillings->add_child($gfilling);

        // Sources. VAR_PARENTID at config level is the bigbluebuttonbn id; at metric_map/grade
        // level it is the (grouping-transparent) config id.
        $config->set_source_table('bbbext_advgrd_config', ['bigbluebuttonbnid' => backup::VAR_PARENTID]);
        $metricmap->set_source_table('bbbext_advgrd_metric_map', ['configid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $grade->set_source_table('bbbext_advgrd_grade', ['configid' => backup::VAR_PARENTID]);
            $annotation->set_source_table('bbbext_advgrd_annotation', ['bigbluebuttonbnid' => backup::VAR_PARENTID]);
        }

        // Grading area/definition sources (this activity's module context, our component only).
        $garea->set_source_table('grading_areas', [
            'contextid' => backup::VAR_CONTEXTID,
            'component' => ['sqlparam' => 'bbbext_advgrd'],
        ]);
        $gdef->set_source_table('grading_definitions', ['areaid' => backup::VAR_PARENTID]);
        $rcrit->set_source_table('gradingform_rubric_criteria', ['definitionid' => backup::VAR_PARENTID]);
        $rlevel->set_source_table('gradingform_rubric_levels', ['criterionid' => backup::VAR_PARENTID]);
        $gcrit->set_source_table('gradingform_guide_criteria', ['definitionid' => backup::VAR_PARENTID]);
        $gcomment->set_source_table('gradingform_guide_comments', ['definitionid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $ginstance->set_source_table('grading_instances', ['definitionid' => backup::VAR_PARENTID]);
            $rfilling->set_source_table('gradingform_rubric_fillings', ['instanceid' => backup::VAR_PARENTID]);
            $gfilling->set_source_table('gradingform_guide_fillings', ['instanceid' => backup::VAR_PARENTID]);
        }

        // Id annotations (user data only).
        $grade->annotate_ids('user', 'userid');
        $grade->annotate_ids('user', 'graderid');
        $annotation->annotate_ids('user', 'targetuserid');
        $annotation->annotate_ids('user', 'graderid');
        // In this plugin grading_instances.itemid is the graded user's id (see pages/grade.php).
        $ginstance->annotate_ids('user', 'raterid');
        $ginstance->annotate_ids('user', 'itemid');

        // Files: annotation bodies (audio/image) and the grading definition description.
        $annotation->annotate_files('bbbext_advgrd', \bbbext_advgrd\local\annotations::FILEAREA, 'id');
        $gdef->annotate_files('grading', 'description', 'id');

        return $subplugin;
    }
}
