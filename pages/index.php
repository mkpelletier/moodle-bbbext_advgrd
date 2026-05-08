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
 * Per-activity grading list: one row per enrolled student with engagement metrics and a Grade link.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$bbbext_advgrd_pathsource = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
$bbbext_advgrd_parts = explode('/', $bbbext_advgrd_pathsource);
array_splice($bbbext_advgrd_parts, -6);
require(implode('/', $bbbext_advgrd_parts) . '/config.php');

use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;
use mod_bigbluebuttonbn\instance;

$bbbid = required_param('id', PARAM_INT);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];
$config = $info['config'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:grade', $context);

$pageurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/index.php', ['id' => $bbbid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($bbb->name) . ': ' . get_string('grading_list_title', 'bbbext_advgrd'));
$PAGE->set_heading($COURSE->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($bbb->name) . ': ' . get_string('grading_list_title', 'bbbext_advgrd'));

if (!$config || $config->gradingmethod === 'none') {
    echo $OUTPUT->notification(get_string('grading_list_no_method', 'bbbext_advgrd'), 'info');
    echo $OUTPUT->footer();
    return;
}

$manager = grader::get_grading_manager($bbbid);
$controller = $manager->get_controller($config->gradingmethod);
if (!$controller->is_form_defined()) {
    echo $OUTPUT->notification(get_string('grading_list_no_definition', 'bbbext_advgrd'), 'info');
    $cfgurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/configure.php', ['id' => $bbbid]);
    echo html_writer::link($cfgurl, get_string('configure_link', 'bbbext_advgrd'), ['class' => 'btn btn-primary']);
    echo $OUTPUT->footer();
    return;
}

$bbbinstance = instance::get_from_instanceid($bbbid);
$users = get_enrolled_users($context, 'mod/bigbluebuttonbn:view', 0,
    'u.id, u.firstname, u.lastname, u.email', 'u.lastname, u.firstname');

$grades = $DB->get_records_menu('bbbext_advgrd_grade',
    ['configid' => $config->id], '', 'userid, finalscore');

$table = new html_table();
$table->head = [
    get_string('column_user', 'bbbext_advgrd'),
    get_string('metric_duration', 'bbbext_advgrd'),
    get_string('metric_talks', 'bbbext_advgrd'),
    get_string('metric_chats', 'bbbext_advgrd'),
    get_string('metric_raisehand', 'bbbext_advgrd'),
    get_string('metric_polls', 'bbbext_advgrd'),
    get_string('metric_emojis', 'bbbext_advgrd'),
    get_string('column_grade', 'bbbext_advgrd'),
    '',
];
$table->attributes['class'] = 'generaltable';

foreach ($users as $u) {
    $m = metrics::for_user($bbbinstance, (int) $u->id);
    $gradeurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/grade.php',
        ['id' => $bbbid, 'userid' => $u->id]);
    $finalscore = $grades[$u->id] ?? null;
    $table->data[] = [
        fullname($u),
        format_time($m[metrics::METRIC_DURATION]),
        format_time($m[metrics::METRIC_TALKS]),
        $m[metrics::METRIC_CHATS],
        $m[metrics::METRIC_RAISEHAND],
        $m[metrics::METRIC_POLLS],
        $m[metrics::METRIC_EMOJIS],
        $finalscore !== null ? format_float($finalscore, 2) : '—',
        html_writer::link($gradeurl, get_string('grade_user_action', 'bbbext_advgrd'),
            ['class' => 'btn btn-sm btn-primary']),
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('grading_list_no_users', 'bbbext_advgrd'), 'info');
} else {
    echo html_writer::table($table);
}

$viewurl = new moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $cm->id]);
$cfgurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/configure.php', ['id' => $bbbid]);
echo html_writer::start_tag('div', ['class' => 'mt-3']);
echo html_writer::link($cfgurl, get_string('configure_link', 'bbbext_advgrd'), ['class' => 'btn btn-link']);
echo html_writer::link($viewurl, get_string('configure_back_to_activity', 'bbbext_advgrd'), ['class' => 'btn btn-link']);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
