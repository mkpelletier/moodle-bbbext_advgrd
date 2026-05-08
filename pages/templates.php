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
 * Starter-template picker for the BBB advanced grading rubric / marking guide.
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
use bbbext_advgrd\local\templates\registry;

$bbbid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$templateid = optional_param('template', '', PARAM_ALPHANUMEXT);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];
$config = $info['config'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:manage', $context);

$pageurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/templates.php', ['id' => $bbbid]);
$mappingsurl = new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/configure.php', ['id' => $bbbid]);
$activityurl = new moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $cm->id]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(format_string($bbb->name) . ': ' . get_string('templates_title', 'bbbext_advgrd'));
$PAGE->set_heading($COURSE->fullname);

if (!$config || $config->gradingmethod === 'none') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('templates_title', 'bbbext_advgrd'));
    echo $OUTPUT->notification(get_string('configure_no_method', 'bbbext_advgrd'), 'info');
    $editurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    echo html_writer::link($editurl, get_string('configure_open_modedit', 'bbbext_advgrd'),
        ['class' => 'btn btn-primary']);
    echo $OUTPUT->footer();
    return;
}

$manager = grader::get_grading_manager($bbbid);
$controller = $manager->get_controller($config->gradingmethod);

// Handle import action.
if ($action === 'import' && $templateid !== '' && confirm_sesskey()) {
    if ($controller->is_form_defined()) {
        redirect($pageurl,
            get_string('error_definition_exists', 'bbbext_advgrd'),
            null, \core\output\notification::NOTIFY_ERROR);
    }
    grader::import_template($bbbid, $templateid);
    redirect($mappingsurl,
        get_string('templates_imported', 'bbbext_advgrd'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($bbb->name) . ': ' . get_string('templates_title', 'bbbext_advgrd'));

if ($controller->is_form_defined()) {
    echo $OUTPUT->notification(get_string('templates_already_defined', 'bbbext_advgrd'), 'warning');
    $editurl = $manager->get_management_url($pageurl);
    echo html_writer::link($editurl, get_string('configure_edit_definition', 'bbbext_advgrd'),
        ['class' => 'btn btn-secondary', 'target' => '_blank']);
    echo $OUTPUT->footer();
    return;
}

echo html_writer::tag('p', get_string('templates_intro', 'bbbext_advgrd'));

echo html_writer::start_tag('div', ['class' => 'row']);
foreach (registry::all() as $tplclass) {
    $importurl = new moodle_url($pageurl, [
        'action'   => 'import',
        'template' => $tplclass::id(),
        'sesskey'  => sesskey(),
    ]);
    $card = html_writer::start_tag('div', ['class' => 'card mb-3']);
    $card .= html_writer::start_tag('div', ['class' => 'card-body']);
    $card .= html_writer::tag('h3', format_string($tplclass::name()), ['class' => 'card-title h5']);
    $card .= html_writer::tag('p', $tplclass::description(), ['class' => 'card-text']);
    $citation = $tplclass::citation();
    if ($citation !== '') {
        $card .= html_writer::tag('p', $citation, ['class' => 'card-text text-muted small']);
    }
    $card .= html_writer::link($importurl,
        get_string('templates_use_button', 'bbbext_advgrd'),
        ['class' => 'btn btn-primary']);
    $card .= html_writer::end_tag('div');
    $card .= html_writer::end_tag('div');
    echo html_writer::tag('div', $card, ['class' => 'col-md-4']);
}
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class' => 'mt-3']);
echo html_writer::link($activityurl, get_string('configure_back_to_activity', 'bbbext_advgrd'),
    ['class' => 'btn btn-link']);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
