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
 * Metric mappings for the BBB advanced grading rubric / marking guide.
 *
 * The rubric definition itself is edited in core Moodle's standard advanced-grading editor
 * (linked from the activity's secondary navigation, matching mod_assign's pattern). This page
 * is purely about the bbbext_advgrd-specific concern of mapping rubric criteria to BigBlueButton
 * engagement signals.
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
require_once($CFG->dirroot . '/grade/grading/lib.php');

use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;

$bbbid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];
$config = $info['config'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:manage', $context);

$pageurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/configure.php', ['id' => $bbbid]);
$templatesurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/templates.php', ['id' => $bbbid]);
$gradeurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/index.php', ['id' => $bbbid]);
$viewurl = new moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $cm->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($bbb->name) . ': ' . get_string('mappings_title', 'bbbext_advgrd'));
$PAGE->set_heading($COURSE->fullname);

if (!$config || $config->gradingmethod === 'none') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('mappings_title', 'bbbext_advgrd'));
    echo $OUTPUT->notification(get_string('configure_no_method', 'bbbext_advgrd'), 'info');
    $editurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    echo html_writer::link(
        $editurl,
        get_string('configure_open_modedit', 'bbbext_advgrd'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->footer();
    return;
}

$manager = grader::get_grading_manager($bbbid);
$controller = $manager->get_controller($config->gradingmethod);

if (!$controller->is_form_defined()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('mappings_title', 'bbbext_advgrd'));
    echo $OUTPUT->notification(get_string('mappings_no_definition', 'bbbext_advgrd'), 'info');
    echo html_writer::link(
        $templatesurl,
        get_string('templates_title', 'bbbext_advgrd'),
        ['class' => 'btn btn-primary mr-2']
    );
    $editurl = $manager->get_management_url($pageurl);
    echo html_writer::link(
        $editurl,
        get_string('configure_edit_definition', 'bbbext_advgrd'),
        ['class' => 'btn btn-secondary', 'target' => '_blank']
    );
    echo $OUTPUT->footer();
    return;
}

if ($action === 'savemappings' && confirm_sesskey()) {
    $rows = [];
    $submitted = data_submitted();
    $rawmapping = isset($submitted->mapping) && is_array($submitted->mapping) ? $submitted->mapping : [];
    foreach ($rawmapping as $criterionid => $row) {
        $thresholds = [];
        $rawthresholds = $row['thresholds'] ?? '';
        if (is_string($rawthresholds) && trim($rawthresholds) !== '') {
            $decoded = json_decode($rawthresholds, true);
            if (is_array($decoded)) {
                $thresholds = $decoded;
            }
        }
        $rows[] = [
            'criterionid' => (int) $criterionid,
            'metric'      => clean_param($row['metric'] ?? 'none', PARAM_ALPHA),
            'thresholds'  => $thresholds,
            'weight'      => (float) ($row['weight'] ?? 1.0),
        ];
    }
    grader::save_metric_mappings((int) $config->id, $rows);
    redirect(
        $pageurl,
        get_string('configure_mappings_saved', 'bbbext_advgrd'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($bbb->name) . ': ' . get_string('mappings_title', 'bbbext_advgrd'));
echo html_writer::tag('p', get_string('mappings_intro', 'bbbext_advgrd', (object) [
    'definition' => format_string($controller->get_definition()->name),
]));
echo html_writer::tag('p', get_string('configure_mappings_help', 'bbbext_advgrd'));

$criteriatable = $config->gradingmethod === 'rubric'
    ? 'gradingform_rubric_criteria' : 'gradingform_guide_criteria';
$labelfield = $config->gradingmethod === 'rubric' ? 'description' : 'shortname';
$criteria = $DB->get_records($criteriatable, ['definitionid' => $controller->get_definition()->id], 'sortorder');
$mappingrows = [];
foreach ($DB->get_records('bbbext_advgrd_metric_map', ['configid' => $config->id]) as $row) {
    $mappingrows[(int) $row->criterionid] = $row;
}

$metricoptions = [
    'none' => get_string('metric_none', 'bbbext_advgrd'),
    metrics::METRIC_DURATION  => get_string('metric_duration', 'bbbext_advgrd'),
    metrics::METRIC_TALKS     => get_string('metric_talks', 'bbbext_advgrd'),
    metrics::METRIC_CHATS     => get_string('metric_chats', 'bbbext_advgrd'),
    metrics::METRIC_RAISEHAND => get_string('metric_raisehand', 'bbbext_advgrd'),
    metrics::METRIC_POLLS     => get_string('metric_polls', 'bbbext_advgrd'),
    metrics::METRIC_EMOJIS    => get_string('metric_emojis', 'bbbext_advgrd'),
    metrics::METRIC_COMPOSITE => get_string('metric_composite', 'bbbext_advgrd'),
];

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'savemappings']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $bbbid]);

$table = new html_table();
$table->head = [
    get_string('column_criterion', 'bbbext_advgrd'),
    get_string('column_metric', 'bbbext_advgrd'),
    get_string('column_thresholds', 'bbbext_advgrd'),
    get_string('column_weight', 'bbbext_advgrd'),
];
$table->attributes['class'] = 'generaltable';

foreach ($criteria as $crit) {
    $existing = $mappingrows[(int) $crit->id] ?? null;
    $namefield = function (string $field) use ($crit): string {
        return 'mapping[' . $crit->id . '][' . $field . ']';
    };
    $thresholdsval = $existing ? ($existing->thresholds ?: '') : '';
    $table->data[] = [
        format_string($crit->{$labelfield}),
        html_writer::select(
            $metricoptions,
            $namefield('metric'),
            $existing ? $existing->metric : 'none',
            false
        ),
        html_writer::tag(
            'textarea',
            s($thresholdsval),
            ['name' => $namefield('thresholds'), 'rows' => 2, 'cols' => 30,
            'placeholder' => '{"3":4,"2":2,"1":1,"0":0}']
        ),
        html_writer::empty_tag(
            'input',
            ['type' => 'number', 'step' => '0.1', 'min' => '0',
             'name' => $namefield('weight'),
             'value' => $existing ? (float) $existing->weight : 1.0,
            'style' => 'width:5em;']
        ),
    ];
}
echo html_writer::table($table);
echo html_writer::tag(
    'div',
    html_writer::empty_tag(
        'input',
        ['type' => 'submit', 'value' => get_string('savemappings', 'bbbext_advgrd'),
        'class' => 'btn btn-primary']
    ),
    ['class' => 'mt-3']
);
echo html_writer::end_tag('form');

echo html_writer::start_tag('div', ['class' => 'mt-4']);
echo html_writer::link(
    $gradeurl,
    get_string('configure_open_grading', 'bbbext_advgrd'),
    ['class' => 'btn btn-link']
);
echo html_writer::link(
    $viewurl,
    get_string('configure_back_to_activity', 'bbbext_advgrd'),
    ['class' => 'btn btn-link']
);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
