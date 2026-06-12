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
 * Single-user grading page: rubric form, engagement evidence, and the timestamped
 * recording-annotation overlay (video player + timeline strip + Atto editor + comment list).
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Bootstrap moodle: use SCRIPT_FILENAME instead of __DIR__ so the page works when the plugin
// source is symlinked from outside the moodle tree (a common dev workflow). String ops only -
// any '..' path resolution would traverse the dev symlink and miss config.php.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
$advgrdpathparts = explode('/', $_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
array_splice($advgrdpathparts, -6);
require(implode('/', $advgrdpathparts) . '/config.php');
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

use bbbext_advgrd\form\grade_form;
use bbbext_advgrd\local\annotations;
use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\recording;

require_once($CFG->dirroot . '/repository/lib.php');

$bbbid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$recordingidparam = optional_param('recordingid', '', PARAM_RAW_TRIMMED);

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

$maxgrade = (int) ($bbb->grade > 0 ? $bbb->grade : 100);
$grademenu = make_grades_menu($maxgrade);
$controller->set_grade_range($grademenu, $bbb->grade > 0);

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

// Annotation overlay setup. We resolve the active recording, prepare an Atto editor draft
// area, and preload the existing comment list - all before $OUTPUT->header() so the editor
// can register its head requirements.
$bbbinstance = instance::get_from_instanceid($bbbid);
$recordings = recording::get_recordings_for_instance($bbbinstance);
$activerecording = null;
$activerecordingid = '';
$fallbackurl = '';
$existing = [];
$draftitemid = 0;
$editor = null;

if (!empty($recordings)) {
    foreach ($recordings as $rec) {
        if ($recordingidparam !== '' && $rec->get('recordingid') === $recordingidparam) {
            $activerecording = $rec;
            break;
        }
    }
    if (!$activerecording) {
        $activerecording = reset($recordings);
    }
    $activerecordingid = $activerecording->get('recordingid');

    // Pick the richest fallback playback: presentation > video > first available. Use
    // out(false) explicitly - casting moodle_url to (string) calls out(true) which HTML-
    // escapes &, which html_writer then escapes again, producing &amp;amp; in the iframe
    // src; the server parses "amp;bn" as the key and the iframe loads the site frontpage.
    $bytype = [];
    foreach ($activerecording->get('playbacks') ?? [] as $pb) {
        if (!isset($pb['type'], $pb['url']) || empty($pb['url'])) {
            continue;
        }
        $u = $pb['url'];
        $bytype[$pb['type']] = $u instanceof moodle_url ? $u->out(false) : (string) $u;
    }
    if (!empty($bytype)) {
        $fallbackurl = $bytype['presentation'] ?? $bytype['video'] ?? reset($bytype);
    }

    $existing = annotations::list_for_review($bbbid, $activerecordingid, $userid);

    // Atto editor wiring with the file picker enabled - audio + image embedding handled by
    // the editor's native recordrtc / filepicker UI, no custom MediaRecorder needed.
    $draftitemid = file_get_unused_draft_itemid();
    $editor = editors_get_preferred_editor(FORMAT_HTML);
    $editor->head_setup();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grade_user_heading', 'bbbext_advgrd', fullname($user)));

// Evidence panel.
$usermetrics = metrics::for_user($bbbinstance, $userid);
$suggestions = grader::suggest_levels($bbbid, $userid);

echo html_writer::start_tag('div', ['class' => 'card mb-3']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
$evidenceheading = get_string('evidence_heading', 'bbbext_advgrd');
echo html_writer::tag('h3', $evidenceheading, ['class' => 'card-title']);

$evidencetable = new html_table();
$evidencetable->head = [
    get_string('column_metric', 'bbbext_advgrd'),
    get_string('column_value', 'bbbext_advgrd'),
];
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
    $help = get_string('suggested_levels_help', 'bbbext_advgrd');
    echo html_writer::tag('p', $help, ['class' => 'text-muted small']);
}
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// Rubric form.
$form->display();

// Annotation overlay.
if ($activerecording) {
    echo html_writer::start_tag('div', ['class' => 'card mt-4 advgrd-annotate']);
    echo html_writer::start_tag('div', ['class' => 'card-body']);
    $heading = get_string('annotate_heading', 'bbbext_advgrd');
    echo html_writer::tag('h3', $heading, ['class' => 'card-title']);

    // Recording picker (only when multiple recordings exist).
    if (count($recordings) > 1) {
        $options = [];
        foreach ($recordings as $rec) {
            $rid = $rec->get('recordingid');
            $when = $rec->get('starttime')
                ? userdate((int) ($rec->get('starttime') / 1000), '%d %b %Y, %H:%M')
                : '';
            $name = $rec->get('name') ?: $rid;
            $options[$rid] = trim($name . ' — ' . $when, ' —');
        }
        $pickerwrap = html_writer::start_tag('div', ['class' => 'mb-3 d-flex align-items-center']);
        $pickerlabel = get_string('annotate_recording_picker', 'bbbext_advgrd');
        $pickerwrap .= html_writer::tag('label', $pickerlabel, [
            'for'   => 'advgrd-recording-picker',
            'class' => 'me-2 fw-bold',
        ]);
        $pickerwrap .= html_writer::select(
            $options,
            'recordingid',
            $activerecordingid,
            false,
            [
                'id'          => 'advgrd-recording-picker',
                'data-region' => 'recording-picker',
                'class'       => 'form-select w-auto',
            ]
        );
        $pickerwrap .= html_writer::end_tag('div');
        echo $pickerwrap;
    }

    // Player region (16:9 wrapper). The inline JS calls probe_recording to decide between
    // own-player <video> (click-to-seek) and BBB iframe (read-only). Both renderers replace
    // this element's contents.
    echo html_writer::start_tag('div', [
        'class'             => 'ratio ratio-16x9 border rounded mb-2',
        'data-region'       => 'player',
        'data-bbbid'        => $bbbid,
        'data-recordingid'  => $activerecordingid,
        'data-fallback-url' => $fallbackurl,
    ]);
    $loading = get_string('annotate_loading', 'bbbext_advgrd');
    echo html_writer::div($loading, 'd-flex align-items-center justify-content-center text-muted');
    echo html_writer::end_tag('div');

    // Timeline strip + playhead. Markers render after probe + duration are known.
    echo html_writer::start_tag('div', [
        'class'       => 'advgrd-timeline-container',
        'data-region' => 'timeline',
    ]);
    echo html_writer::div('', 'advgrd-timeline-bar', ['data-region' => 'timeline-bar']);
    echo html_writer::div('', 'advgrd-timeline-playhead', ['data-region' => 'timeline-playhead']);
    echo html_writer::end_tag('div');

    // Current-time readout (matches ytsubmission's display).
    echo html_writer::start_tag('div', ['class' => 'mb-3 d-flex align-items-center gap-2']);
    $currentlabel = get_string('annotate_currenttime', 'bbbext_advgrd');
    echo html_writer::tag('strong', $currentlabel . ': ');
    echo html_writer::tag('span', '00:00', [
        'data-region' => 'current-time',
        'class'       => 'advgrd-time-display',
    ]);
    echo html_writer::end_tag('div');

    // Comment form.
    echo html_writer::start_tag('div', ['class' => 'advgrd-comment-form mb-3']);

    // Timestamp (mm:ss text input + Use current time button).
    echo html_writer::start_tag('div', ['class' => 'row g-2 align-items-end mb-2']);
    echo html_writer::start_tag('div', ['class' => 'col-md-3']);
    $tslabel = get_string('annotate_timestamp', 'bbbext_advgrd');
    echo html_writer::tag('label', $tslabel, ['for' => 'advgrd-timestamp', 'class' => 'form-label']);
    echo html_writer::start_tag('div', ['class' => 'input-group']);
    echo html_writer::empty_tag('input', [
        'id'          => 'advgrd-timestamp',
        'type'        => 'text',
        'class'       => 'form-control',
        'placeholder' => 'mm:ss',
        'pattern'     => '^[0-9]{1,3}:[0-5][0-9]$',
        'value'       => '0:00',
        'data-region' => 'timestamp-input',
    ]);
    $usenowlabel = get_string('annotate_use_current_time', 'bbbext_advgrd');
    echo html_writer::tag('button', '⏱', [
        'type'        => 'button',
        'class'       => 'btn btn-outline-secondary',
        'data-action' => 'grab-current-time',
        'title'       => $usenowlabel,
    ]);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // Category dropdown.
    echo html_writer::start_tag('div', ['class' => 'col-md-3']);
    $catlabel = get_string('annotate_category', 'bbbext_advgrd');
    echo html_writer::tag('label', $catlabel, ['for' => 'advgrd-category', 'class' => 'form-label']);
    $catoptions = [];
    foreach (annotations::CATEGORIES as $cat) {
        $catoptions[$cat] = get_string('annotate_category_' . $cat, 'bbbext_advgrd');
    }
    echo html_writer::select($catoptions, 'commenttype', 'general', false, [
        'id'    => 'advgrd-category',
        'class' => 'form-select',
    ]);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // Editor textarea + file picker hookup. Atto/TinyMCE wraps this with the toolbar
    // (including audio recording when site config allows).
    $bodylabel = get_string('annotate_body', 'bbbext_advgrd');
    echo html_writer::tag('label', $bodylabel, ['for' => 'advgrd-body', 'class' => 'form-label']);
    echo html_writer::tag('textarea', '', [
        'id'    => 'advgrd-body',
        'name'  => 'advgrd-body',
        'class' => 'form-control',
        'rows'  => 5,
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'id'    => 'advgrd-draftitemid',
        'value' => $draftitemid,
    ]);

    // Initialise the preferred editor + file picker (media, image) so audio recording shows.
    if ($editor) {
        $editoroptions = annotations::editor_options($context);
        $fpoptions = [];

        $imgargs = new stdClass();
        $imgargs->accepted_types = ['image'];
        $imgargs->return_types = FILE_INTERNAL | FILE_EXTERNAL;
        $imgargs->context = $context;
        $imgargs->env = 'editor';
        $imgpicker = initialise_filepicker($imgargs);
        $imgpicker->itemid = $draftitemid;
        $fpoptions['image'] = $imgpicker;

        $mediaargs = new stdClass();
        $mediaargs->accepted_types = ['video', 'audio'];
        $mediaargs->return_types = FILE_INTERNAL | FILE_EXTERNAL;
        $mediaargs->context = $context;
        $mediaargs->env = 'editor';
        $mediapicker = initialise_filepicker($mediaargs);
        $mediapicker->itemid = $draftitemid;
        $fpoptions['media'] = $mediapicker;

        $editor->use_editor('advgrd-body', $editoroptions, $fpoptions);
    }

    // Submit row.
    echo html_writer::start_tag('div', ['class' => 'mt-2']);
    $addlabel = get_string('annotate_add', 'bbbext_advgrd');
    echo html_writer::tag('button', $addlabel, [
        'type'        => 'button',
        'class'       => 'btn btn-primary',
        'data-action' => 'add-comment',
    ]);
    echo html_writer::end_tag('div');

    echo html_writer::end_tag('div');

    // Existing-comments list - rendered server-side here; refreshed client-side by JS.
    $existingheading = get_string('annotate_existing', 'bbbext_advgrd');
    echo html_writer::tag('h4', $existingheading, ['class' => 'mt-3']);
    echo html_writer::start_tag('div', ['data-region' => 'comment-list']);
    if (empty($existing)) {
        $noneyet = get_string('annotate_no_comments', 'bbbext_advgrd');
        echo html_writer::tag('p', $noneyet, [
            'class'       => 'text-muted',
            'data-region' => 'empty-state',
        ]);
    }
    foreach ($existing as $row) {
        echo bbbext_advgrd_render_comment_item($row, $context);
    }
    echo html_writer::end_tag('div');

    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');

    // Inline JS for player init, timeline rendering, and comment AJAX.
    $jsbbbid = (int) $bbbid;
    $jsuserid = (int) $userid;
    $jsrecordingid = json_encode($activerecordingid);
    $jsfallback = json_encode($fallbackurl);
    $jsstrings = json_encode([
        'iframenotice'  => get_string('annotate_iframe_notice', 'bbbext_advgrd'),
        'unavailable'   => get_string('annotate_video_unavailable', 'bbbext_advgrd'),
        'seekunsup'     => get_string('annotate_seek_unsupported', 'bbbext_advgrd'),
        'savefailed'    => get_string('annotate_save_failed', 'bbbext_advgrd'),
        'deleteconfirm' => get_string('annotate_delete_confirm', 'bbbext_advgrd'),
        'noplayback'    => get_string('annotate_no_playback', 'bbbext_advgrd'),
        'youlabel'      => get_string('annotate_comment_for', 'bbbext_advgrd', fullname($user)),
    ]);
    $jscategories = json_encode(annotations::CATEGORIES);

    $PAGE->requires->js_amd_inline(<<<JS
require(['core/ajax', 'core/notification', 'core/templates'], function(Ajax, Notification) {
    var BBBID = {$jsbbbid};
    var USERID = {$jsuserid};
    var RECORDINGID = {$jsrecordingid};
    var FALLBACK_URL = {$jsfallback};
    var STR = {$jsstrings};
    var CATEGORIES = {$jscategories};

    var videoEl = null;
    var videoDuration = 0;
    var ownPlayer = false;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function formatTimestamp(ms) {
        var total = Math.max(0, Math.floor(ms / 1000));
        return pad(Math.floor(total / 60)) + ':' + pad(total % 60);
    }
    function parseTimestamp(value) {
        var m = String(value || '').trim().match(/^(\d{1,3}):([0-5]\d)$/);
        if (!m) return null;
        return (parseInt(m[1], 10) * 60 + parseInt(m[2], 10)) * 1000;
    }

    function init() {
        Ajax.call([{
            methodname: 'bbbext_advgrd_probe_recording',
            args: {bbbid: BBBID, recordingid: RECORDINGID, refresh: false}
        }])[0].then(function(result) {
            if (result.status === 'ok' && result.mediaurl) {
                mountVideo(result.mediaurl, result.durationms);
            } else if (FALLBACK_URL) {
                mountIframe(FALLBACK_URL);
            } else {
                showUnavailable();
            }
        }).catch(function() {
            if (FALLBACK_URL) { mountIframe(FALLBACK_URL); } else { showUnavailable(); }
        });

        wireEvents();
        renderTimelineFromDom();
    }

    function mountVideo(src, durationms) {
        var region = document.querySelector('[data-region="player"]');
        if (!region) return;
        region.innerHTML = '';
        var v = document.createElement('video');
        v.src = src;
        v.controls = true;
        v.preload = 'metadata';
        v.className = 'w-100 h-100';
        v.setAttribute('controlsList', 'nodownload');
        region.appendChild(v);
        videoEl = v;
        ownPlayer = true;
        if (durationms > 0) {
            videoDuration = durationms / 1000;
            renderTimelineFromDom();
        }
        v.addEventListener('loadedmetadata', function() {
            if (!videoDuration || isNaN(videoDuration)) {
                videoDuration = v.duration;
                renderTimelineFromDom();
            }
        });
        v.addEventListener('timeupdate', updatePlayhead);
    }

    function mountIframe(src) {
        var region = document.querySelector('[data-region="player"]');
        if (!region) return;
        region.innerHTML = '';
        var f = document.createElement('iframe');
        f.src = src;
        f.className = 'w-100 h-100 border-0';
        f.setAttribute('allow', 'autoplay; fullscreen; encrypted-media');
        f.setAttribute('allowfullscreen', 'true');
        region.appendChild(f);
        ownPlayer = false;
        var notice = document.createElement('div');
        notice.className = 'alert alert-info small mt-2';
        notice.textContent = STR.iframenotice;
        region.parentNode.insertBefore(notice, region.nextSibling);
    }

    function showUnavailable() {
        var region = document.querySelector('[data-region="player"]');
        if (!region) return;
        region.innerHTML = '<div class="alert alert-warning m-3">' + STR.unavailable + '</div>';
    }

    function updatePlayhead() {
        if (!videoEl) return;
        var t = videoEl.currentTime || 0;
        var display = document.querySelector('[data-region="current-time"]');
        if (display) display.textContent = formatTimestamp(Math.floor(t * 1000));
        var playhead = document.querySelector('[data-region="timeline-playhead"]');
        if (playhead && videoDuration > 0) {
            playhead.style.left = Math.min(100, (t / videoDuration) * 100) + '%';
        }
    }

    function renderTimelineFromDom() {
        var bar = document.querySelector('[data-region="timeline-bar"]');
        if (!bar || videoDuration <= 0) return;
        var existingMarkers = bar.querySelectorAll('.advgrd-timeline-marker');
        existingMarkers.forEach(function(m) { m.remove(); });
        var items = document.querySelectorAll('[data-region="comment-list"] [data-region="comment-item"]');
        items.forEach(function(item) {
            addMarker(
                parseInt(item.dataset.id, 10),
                parseInt(item.dataset.timestamp, 10),
                item.dataset.category || 'general'
            );
        });
    }

    function addMarker(id, timestampms, category) {
        var bar = document.querySelector('[data-region="timeline-bar"]');
        if (!bar || videoDuration <= 0) return;
        var existing = bar.querySelector('[data-marker-id="' + id + '"]');
        if (existing) existing.remove();
        var ratio = (timestampms / 1000) / videoDuration;
        var percent = Math.min(100, Math.max(0, ratio * 100));
        var marker = document.createElement('div');
        marker.className = 'advgrd-timeline-marker advgrd-cat-' + category;
        marker.style.left = percent + '%';
        marker.dataset.markerId = id;
        marker.dataset.timestampms = timestampms;
        marker.addEventListener('click', function(ev) {
            ev.stopPropagation();
            seekTo(timestampms);
        });
        bar.appendChild(marker);
    }

    function seekTo(ms) {
        if (ownPlayer && videoEl) {
            videoEl.currentTime = ms / 1000;
            var p = videoEl.play();
            if (p && typeof p.catch === 'function') p.catch(function() { /* autoplay block */ });
        } else {
            Notification.alert('', STR.seekunsup);
        }
    }

    function getEditorContent() {
        if (window.tinymce) {
            var t = window.tinymce.get('advgrd-body');
            if (t) return t.getContent();
        }
        var ta = document.getElementById('advgrd-body');
        return ta ? ta.value : '';
    }

    function clearEditor() {
        if (window.tinymce) {
            var t = window.tinymce.get('advgrd-body');
            if (t) { t.setContent(''); return; }
        }
        var ta = document.getElementById('advgrd-body');
        if (ta) ta.value = '';
    }

    function addComment() {
        var bodyhtml = (getEditorContent() || '').trim();
        var plain = bodyhtml.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '').trim();
        var hasMedia = /<(audio|video|img|source)\b/i.test(bodyhtml);
        if (!plain && !hasMedia) {
            Notification.alert('', STR.savefailed);
            return;
        }
        var tsEl = document.getElementById('advgrd-timestamp');
        var ms = parseTimestamp(tsEl.value);
        if (ms === null) ms = 0;
        var category = document.getElementById('advgrd-category').value;
        if (CATEGORIES.indexOf(category) === -1) category = 'general';
        var draftitemid = parseInt(document.getElementById('advgrd-draftitemid').value, 10) || 0;

        Ajax.call([{
            methodname: 'bbbext_advgrd_add_annotation',
            args: {
                bbbid: BBBID,
                recordingid: RECORDINGID,
                targetuserid: USERID,
                timestampms: ms,
                body: bodyhtml,
                bodyformat: 1,
                commenttype: category,
                draftitemid: draftitemid
            }
        }])[0].then(function() {
            clearEditor();
            tsEl.value = '0:00';
            refreshList();
        }).catch(function() {
            Notification.alert('', STR.savefailed);
        });
    }

    function deleteComment(id) {
        if (!window.confirm(STR.deleteconfirm)) return;
        Ajax.call([{
            methodname: 'bbbext_advgrd_delete_annotation',
            args: {id: parseInt(id, 10)}
        }])[0].then(function() {
            refreshList();
        }).catch(Notification.exception);
    }

    function refreshList() {
        Ajax.call([{
            methodname: 'bbbext_advgrd_list_annotations',
            args: {bbbid: BBBID, recordingid: RECORDINGID, targetuserid: USERID}
        }])[0].then(function(rows) {
            renderList(rows);
        }).catch(Notification.exception);
    }

    function renderList(rows) {
        var list = document.querySelector('[data-region="comment-list"]');
        if (!list) return;
        list.innerHTML = '';
        if (!rows.length) {
            var p = document.createElement('p');
            p.className = 'text-muted';
            p.dataset.region = 'empty-state';
            p.textContent = '';
            list.appendChild(p);
            renderTimelineFromDom();
            return;
        }
        rows.forEach(function(row) { list.appendChild(buildCommentItem(row)); });
        renderTimelineFromDom();
    }

    function buildCommentItem(row) {
        var card = document.createElement('div');
        card.className = 'advgrd-comment-item card mb-2 advgrd-cat-' + row.commenttype;
        card.dataset.region = 'comment-item';
        card.dataset.id = row.id;
        card.dataset.timestamp = row.timestampms;
        card.dataset.category = row.commenttype;
        var body = document.createElement('div');
        body.className = 'card-body';
        var meta = document.createElement('div');
        meta.className = 'd-flex justify-content-between align-items-start mb-2 flex-wrap gap-2';
        var left = document.createElement('div');
        var seek = document.createElement('a');
        seek.href = '#';
        seek.className = 'advgrd-timestamp-link badge text-white me-1';
        seek.dataset.action = 'seek';
        seek.dataset.timestamp = row.timestampms;
        seek.textContent = formatTimestamp(row.timestampms);
        var pill = document.createElement('span');
        pill.className = 'badge me-2 advgrd-badge advgrd-cat-' + row.commenttype;
        pill.textContent = row.commenttype;
        var author = document.createElement('small');
        author.className = 'text-muted';
        author.textContent = row.gradername || '';
        left.appendChild(seek);
        left.appendChild(pill);
        left.appendChild(author);
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-link text-danger';
        del.dataset.action = 'delete-comment';
        del.dataset.id = row.id;
        del.textContent = '×';
        meta.appendChild(left);
        meta.appendChild(del);
        var content = document.createElement('div');
        content.className = 'mt-2';
        content.innerHTML = row.body;
        body.appendChild(meta);
        body.appendChild(content);
        card.appendChild(body);
        return card;
    }

    function wireEvents() {
        document.addEventListener('click', function(ev) {
            var t = ev.target.closest('[data-action]');
            if (t) {
                var action = t.dataset.action;
                if (action === 'grab-current-time') {
                    ev.preventDefault();
                    var ms = videoEl ? Math.floor((videoEl.currentTime || 0) * 1000) : 0;
                    document.getElementById('advgrd-timestamp').value = formatTimestamp(ms);
                } else if (action === 'add-comment') {
                    ev.preventDefault();
                    addComment();
                } else if (action === 'delete-comment') {
                    ev.preventDefault();
                    deleteComment(t.dataset.id);
                } else if (action === 'seek') {
                    ev.preventDefault();
                    seekTo(parseInt(t.dataset.timestamp, 10));
                }
            }
        });

        document.addEventListener('change', function(ev) {
            if (ev.target && ev.target.id === 'advgrd-recording-picker') {
                var url = new URL(window.location.href);
                url.searchParams.set('recordingid', ev.target.value);
                window.location.href = url.toString();
            }
        });

        var bar = document.querySelector('[data-region="timeline-bar"]');
        if (bar) {
            bar.addEventListener('click', function(ev) {
                if (ev.target.classList.contains('advgrd-timeline-marker')) return;
                if (!ownPlayer || videoDuration <= 0) {
                    Notification.alert('', STR.seekunsup);
                    return;
                }
                var rect = bar.getBoundingClientRect();
                var ratio = (ev.clientX - rect.left) / rect.width;
                seekTo(Math.floor(ratio * videoDuration * 1000));
            });
        }
    }

    init();
});
JS);
}

echo html_writer::link(
    $listurl,
    get_string('grade_back_to_list', 'bbbext_advgrd'),
    ['class' => 'btn btn-link']
);

echo $OUTPUT->footer();

/**
 * Render one comment item for the existing-comments list. Mirrored in JS for client-side
 * inserts (after add / delete) - keep the two in sync.
 *
 * @param \stdClass        $row
 * @param \context_module  $context
 * @return string
 */
function bbbext_advgrd_render_comment_item(\stdClass $row, \context_module $context): string {
    global $DB;
    $author = $row->graderid
        ? $DB->get_record('user', ['id' => $row->graderid], 'id, firstname, lastname')
        : null;
    $rendered = file_rewrite_pluginfile_urls(
        $row->body,
        'pluginfile.php',
        $context->id,
        'bbbext_advgrd',
        annotations::FILEAREA,
        $row->id
    );
    $cleaned = format_text($rendered, (int) $row->bodyformat, [
        'context' => $context,
        'noclean' => false,
    ]);
    $time = sprintf('%d:%02d', floor($row->timestampms / 60000), floor(($row->timestampms / 1000) % 60));

    $out = html_writer::start_tag('div', [
        'class'         => 'advgrd-comment-item card mb-2 advgrd-cat-' . $row->commenttype,
        'data-region'   => 'comment-item',
        'data-id'       => $row->id,
        'data-timestamp' => $row->timestampms,
        'data-category' => $row->commenttype,
    ]);
    $out .= html_writer::start_tag('div', ['class' => 'card-body']);
    $out .= html_writer::start_tag('div', [
        'class' => 'd-flex justify-content-between align-items-start mb-2 flex-wrap gap-2',
    ]);
    $left = html_writer::start_tag('div');
    $left .= html_writer::link('#', $time, [
        'class'         => 'advgrd-timestamp-link badge text-white me-1',
        'data-action'   => 'seek',
        'data-timestamp' => $row->timestampms,
    ]);
    $left .= html_writer::tag('span', $row->commenttype, [
        'class' => 'badge me-2 advgrd-badge advgrd-cat-' . $row->commenttype,
    ]);
    if ($author) {
        $left .= html_writer::tag('small', fullname($author), ['class' => 'text-muted']);
    }
    $left .= html_writer::end_tag('div');
    $del = html_writer::tag('button', '×', [
        'type'        => 'button',
        'class'       => 'btn btn-sm btn-link text-danger',
        'data-action' => 'delete-comment',
        'data-id'     => $row->id,
    ]);
    $out .= $left . $del;
    $out .= html_writer::end_tag('div');
    $out .= html_writer::tag('div', $cleaned, ['class' => 'mt-2']);
    $out .= html_writer::end_tag('div');
    $out .= html_writer::end_tag('div');
    return $out;
}
