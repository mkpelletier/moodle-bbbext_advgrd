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
 * Renders the body of the Annotate tab on grade.php — recording picker, player container,
 * comment editor, and the timeline of existing annotations for the active student.
 *
 * Fetched as an HTML fragment by amd/grade_page.js when the tab is first activated. Returns
 * HTML directly (not wrapped in a Moodle page chrome). Auth + capability checks happen here.
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

use bbbext_advgrd\local\annotations;
use bbbext_advgrd\local\grader;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\recording;

$bbbid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$recordingid = optional_param('recordingid', '', PARAM_RAW_TRIMMED);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:grade', $context);

$bbbinstance = instance::get_from_instanceid($bbbid);
$recordings = recording::get_recordings_for_instance($bbbinstance);

if (empty($recordings)) {
    echo html_writer::div(
        get_string('annotate_no_recordings', 'bbbext_advgrd'),
        'alert alert-info'
    );
    return;
}

// Resolve the active recording: explicit param > first recording.
$active = null;
foreach ($recordings as $rec) {
    if ($recordingid !== '' && $rec->get('recordingid') === $recordingid) {
        $active = $rec;
        break;
    }
}
if (!$active) {
    $active = reset($recordings);
    $recordingid = $active->get('recordingid');
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Recording picker (only shown when there's more than one).
if (count($recordings) > 1) {
    $options = [];
    foreach ($recordings as $rec) {
        $rid = $rec->get('recordingid');
        $when = $rec->get('starttime') ? userdate((int) ($rec->get('starttime') / 1000), '%d %b %Y, %H:%M') : '';
        $name = $rec->get('name') ?: $rid;
        $options[$rid] = trim($name . ' — ' . $when, ' —');
    }
    echo html_writer::start_tag('div', ['class' => 'mb-3 d-flex align-items-center']);
    echo html_writer::tag(
        'label',
        get_string('annotate_recording_picker', 'bbbext_advgrd'),
        ['for' => 'advgrd-recording-picker', 'class' => 'me-2 fw-bold']
    );
    echo html_writer::select(
        $options,
        'recordingid',
        $recordingid,
        false,
        ['id' => 'advgrd-recording-picker', 'data-region' => 'recording-picker', 'class' => 'form-select w-auto']
    );
    echo html_writer::end_tag('div');
}

// Player container. The inline JS calls probe_recording to decide between own-player (HTML5
// <video> with click-to-seek) and iframe-fallback. We pass the iframe-fallback URL as a data
// attribute so the JS doesn't need a second round-trip when the probe says 'iframe' or 'failed'.
$fallbackurl = '';
foreach ($active->get('playbacks') ?? [] as $playback) {
    if (!empty($playback['url'])) {
        $fallbackurl = (string) $playback['url'];
        break;
    }
}
echo html_writer::start_tag('div', [
    'data-region'    => 'player',
    'class'          => 'mb-3',
    'data-bbbid'     => $bbbid,
    'data-recordingid' => $recordingid,
    'data-fallback-url' => $fallbackurl,
]);
echo html_writer::div(
    get_string('annotate_loading', 'bbbext_advgrd'),
    'text-muted',
    ['data-region' => 'player-placeholder']
);
echo html_writer::end_tag('div');

// Comment editor: category dropdown, text body, manual timestamp (mm:ss), Post button.
echo html_writer::start_tag('div', [
    'class'           => 'card mb-3',
    'data-region'     => 'comment-editor',
    'data-bbbid'      => $bbbid,
    'data-recordingid' => $recordingid,
    'data-targetuserid' => $userid,
]);
echo html_writer::start_tag('div', ['class' => 'card-body']);
$heading = get_string('annotate_post_heading', 'bbbext_advgrd', fullname($user));
echo html_writer::tag('h5', $heading, ['class' => 'card-title']);

echo html_writer::start_tag('div', ['class' => 'row g-2']);

// Timestamp input.
echo html_writer::start_tag('div', ['class' => 'col-md-2']);
$timestamplabel = get_string('annotate_timestamp', 'bbbext_advgrd');
echo html_writer::tag('label', $timestamplabel, ['for' => 'advgrd-comment-time', 'class' => 'form-label']);
echo html_writer::start_tag('div', ['class' => 'input-group']);
echo html_writer::empty_tag('input', [
    'id'          => 'advgrd-comment-time',
    'type'        => 'text',
    'class'       => 'form-control',
    'placeholder' => 'mm:ss',
    'data-region' => 'timestamp-input',
    'pattern'     => '^[0-9]{1,3}:[0-5][0-9]$',
]);
echo html_writer::tag('button', '⏱', [
    'type'        => 'button',
    'class'       => 'btn btn-outline-secondary',
    'data-action' => 'grab-current-time',
    'title'       => get_string('annotate_use_current_time', 'bbbext_advgrd'),
]);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Category dropdown.
echo html_writer::start_tag('div', ['class' => 'col-md-3']);
$categorylabel = get_string('annotate_category', 'bbbext_advgrd');
echo html_writer::tag('label', $categorylabel, ['for' => 'advgrd-comment-category', 'class' => 'form-label']);
$catoptions = [];
foreach (annotations::CATEGORIES as $cat) {
    $catoptions[$cat] = get_string('annotation_category_' . $cat, 'bbbext_advgrd');
}
echo html_writer::select($catoptions, 'commenttype', 'general', false, [
    'id' => 'advgrd-comment-category',
    'data-region' => 'category-select',
    'class' => 'form-select',
]);
echo html_writer::end_tag('div');

// Body textarea.
echo html_writer::start_tag('div', ['class' => 'col-12']);
$bodylabel = get_string('annotate_body', 'bbbext_advgrd');
echo html_writer::tag('label', $bodylabel, ['for' => 'advgrd-comment-body', 'class' => 'form-label']);
echo html_writer::tag('textarea', '', [
    'id'          => 'advgrd-comment-body',
    'class'       => 'form-control',
    'rows'        => 3,
    'data-region' => 'body-input',
    'maxlength'   => 4000,
]);
echo html_writer::end_tag('div');

// Post button.
echo html_writer::start_tag('div', ['class' => 'col-12']);
echo html_writer::tag('button', get_string('annotate_post', 'bbbext_advgrd'), [
    'type'        => 'button',
    'class'       => 'btn btn-primary',
    'data-action' => 'post-comment',
]);
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Comment list - populated server-side now and refreshed client-side after posts.
$existing = annotations::list_for_review($bbbid, $recordingid, $userid);
echo html_writer::start_tag('div', ['data-region' => 'comment-list']);
$commentsheading = get_string('annotate_comments_heading', 'bbbext_advgrd');
echo html_writer::tag('h5', $commentsheading, ['class' => 'mb-2']);
if (empty($existing)) {
    $nocomments = get_string('annotate_no_comments', 'bbbext_advgrd');
    echo html_writer::tag('p', $nocomments, ['class' => 'text-muted', 'data-region' => 'empty-state']);
}
echo html_writer::start_tag('ul', ['class' => 'list-unstyled', 'data-region' => 'comment-items']);
foreach ($existing as $annot) {
    $author = $DB->get_record('user', ['id' => $annot->graderid], '*', IGNORE_MISSING);
    $time = sprintf('%d:%02d', floor($annot->timestampms / 60000), floor(($annot->timestampms / 1000) % 60));
    echo html_writer::start_tag('li', [
        'class'         => 'advgrd-comment advgrd-cat-' . $annot->commenttype . ' mb-2 p-2 border rounded',
        'data-id'       => $annot->id,
        'data-timestamp' => $annot->timestampms,
        'data-category' => $annot->commenttype,
    ]);
    echo html_writer::tag('span', s($time), ['class' => 'advgrd-time fw-bold me-2']);
    $catlabel = get_string('annotation_category_' . $annot->commenttype, 'bbbext_advgrd');
    echo html_writer::tag('span', $catlabel, ['class' => 'advgrd-category-pill badge me-2']);
    if ($annot->kind === 'audio') {
        $audiolabel = get_string('annotation_kind_audio', 'bbbext_advgrd');
        echo html_writer::tag('em', $audiolabel, ['class' => 'me-2']);
    }
    echo html_writer::tag('span', s($annot->body ?? ''));
    if ($author) {
        echo html_writer::tag('small', ' — ' . fullname($author), ['class' => 'text-muted ms-2']);
    }
    echo html_writer::tag('button', '×', [
        'type'        => 'button',
        'class'       => 'btn btn-sm btn-link text-danger float-end',
        'data-action' => 'delete-comment',
        'data-id'     => $annot->id,
        'title'       => get_string('annotate_delete', 'bbbext_advgrd'),
    ]);
    echo html_writer::end_tag('li');
}
echo html_writer::end_tag('ul');
echo html_writer::end_tag('div');
