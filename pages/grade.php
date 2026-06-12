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
 * Single-user grading page: displays an evidence panel and the rubric/guide grading form.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Bootstrap moodle: use SCRIPT_FILENAME instead of __DIR__ so the page works when the plugin
// source is symlinked from outside the moodle tree (a common dev workflow). String ops only —
// any '..' path resolution would traverse the dev symlink and miss config.php.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
$advgrdpathparts = explode('/', $_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
array_splice($advgrdpathparts, -6);
require(implode('/', $advgrdpathparts) . '/config.php');
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

use bbbext_advgrd\form\grade_form;
use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;
use mod_bigbluebuttonbn\instance;

$bbbid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];
$config = $info['config'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:grade', $context);

$listurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/index.php', ['id' => $bbbid]);
$pageurl = new moodle_url(
    '/mod/bigbluebuttonbn/extension/advgrd/pages/grade.php',
    ['id' => $bbbid, 'userid' => $userid]
);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$PAGE->set_title(format_string($bbb->name) . ': ' . fullname($user));
$PAGE->set_heading($COURSE->fullname);

if (!$config || $config->gradingmethod === 'none') {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('grading_list_no_method', 'bbbext_advgrd'), 'info');
    echo $OUTPUT->footer();
    return;
}

$manager = grader::get_grading_manager($bbbid);
$controller = $manager->get_controller($config->gradingmethod);
if (!$controller->is_form_defined()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('grading_list_no_definition', 'bbbext_advgrd'), 'info');
    echo $OUTPUT->footer();
    return;
}

// Set the grading scale to match the BBB activity grade.
$maxgrade = (int) ($bbb->grade > 0 ? $bbb->grade : 100);
$grademenu = make_grades_menu($maxgrade);
$controller->set_grade_range($grademenu, $bbb->grade > 0);

// Grading instance: lookup by raterid+itemid, falling back to a fresh one.
$instanceid = optional_param('advancedgradinginstanceid', 0, PARAM_INT) ?: null;
$gradinginstance = $controller->get_or_create_instance($instanceid, $USER->id, $userid);

$form = new grade_form($pageurl->out(false), [
    'gradinginstance' => $gradinginstance,
    'bbbid'           => $bbbid,
    'userid'          => $userid,
    'returnurl'       => $listurl,
]);

if ($form->is_cancelled()) {
    redirect($listurl);
} else if ($data = $form->get_data()) {
    $rawscore = $gradinginstance->submit_and_get_grade($data->advancedgrading, $userid);
    grader::record_grade(
        $bbbid,
        $userid,
        (int) $USER->id,
        $rawscore !== false ? (float) $rawscore : null,
        (int) $gradinginstance->get_id()
    );
    redirect(
        $listurl,
        get_string('grade_saved', 'bbbext_advgrd', fullname($user)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grade_user_heading', 'bbbext_advgrd', fullname($user)));

// Evidence panel: metrics + suggested levels.
$bbbinstance = instance::get_from_instanceid($bbbid);
$usermetrics = metrics::for_user($bbbinstance, $userid);
$suggestions = grader::suggest_levels($bbbid, $userid);

echo html_writer::start_tag('div', ['class' => 'card mb-3']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h3', get_string('evidence_heading', 'bbbext_advgrd'), ['class' => 'card-title']);

$evidencetable = new html_table();
$evidencetable->head = [get_string('column_metric', 'bbbext_advgrd'), get_string('column_value', 'bbbext_advgrd')];
$evidencetable->attributes['class'] = 'generaltable';
$evidencetable->data = [
    [get_string('metric_duration', 'bbbext_advgrd'), format_time($usermetrics[metrics::METRIC_DURATION])],
    [get_string('metric_talks', 'bbbext_advgrd'), format_time($usermetrics[metrics::METRIC_TALKS])],
    [get_string('metric_chats', 'bbbext_advgrd'), $usermetrics[metrics::METRIC_CHATS]],
    [get_string('metric_raisehand', 'bbbext_advgrd'), $usermetrics[metrics::METRIC_RAISEHAND]],
    [get_string('metric_polls', 'bbbext_advgrd'), $usermetrics[metrics::METRIC_POLLS]],
    [get_string('metric_emojis', 'bbbext_advgrd'), $usermetrics[metrics::METRIC_EMOJIS]],
];
echo html_writer::table($evidencetable);

if (!empty($suggestions)) {
    echo html_writer::tag('h4', get_string('suggested_levels_heading', 'bbbext_advgrd'));
    $criteriatable = $config->gradingmethod === 'rubric'
        ? 'gradingform_rubric_criteria' : 'gradingform_guide_criteria';
    $labelfield = $config->gradingmethod === 'rubric' ? 'description' : 'shortname';
    $crits = $DB->get_records_list($criteriatable, 'id', array_keys($suggestions));
    $list = [];
    foreach ($suggestions as $cid => $score) {
        $name = isset($crits[$cid]) ? format_string($crits[$cid]->{$labelfield}) : ('#' . $cid);
        $list[] = $name . ' — ' . get_string('suggested_level_score', 'bbbext_advgrd', (string) $score);
    }
    echo html_writer::alist($list);
    echo html_writer::tag(
        'p',
        get_string('suggested_levels_help', 'bbbext_advgrd'),
        ['class' => 'text-muted small']
    );
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

$form->display();

echo html_writer::link($listurl, get_string('grade_back_to_list', 'bbbext_advgrd'), ['class' => 'btn btn-link']);

echo $OUTPUT->footer();
