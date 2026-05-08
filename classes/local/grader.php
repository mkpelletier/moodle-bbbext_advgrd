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
 * Orchestration helpers that bridge our config rows to Moodle's grading subsystem.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use bbbext_advgrd\local\templates\registry;
use context_module;
use grading_manager;
use mod_bigbluebuttonbn\instance;
use moodle_exception;
use stdClass;

/**
 * Stateless service that owns the wiring between bbbext_advgrd_* tables and core grading.
 *
 * Every entry point starts from a BBB instance id; methods bootstrap the cm/context/manager
 * lazily so callers don't have to know about Moodle's plumbing.
 */
class grader {
    /**
     * Resolve the BBB activity context, course module, and config row from a BBB instance id.
     *
     * @param int $bbbid
     * @return array{bbb:stdClass, cm:stdClass, context:context_module, config:stdClass|null}
     */
    public static function bootstrap(int $bbbid): array {
        global $DB;

        $bbb = $DB->get_record('bigbluebuttonbn', ['id' => $bbbid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $bbbid, $bbb->course, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $bbbid]);
        return ['bbb' => $bbb, 'cm' => $cm, 'context' => $context, 'config' => $config ?: null];
    }

    /**
     * Build a grading_manager for the BBB participation area, ensuring the activemethod matches config.
     *
     * @param int $bbbid
     * @return grading_manager
     */
    public static function get_grading_manager(int $bbbid): grading_manager {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');

        $info = self::bootstrap($bbbid);
        if (!$info['config'] || $info['config']->gradingmethod === 'none') {
            throw new moodle_exception('error_nogradingmethod', 'bbbext_advgrd');
        }

        $manager = get_grading_manager($info['context'], 'bbbext_advgrd', 'participation');
        if ($manager->get_active_method() !== $info['config']->gradingmethod) {
            $manager->set_active_method($info['config']->gradingmethod);
        }
        return $manager;
    }

    /**
     * Import a starter template's rubric or marking-guide definition for the configured method,
     * and seed metric→criterion mappings.
     *
     * @param int    $bbbid      BBB instance id.
     * @param string $templateid Stable template id (see {@see registry::all()}).
     */
    public static function import_template(int $bbbid, string $templateid): void {
        global $DB;

        $info = self::bootstrap($bbbid);
        $config = $info['config'];
        if (!$config || $config->gradingmethod === 'none') {
            throw new moodle_exception('error_nogradingmethod', 'bbbext_advgrd');
        }

        $manager = self::get_grading_manager($bbbid);
        $controller = $manager->get_controller($config->gradingmethod);

        // Refuse to append on top of an existing definition — the picker UI hides the import
        // button in this case, so reaching here means a stale URL or a programmatic call.
        if ($controller->is_form_defined()) {
            throw new moodle_exception('error_definition_exists', 'bbbext_advgrd');
        }

        $tpl = registry::get($templateid);
        $payload = $config->gradingmethod === 'rubric'
            ? $tpl::rubric_definition()
            : $tpl::guide_definition();

        $obj = (object) $payload['definition'];
        $controller->update_definition($obj);

        // Seed metric mappings. Find the criterion ids by their description (rubric) or shortname (guide).
        $criteriatable = $config->gradingmethod === 'rubric'
            ? 'gradingform_rubric_criteria'
            : 'gradingform_guide_criteria';
        $labelfield = $config->gradingmethod === 'rubric' ? 'description' : 'shortname';

        $crits = $DB->get_records($criteriatable, ['definitionid' => $controller->get_definition()->id]);
        $byname = [];
        foreach ($crits as $c) {
            $byname[$c->{$labelfield}] = (int) $c->id;
        }

        foreach ($payload['mappings'] as $mapping) {
            if (!isset($byname[$mapping['shortname']])) {
                continue;
            }
            $criterionid = $byname[$mapping['shortname']];
            $existing = $DB->get_record('bbbext_advgrd_metric_map', [
                'configid'    => $config->id,
                'criterionid' => $criterionid,
            ]);
            if ($existing) {
                continue; // Don't overwrite teacher customisations on re-import.
            }
            $DB->insert_record('bbbext_advgrd_metric_map', (object) [
                'configid'    => $config->id,
                'criterionid' => $criterionid,
                'metric'      => $mapping['metric'],
                'thresholds'  => json_encode($mapping['thresholds']),
                'weight'      => 1.0,
            ]);
        }
    }

    /**
     * Persist a teacher-edited set of metric mappings, replacing any prior rows for this config.
     *
     * @param int $configid bbbext_advgrd_config.id.
     * @param array $rows Each row is ['criterionid' => int, 'metric' => string, 'thresholds' => array, 'weight' => float].
     */
    public static function save_metric_mappings(int $configid, array $rows): void {
        global $DB;

        $DB->delete_records('bbbext_advgrd_metric_map', ['configid' => $configid]);
        foreach ($rows as $row) {
            if (empty($row['metric']) || $row['metric'] === 'none') {
                continue;
            }
            $DB->insert_record('bbbext_advgrd_metric_map', (object) [
                'configid'    => $configid,
                'criterionid' => (int) $row['criterionid'],
                'metric'      => $row['metric'],
                'thresholds'  => json_encode($row['thresholds'] ?? []),
                'weight'      => (float) ($row['weight'] ?? 1.0),
            ]);
        }
    }

    /**
     * Suggest a level (rubric) or score (guide) for each criterion based on the user's metrics.
     *
     * @param int $bbbid
     * @param int $userid
     * @return array<int, int|float|null> map of criterionid => suggested score (rubric: a level score,
     *                                    guide: the suggested mark, null if no mapping or no threshold matched).
     */
    public static function suggest_levels(int $bbbid, int $userid): array {
        global $DB;

        $info = self::bootstrap($bbbid);
        if (!$info['config']) {
            return [];
        }

        $instance = instance::get_from_instanceid($bbbid);
        $metrics = metrics::for_user($instance, $userid);

        $maps = $DB->get_records('bbbext_advgrd_metric_map', ['configid' => $info['config']->id]);
        $suggestions = [];
        foreach ($maps as $map) {
            if ($map->metric === metrics::METRIC_COMPOSITE) {
                $value = metrics::composite_score($metrics);
            } else {
                $value = $metrics[$map->metric] ?? 0;
            }
            $thresholds = json_decode($map->thresholds, true);
            if (!is_array($thresholds) || empty($thresholds)) {
                continue;
            }
            $suggested = metrics::suggest_level($value, $thresholds);
            if ($suggested !== null) {
                $suggestions[(int) $map->criterionid] = $suggested;
            }
        }
        return $suggestions;
    }

    /**
     * Persist a finalised grade for a user, push it to the BBB gradebook item, and snapshot the evidence.
     *
     * @param int        $bbbid   BBB instance id.
     * @param int        $userid  User being graded.
     * @param int        $graderid The user recording the grade.
     * @param float|null $rawscore Score returned by gradingform_*_instance::submit_and_get_grade(),
     *                            on the rubric/guide's native scale (0..maxscore).
     * @param int|null   $gradinginstanceid grading_instances.id from the form submission.
     */
    public static function record_grade(
        int $bbbid,
        int $userid,
        int $graderid,
        ?float $rawscore,
        ?int $gradinginstanceid
    ): void {
        global $DB, $CFG;

        $info = self::bootstrap($bbbid);
        if (!$info['config']) {
            throw new moodle_exception('error_noconfig', 'bbbext_advgrd');
        }

        $instance = instance::get_from_instanceid($bbbid);
        $evidence = metrics::for_user($instance, $userid);

        // Normalise the rubric/guide raw score to the activity's grade scale (default 100).
        $maxgrade = (float) ($info['bbb']->grade ?: 100);
        $finalscore = self::scale_to_gradebook($rawscore, $bbbid, $info['config']->gradingmethod, $maxgrade);

        $existing = $DB->get_record('bbbext_advgrd_grade', [
            'configid' => $info['config']->id,
            'userid'   => $userid,
        ]);

        $row = (object) [
            'configid'          => $info['config']->id,
            'userid'            => $userid,
            'gradinginstanceid' => $gradinginstanceid,
            'rawscore'          => $rawscore,
            'finalscore'        => $finalscore,
            'evidence'          => json_encode($evidence),
            'graderid'          => $graderid,
            'timegraded'        => time(),
        ];

        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('bbbext_advgrd_grade', $row);
        } else {
            $DB->insert_record('bbbext_advgrd_grade', $row);
        }

        if (!empty($info['config']->passthroughtogradebook)) {
            self::push_to_gradebook($info['bbb'], $userid, $finalscore, $graderid);
            if (
                $info['config']->scoremode === 'analytic'
                    && $info['config']->gradingmethod === 'rubric'
                    && $gradinginstanceid
            ) {
                self::push_analytic_subscores($info['bbb'], $userid, (int) $gradinginstanceid, $graderid);
            }
        }
    }

    /**
     * Compute per-presence sub-scores from a rubric grading instance and push them to the
     * gradebook as additional grade items (itemnumber 1=cognitive, 2=social, 3=teaching).
     *
     * Each sub-score is sum-of-earned ÷ sum-of-max across the criteria belonging to that
     * presence (inferred from the criterion label), then multiplied by the activity's grademax.
     * Criteria whose presence cannot be inferred (custom criteria) are skipped.
     *
     * @param stdClass $bbb
     * @param int $userid
     * @param int $gradinginstanceid
     * @param int $graderid
     */
    protected static function push_analytic_subscores(
        stdClass $bbb,
        int $userid,
        int $gradinginstanceid,
        int $graderid
    ): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        // Pull the per-criterion filling for this grading instance.
        $sql = "SELECT c.id AS critid, c.description, c.definitionid, l.score, lmax.maxscore
                  FROM {gradingform_rubric_fillings} f
                  JOIN {gradingform_rubric_criteria} c ON c.id = f.criterionid
                  JOIN {gradingform_rubric_levels} l ON l.id = f.levelid
                  JOIN (
                       SELECT criterionid, MAX(score) AS maxscore
                         FROM {gradingform_rubric_levels}
                     GROUP BY criterionid
                  ) lmax ON lmax.criterionid = c.id
                 WHERE f.instanceid = :iid";
        $rows = $DB->get_records_sql($sql, ['iid' => $gradinginstanceid]);
        if (!$rows) {
            return;
        }

        $maxgrade = (float) ($bbb->grade ?: 100);

        // Walk every registered template's group definitions and accumulate (earned, max) per group.
        // Groups are inferred from criterion description prefixes; a teacher who renames criteria in
        // the standard rubric editor will see those criteria silently excluded here, which is the
        // intended graceful degradation.
        $bygroup = [];
        $grouplabels = [];
        foreach ($rows as $row) {
            [$groupkey, $grouplabel] = self::resolve_group_for_label($row->description);
            if ($groupkey === null) {
                continue;
            }
            $bygroup[$groupkey] = $bygroup[$groupkey] ?? ['earned' => 0.0, 'max' => 0.0];
            $bygroup[$groupkey]['earned'] += (float) $row->score;
            $bygroup[$groupkey]['max']    += (float) $row->maxscore;
            $grouplabels[$groupkey] = $grouplabel;
        }

        // Stable item-number assignment by sort order of the group keys, starting at 1.
        ksort($bygroup);
        $itemnumber = 1;
        foreach ($bygroup as $key => $sums) {
            if ($sums['max'] <= 0) {
                $itemnumber++;
                continue;
            }
            $score = ($sums['earned'] / $sums['max']) * $maxgrade;
            $itemname = get_string('analytic_grade_itemname', 'bbbext_advgrd', (object) [
                'presence' => $grouplabels[$key],
                'activity' => format_string($bbb->name),
            ]);
            grade_update(
                source: 'mod/bigbluebuttonbn',
                courseid: $bbb->course,
                itemtype: 'mod',
                itemmodule: 'bigbluebuttonbn',
                iteminstance: $bbb->id,
                itemnumber: $itemnumber,
                grades: (object) [
                    'userid'       => $userid,
                    'rawgrade'     => $score,
                    'usermodified' => $graderid,
                ],
                itemdetails: [
                    'itemname' => $itemname,
                    'gradetype' => GRADE_TYPE_VALUE,
                    'grademax' => $maxgrade,
                    'grademin' => 0,
                ]
            );
            $itemnumber++;
        }
    }

    /**
     * Resolve a criterion description to its (group key, group label) by checking every registered
     * template's analytic groupings. First match wins. Returns [null, null] if no template
     * recognises the prefix.
     *
     * @param string $label
     * @return array{0: ?string, 1: ?string}
     */
    protected static function resolve_group_for_label(string $label): array {
        foreach (registry::all() as $tplclass) {
            $key = $tplclass::infer_group_from_label($label);
            if ($key !== null) {
                $groups = $tplclass::analytic_groups();
                return [$key, $groups[$key] ?? $key];
            }
        }
        return [null, null];
    }

    /**
     * Convert a rubric or guide raw score to the BBB activity's gradebook scale.
     *
     * Rubrics report on the rubric's own min..max range; guides report sum-of-criteria over
     * sum-of-maxscores. We rescale either to 0..$maxgrade.
     *
     * @param float|null $rawscore
     * @param int $bbbid
     * @param string $method
     * @param float $maxgrade
     * @return float|null
     */
    protected static function scale_to_gradebook(?float $rawscore, int $bbbid, string $method, float $maxgrade): ?float {
        if ($rawscore === null) {
            return null;
        }
        // The submit_and_get_grade() call already returns a value scaled to set_grade_range(),
        // which the caller will set to 0..$maxgrade. So rawscore is already on the gradebook scale.
        // We clamp defensively.
        return max(0.0, min($maxgrade, $rawscore));
    }

    /**
     * Push the final score to the BBB gradebook item via the standard grade API.
     *
     * @param stdClass $bbb
     * @param int $userid
     * @param float|null $score
     * @param int $graderid
     */
    protected static function push_to_gradebook(stdClass $bbb, int $userid, ?float $score, int $graderid): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/bigbluebuttonbn/lib.php');

        $grade = (object) [
            'userid'    => $userid,
            'rawgrade'  => $score,
            'usermodified' => $graderid,
        ];
        bigbluebuttonbn_grade_item_update($bbb, $grade);
    }
}
