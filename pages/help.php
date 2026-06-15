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
 * Reference documentation for the BBB Advanced Grading extension. Rendered as a single
 * accordion so teachers can scan the 8 standard topics quickly. Reached from the BBB
 * activity's secondary navigation under "Help".
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
$advgrdpathparts = explode('/', $_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
array_splice($advgrdpathparts, -6);
require(implode('/', $advgrdpathparts) . '/config.php');
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

$bbbid = required_param('id', PARAM_INT);

$bbb = $DB->get_record('bigbluebuttonbn', ['id' => $bbbid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('bigbluebuttonbn', $bbbid, $bbb->course, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($bbb->course, false, $cm);

// Anyone with any of the plugin's caps may read the docs - graders / managers and the
// students who can view their own report all benefit from understanding how the
// participation grading model works.
if (
    !has_capability('bbbext/advgrd:manage', $context)
    && !has_capability('bbbext/advgrd:grade', $context)
    && !has_capability('bbbext/advgrd:viewownreport', $context)
) {
    throw new required_capability_exception($context, 'bbbext/advgrd:viewownreport', 'nopermissions', '');
}

$pageurl = new moodle_url(
    '/mod/bigbluebuttonbn/extension/advgrd/pages/help.php',
    ['id' => $bbbid]
);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_cm($cm);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('help_title', 'bbbext_advgrd'));
$PAGE->set_heading($COURSE->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('help_title', 'bbbext_advgrd'));

// Table of contents - lets teachers jump straight to the section they're after.
$sections = [
    'grading-interface'   => get_string('help_h_grading_interface', 'bbbext_advgrd'),
    'configuration'       => get_string('help_h_configuration', 'bbbext_advgrd'),
    'templates'           => get_string('help_h_templates', 'bbbext_advgrd'),
    'metric-mapping'      => get_string('help_h_metric_mapping', 'bbbext_advgrd'),
    'participation'       => get_string('help_h_participation', 'bbbext_advgrd'),
    'groups'              => get_string('help_h_groups', 'bbbext_advgrd'),
    'submissions'         => get_string('help_h_submissions', 'bbbext_advgrd'),
    'multiple-sessions'   => get_string('help_h_multiple_sessions', 'bbbext_advgrd'),
];

echo html_writer::start_tag('div', ['class' => 'card mb-4']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h2', get_string('help_toc_heading', 'bbbext_advgrd'), ['class' => 'h5 mb-3']);
echo html_writer::start_tag('ol', ['class' => 'mb-0']);
foreach ($sections as $anchor => $title) {
    echo html_writer::tag('li', html_writer::link('#' . $anchor, $title));
}
echo html_writer::end_tag('ol');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Each section renders the same shape: a card with an anchor target + a content block. We
// keep the actual prose in lang strings so the wording can be translated, and emit the
// markup here so the structure (headings, lists, footnotes) stays consistent.
$sectionrenderer = function (string $anchor, string $title, string $body): void {
    echo html_writer::start_tag('section', ['id' => $anchor, 'class' => 'card mb-4']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    echo html_writer::tag('h2', $title, ['class' => 'card-title h4']);
    echo html_writer::start_tag('div', ['class' => 'advgrd-help-body']);
    echo $body;
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('section');
};

foreach ($sections as $anchor => $title) {
    $bodykey = 'help_b_' . str_replace('-', '_', $anchor);
    $sectionrenderer($anchor, $title, get_string($bodykey, 'bbbext_advgrd'));
}

echo html_writer::tag(
    'p',
    get_string('help_footer_about', 'bbbext_advgrd'),
    ['class' => 'text-muted small mt-4']
);

echo $OUTPUT->footer();
