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
 * Restore support for the BigBlueButton Advanced Grading extension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the advanced-grading extension data grafted onto a BigBlueButton activity.
 *
 * This restores our own tables (config, metric_map, grade, annotation) AND the advanced-grading
 * definition the plugin registers under its own component (grading area/definition, rubric/guide
 * criteria + levels + comments, grading instances + fillings) — core's activity-grading restore
 * only handles mod_<modname> areas, so it never restores ours.
 *
 * Ordering note: the criterion ids on metric_map and the grading-instance id on grade are remapped
 * in after_restore_bigbluebuttonbn(). Everything they point at (criteria, instances) is restored in
 * THIS same step, but not necessarily before the config subtree is processed, so we keep the old
 * ids on insert and rewrite them once the whole step's mappings exist.
 */
class restore_bbbext_advgrd_subplugin extends restore_subplugin {
    /**
     * Metric-map rows awaiting criterion remap in after_restore.
     *
     * @var array[] Each entry: {id: newrowid, configid: newconfigid, oldcriterionid: int}.
     */
    protected $pendingcriteria = [];

    /**
     * Grade rows awaiting grading-instance remap in after_restore.
     *
     * @var array[] Each entry: {id: newrowid, oldinstanceid: int}.
     */
    protected $pendinginstances = [];

    /**
     * Paths this subplugin handles under the bigbluebuttonbn element.
     *
     * @return restore_path_element[]
     */
    protected function define_bigbluebuttonbn_subplugin_structure() {
        $gdef = '/advgrd_gradingareas/advgrd_gradingarea/advgrd_gradingdefinitions/advgrd_gradingdefinition';
        $ginst = $gdef . '/advgrd_gradinginstances/advgrd_gradinginstance';

        return [
            new restore_path_element(
                $this->get_namefor('config'),
                $this->get_pathfor('/advgrd_configs/advgrd_config')
            ),
            new restore_path_element(
                $this->get_namefor('metric_map'),
                $this->get_pathfor('/advgrd_configs/advgrd_config/advgrd_metric_maps/advgrd_metric_map')
            ),
            new restore_path_element(
                $this->get_namefor('grade'),
                $this->get_pathfor('/advgrd_configs/advgrd_config/advgrd_grades/advgrd_grade')
            ),
            new restore_path_element(
                $this->get_namefor('annotation'),
                $this->get_pathfor('/advgrd_annotations/advgrd_annotation')
            ),
            new restore_path_element(
                $this->get_namefor('gradingarea'),
                $this->get_pathfor('/advgrd_gradingareas/advgrd_gradingarea')
            ),
            new restore_path_element(
                $this->get_namefor('gradingdefinition'),
                $this->get_pathfor($gdef)
            ),
            new restore_path_element(
                $this->get_namefor('rubriccriterion'),
                $this->get_pathfor($gdef . '/advgrd_rubriccriteria/advgrd_rubriccriterion')
            ),
            new restore_path_element(
                $this->get_namefor('rubriclevel'),
                $this->get_pathfor($gdef . '/advgrd_rubriccriteria/advgrd_rubriccriterion/advgrd_rubriclevels/advgrd_rubriclevel')
            ),
            new restore_path_element(
                $this->get_namefor('guidecriterion'),
                $this->get_pathfor($gdef . '/advgrd_guidecriteria/advgrd_guidecriterion')
            ),
            new restore_path_element(
                $this->get_namefor('guidecomment'),
                $this->get_pathfor($gdef . '/advgrd_guidecomments/advgrd_guidecomment')
            ),
            new restore_path_element(
                $this->get_namefor('gradinginstance'),
                $this->get_pathfor($ginst)
            ),
            new restore_path_element(
                $this->get_namefor('rubricfilling'),
                $this->get_pathfor($ginst . '/advgrd_rubricfillings/advgrd_rubricfilling')
            ),
            new restore_path_element(
                $this->get_namefor('guidefilling'),
                $this->get_pathfor($ginst . '/advgrd_guidefillings/advgrd_guidefilling')
            ),
        ];
    }

    /**
     * Restore a config row and map its id so child mappings/grades can find the new parent.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_config($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->bigbluebuttonbnid = $this->get_new_parentid('bigbluebuttonbn');
        $newid = $DB->insert_record('bbbext_advgrd_config', $data);
        $this->set_mapping('bbbext_advgrd_config', $oldid, $newid);
    }

    /**
     * Restore a criterion/metric mapping. The criterion id belongs to the grading definition
     * restored elsewhere in this step, so we keep the old id and remap in after_restore.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_metric_map($data) {
        global $DB;
        $data = (object) $data;
        $data->configid = $this->get_new_parentid('bbbext_advgrd_config');
        $data->bigbluebuttonbnid = $this->get_new_parentid('bigbluebuttonbn');
        $oldcriterionid = (int) $data->criterionid;
        $newid = $DB->insert_record('bbbext_advgrd_metric_map', $data);
        $this->pendingcriteria[] = (object) [
            'id'             => $newid,
            'configid'       => $data->configid,
            'oldcriterionid' => $oldcriterionid,
        ];
    }

    /**
     * Restore a per-user grade row. userid/graderid remap immediately; gradinginstanceid is
     * nulled here and remapped in after_restore once the grading instances exist.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_grade($data) {
        global $DB;
        $data = (object) $data;
        $data->configid = $this->get_new_parentid('bbbext_advgrd_config');
        $data->bigbluebuttonbnid = $this->get_new_parentid('bigbluebuttonbn');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!empty($data->graderid)) {
            $data->graderid = $this->get_mappingid('user', $data->graderid);
        }
        $oldinstanceid = empty($data->gradinginstanceid) ? null : (int) $data->gradinginstanceid;
        $data->gradinginstanceid = null;
        $newid = $DB->insert_record('bbbext_advgrd_grade', $data);
        if ($oldinstanceid !== null) {
            $this->pendinginstances[] = (object) [
                'id'            => $newid,
                'oldinstanceid' => $oldinstanceid,
            ];
        }
    }

    /**
     * Restore a feedback annotation. targetuserid/graderid remap immediately; the attached
     * filearea is remapped in after_execute via set_mapping(restorefiles = true).
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_annotation($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->bigbluebuttonbnid = $this->get_new_parentid('bigbluebuttonbn');
        $data->targetuserid = $this->get_mappingid('user', $data->targetuserid);
        if (!empty($data->graderid)) {
            $data->graderid = $this->get_mappingid('user', $data->graderid);
        }
        $newid = $DB->insert_record('bbbext_advgrd_annotation', $data);
        // Passing restorefiles = true records the file context so after_execute can rewrite the files.
        $this->set_mapping('bbbext_advgrd_annotation', $oldid, $newid, true);
    }

    /**
     * Restore the grading area into the new module context.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_gradingarea($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->contextid = $this->task->get_contextid();
        $data->component = 'bbbext_advgrd';
        $newid = $DB->insert_record('grading_areas', $data);
        $this->set_mapping('bbbext_advgrd_gradingarea', $oldid, $newid);
    }

    /**
     * Restore a grading definition. Ownership/time fields are reset to the restoring user/now,
     * mirroring core's restore_activity_grading_structure_step.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_gradingdefinition($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->areaid = $this->get_new_parentid('bbbext_advgrd_gradingarea');
        $data->copiedfromid = null;
        $data->timecreated = time();
        $data->usercreated = $this->task->get_userid();
        $data->timemodified = $data->timecreated;
        $data->usermodified = $data->usercreated;
        $newid = $DB->insert_record('grading_definitions', $data);
        // Passing restorefiles = true so after_execute can rewrite the definition description files.
        $this->set_mapping('bbbext_advgrd_gradingdefinition', $oldid, $newid, true);
    }

    /**
     * Restore a rubric criterion; its id is what metric_map and rubric fillings remap against.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_rubriccriterion($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->definitionid = $this->get_new_parentid('bbbext_advgrd_gradingdefinition');
        $newid = $DB->insert_record('gradingform_rubric_criteria', $data);
        $this->set_mapping('bbbext_advgrd_rubriccriterion', $oldid, $newid);
    }

    /**
     * Restore a rubric level.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_rubriclevel($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->criterionid = $this->get_new_parentid('bbbext_advgrd_rubriccriterion');
        $newid = $DB->insert_record('gradingform_rubric_levels', $data);
        $this->set_mapping('bbbext_advgrd_rubriclevel', $oldid, $newid);
    }

    /**
     * Restore a guide criterion.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_guidecriterion($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->definitionid = $this->get_new_parentid('bbbext_advgrd_gradingdefinition');
        $newid = $DB->insert_record('gradingform_guide_criteria', $data);
        $this->set_mapping('bbbext_advgrd_guidecriterion', $oldid, $newid);
    }

    /**
     * Restore a guide frequently-used comment.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_guidecomment($data) {
        global $DB;
        $data = (object) $data;
        $data->definitionid = $this->get_new_parentid('bbbext_advgrd_gradingdefinition');
        $DB->insert_record('gradingform_guide_comments', $data);
    }

    /**
     * Restore a grading instance. raterid and itemid (the graded user) remap as users.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_gradinginstance($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->definitionid = $this->get_new_parentid('bbbext_advgrd_gradingdefinition');
        $data->raterid = $this->get_mappingid('user', $data->raterid);
        if (!empty($data->itemid)) {
            $data->itemid = $this->get_mappingid('user', $data->itemid);
        }
        $newid = $DB->insert_record('grading_instances', $data);
        $this->set_mapping('bbbext_advgrd_gradinginstance', $oldid, $newid);
    }

    /**
     * Restore a rubric filling, remapping the criterion and level onto the restored definition.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_rubricfilling($data) {
        global $DB;
        $data = (object) $data;
        $data->instanceid = $this->get_new_parentid('bbbext_advgrd_gradinginstance');
        $data->criterionid = $this->get_mappingid('bbbext_advgrd_rubriccriterion', $data->criterionid);
        $data->levelid = $this->get_mappingid('bbbext_advgrd_rubriclevel', $data->levelid);
        if (!empty($data->criterionid)) {
            $DB->insert_record('gradingform_rubric_fillings', $data);
        }
    }

    /**
     * Restore a guide filling, remapping the criterion onto the restored definition.
     *
     * @param array $data
     */
    public function process_bbbext_advgrd_guidefilling($data) {
        global $DB;
        $data = (object) $data;
        $data->instanceid = $this->get_new_parentid('bbbext_advgrd_gradinginstance');
        $data->criterionid = $this->get_mappingid('bbbext_advgrd_guidecriterion', $data->criterionid);
        if (!empty($data->criterionid)) {
            $DB->insert_record('gradingform_guide_fillings', $data);
        }
    }

    /**
     * Rewrite fileareas once every row has been inserted: annotation comments and the grading
     * definition description.
     */
    protected function after_execute_bigbluebuttonbn() {
        $this->add_related_files(
            'bbbext_advgrd',
            \bbbext_advgrd\local\annotations::FILEAREA,
            'bbbext_advgrd_annotation'
        );
        $this->add_related_files('grading', 'description', 'bbbext_advgrd_gradingdefinition');
    }

    /**
     * Remap ids that point at rows restored elsewhere in this step:
     *   - metric_map.criterionid  -> the restored rubric/guide criterion (per config gradingmethod).
     *   - grade.gradinginstanceid -> the restored grading instance.
     * Unmappable references are left as-is (criterion) or nulled (instance) rather than left
     * pointing at a foreign activity's rows.
     */
    protected function after_restore_bigbluebuttonbn() {
        global $DB;

        foreach ($this->pendingcriteria as $pending) {
            $config = $DB->get_record('bbbext_advgrd_config', ['id' => $pending->configid]);
            if (!$config) {
                continue;
            }
            $itemname = match ($config->gradingmethod) {
                'rubric' => 'bbbext_advgrd_rubriccriterion',
                'guide'  => 'bbbext_advgrd_guidecriterion',
                default  => null,
            };
            if ($itemname === null) {
                continue;
            }
            $newcriterionid = $this->get_mappingid($itemname, $pending->oldcriterionid);
            if ($newcriterionid) {
                $DB->set_field('bbbext_advgrd_metric_map', 'criterionid', $newcriterionid, ['id' => $pending->id]);
            }
        }

        foreach ($this->pendinginstances as $pending) {
            $newinstanceid = $this->get_mappingid('bbbext_advgrd_gradinginstance', $pending->oldinstanceid);
            if ($newinstanceid) {
                $DB->set_field('bbbext_advgrd_grade', 'gradinginstanceid', $newinstanceid, ['id' => $pending->id]);
            }
        }
    }
}
