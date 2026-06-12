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
 * Single-user grading page: rubric grading + engagement-evidence pane + recording-annotation pane,
 * presented as three Bootstrap tabs. The annotate tab body is lazy-fetched from annotate_ajax.php
 * on first activation.
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

// Compute evidence data once so we can drop it in the Evidence pane below.
$bbbinstance = instance::get_from_instanceid($bbbid);
$usermetrics = metrics::for_user($bbbinstance, $userid);
$suggestions = grader::suggest_levels($bbbid, $userid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grade_user_heading', 'bbbext_advgrd', fullname($user)));

// Render the tab nav. We hand-roll the Bootstrap markup rather than using $OUTPUT->tabtree()
// because tabtree builds full-reload links — we need client-side tab activation with deep-link
// fragments so the rubric form state survives a tab switch.
$tabsnav = <<<HTML
<ul class="nav nav-tabs mb-3" id="advgrd-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="advgrd-tab-rubric" data-bs-toggle="tab"
                data-bs-target="#advgrd-pane-rubric" type="button" role="tab"
                aria-controls="advgrd-pane-rubric" aria-selected="true">
HTML;
$tabsnav .= s(get_string('tab_rubric', 'bbbext_advgrd'));
$tabsnav .= <<<HTML
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="advgrd-tab-annotate" data-bs-toggle="tab"
                data-bs-target="#advgrd-pane-annotate" type="button" role="tab"
                aria-controls="advgrd-pane-annotate" aria-selected="false">
HTML;
$tabsnav .= s(get_string('tab_annotate', 'bbbext_advgrd'));
$tabsnav .= <<<HTML
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="advgrd-tab-evidence" data-bs-toggle="tab"
                data-bs-target="#advgrd-pane-evidence" type="button" role="tab"
                aria-controls="advgrd-pane-evidence" aria-selected="false">
HTML;
$tabsnav .= s(get_string('tab_evidence', 'bbbext_advgrd'));
$tabsnav .= '</button></li></ul>';
echo $tabsnav;

echo html_writer::start_tag('div', ['class' => 'tab-content', 'id' => 'advgrd-tab-content']);

// Rubric tab.
echo html_writer::start_tag('div', [
    'class'           => 'tab-pane fade show active',
    'id'              => 'advgrd-pane-rubric',
    'role'            => 'tabpanel',
    'aria-labelledby' => 'advgrd-tab-rubric',
]);
$form->display();
echo html_writer::end_tag('div');

// Annotate tab - lazy-loaded after first activation.
echo html_writer::start_tag('div', [
    'class'           => 'tab-pane fade',
    'id'              => 'advgrd-pane-annotate',
    'role'            => 'tabpanel',
    'aria-labelledby' => 'advgrd-tab-annotate',
    'data-state'      => 'pending',
]);
echo html_writer::tag(
    'div',
    get_string('annotate_loading', 'bbbext_advgrd'),
    ['class' => 'text-muted', 'data-region' => 'annotate-placeholder']
);
echo html_writer::end_tag('div');

// Evidence tab.
echo html_writer::start_tag('div', [
    'class'           => 'tab-pane fade',
    'id'              => 'advgrd-pane-evidence',
    'role'            => 'tabpanel',
    'aria-labelledby' => 'advgrd-tab-evidence',
]);
echo html_writer::start_tag('div', ['class' => 'card']);
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
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');

$annotateurl = (new moodle_url(
    '/mod/bigbluebuttonbn/extension/advgrd/pages/annotate_ajax.php',
    ['id' => $bbbid, 'userid' => $userid]
))->out(false);
$failedmsg = get_string('annotate_failed', 'bbbext_advgrd');
$invalidmsg = get_string('annotate_post_invalid', 'bbbext_advgrd');
$confirmdelete = get_string('annotate_delete', 'bbbext_advgrd');
$seekfailedmsg = get_string('annotate_seek_failed', 'bbbext_advgrd');
$playernamsg = get_string('annotate_no_playback', 'bbbext_advgrd');

$jsbbbid = (int) $bbbid;
$jsuserid = (int) $userid;
$jsannotateurl = json_encode($annotateurl);
$jsfailed = json_encode($failedmsg);
$jsinvalid = json_encode($invalidmsg);
$jsconfirm = json_encode($confirmdelete);
$jsseekfailed = json_encode($seekfailedmsg);
$jsplayerna = json_encode($playernamsg);

$PAGE->requires->js_amd_inline(<<<JS
require(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    var BBBID = {$jsbbbid};
    var USERID = {$jsuserid};
    var ANNOTATE_URL = {$jsannotateurl};
    var FAILED_MSG = {$jsfailed};
    var INVALID_MSG = {$jsinvalid};
    var CONFIRM_DELETE = {$jsconfirm};
    var SEEK_FAILED_MSG = {$jsseekfailed};
    var PLAYER_NA_MSG = {$jsplayerna};
    var currentRecordingId = '';
    var loaded = false;
    var ownPlayer = false;

    function parseTimestamp(value) {
        var match = String(value || '').trim().match(/^(\d{1,3}):([0-5]\d)$/);
        if (!match) {
            return null;
        }
        return (parseInt(match[1], 10) * 60 + parseInt(match[2], 10)) * 1000;
    }

    function formatTimestamp(ms) {
        var total = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
    }

    function loadAnnotate(recordingid) {
        var pane = document.getElementById('advgrd-pane-annotate');
        if (!pane) {
            return;
        }
        pane.dataset.state = 'loading';
        var url = ANNOTATE_URL + (recordingid ? '&recordingid=' + encodeURIComponent(recordingid) : '');
        fetch(url, {credentials: 'same-origin'}).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function(html) {
            pane.innerHTML = html;
            pane.dataset.state = 'ready';
            var editor = pane.querySelector('[data-region="comment-editor"]');
            if (editor) {
                currentRecordingId = editor.dataset.recordingid || '';
            }
            loaded = true;
            initPlayer();
        }).catch(function(err) {
            pane.dataset.state = 'error';
            pane.innerHTML = '<div class="alert alert-danger">' + FAILED_MSG + '</div>';
            window.console && window.console.warn('advgrd annotate load failed', err);
        });
    }

    function initPlayer() {
        var region = document.querySelector('[data-region="player"]');
        if (!region || !currentRecordingId) {
            return;
        }
        var fallback = region.dataset.fallbackUrl || '';
        ownPlayer = false;
        Ajax.call([{
            methodname: 'bbbext_advgrd_probe_recording',
            args: {bbbid: BBBID, recordingid: currentRecordingId, refresh: false}
        }])[0].then(function(result) {
            if (result.status === 'ok' && result.mediaurl) {
                renderVideo(region, result.mediaurl);
                ownPlayer = true;
            } else if (fallback) {
                renderIframe(region, fallback);
            } else {
                region.innerHTML = '<div class="alert alert-warning">' + PLAYER_NA_MSG + '</div>';
            }
        }).catch(function() {
            if (fallback) {
                renderIframe(region, fallback);
            } else {
                region.innerHTML = '<div class="alert alert-warning">' + PLAYER_NA_MSG + '</div>';
            }
        });
    }

    function renderVideo(region, src) {
        region.innerHTML = '';
        var v = document.createElement('video');
        v.src = src;
        v.controls = true;
        v.preload = 'metadata';
        v.className = 'w-100 border rounded';
        v.style.maxHeight = '480px';
        v.dataset.role = 'advgrd-video';
        region.appendChild(v);
    }

    function renderIframe(region, src) {
        region.innerHTML = '';
        var f = document.createElement('iframe');
        f.src = src;
        f.width = '100%';
        f.height = '480';
        f.setAttribute('allowfullscreen', 'allowfullscreen');
        f.className = 'border rounded';
        region.appendChild(f);
    }

    function getOwnVideo() {
        return document.querySelector('[data-role="advgrd-video"]');
    }

    function grabCurrentTime() {
        var v = getOwnVideo();
        if (!v || isNaN(v.currentTime)) {
            Notification.alert('', SEEK_FAILED_MSG);
            return;
        }
        var tsEl = document.querySelector('[data-region="timestamp-input"]');
        if (tsEl) {
            tsEl.value = formatTimestamp(Math.floor(v.currentTime * 1000));
        }
    }

    function seekTo(ms) {
        var v = getOwnVideo();
        if (!v) {
            Notification.alert('', SEEK_FAILED_MSG);
            return;
        }
        v.currentTime = ms / 1000;
        v.play().catch(function() { /* autoplay may be blocked; harmless. */ });
    }

    function refreshList() {
        var pane = document.getElementById('advgrd-pane-annotate');
        if (!pane || !currentRecordingId) {
            return;
        }
        Ajax.call([{
            methodname: 'bbbext_advgrd_list_annotations',
            args: {bbbid: BBBID, recordingid: currentRecordingId, targetuserid: USERID}
        }])[0].then(function(rows) {
            renderList(rows);
        }).catch(Notification.exception);
    }

    function renderList(rows) {
        var list = document.querySelector('[data-region="comment-items"]');
        var empty = document.querySelector('[data-region="empty-state"]');
        if (!list) {
            return;
        }
        list.innerHTML = '';
        if (!rows.length) {
            if (!empty) {
                var p = document.createElement('p');
                p.className = 'text-muted';
                p.dataset.region = 'empty-state';
                p.textContent = '';
                list.parentNode.insertBefore(p, list);
            }
            return;
        }
        if (empty) {
            empty.remove();
        }
        rows.forEach(function(row) {
            var li = document.createElement('li');
            li.className = 'advgrd-comment advgrd-cat-' + row.commenttype + ' mb-2 p-2 border rounded';
            li.dataset.id = row.id;
            li.dataset.timestamp = row.timestampms;
            li.dataset.category = row.commenttype;
            var time = document.createElement('span');
            time.className = 'advgrd-time fw-bold me-2';
            time.textContent = formatTimestamp(row.timestampms);
            li.appendChild(time);
            var pill = document.createElement('span');
            pill.className = 'advgrd-category-pill badge me-2';
            pill.textContent = row.commenttype;
            li.appendChild(pill);
            var body = document.createElement('span');
            body.textContent = row.body || '';
            li.appendChild(body);
            if (row.gradername) {
                var author = document.createElement('small');
                author.className = 'text-muted ms-2';
                author.textContent = ' — ' + row.gradername;
                li.appendChild(author);
            }
            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-sm btn-link text-danger float-end';
            del.dataset.action = 'delete-comment';
            del.dataset.id = row.id;
            del.title = CONFIRM_DELETE;
            del.textContent = '×';
            li.appendChild(del);
            list.appendChild(li);
        });
    }

    function postComment() {
        var editor = document.querySelector('[data-region="comment-editor"]');
        if (!editor) {
            return;
        }
        var bodyEl = editor.querySelector('[data-region="body-input"]');
        var tsEl = editor.querySelector('[data-region="timestamp-input"]');
        var catEl = editor.querySelector('[data-region="category-select"]');
        var ms = parseTimestamp(tsEl.value);
        var text = (bodyEl.value || '').trim();
        if (ms === null || !text) {
            Notification.alert('', INVALID_MSG);
            return;
        }
        Ajax.call([{
            methodname: 'bbbext_advgrd_add_annotation',
            args: {
                bbbid: BBBID,
                recordingid: currentRecordingId,
                targetuserid: USERID,
                timestampms: ms,
                body: text,
                commenttype: catEl.value
            }
        }])[0].then(function() {
            bodyEl.value = '';
            tsEl.value = '';
            refreshList();
        }).catch(Notification.exception);
    }

    function deleteComment(id) {
        if (!window.confirm(CONFIRM_DELETE + '?')) {
            return;
        }
        Ajax.call([{
            methodname: 'bbbext_advgrd_delete_annotation',
            args: {id: parseInt(id, 10)}
        }])[0].then(function() {
            refreshList();
        }).catch(Notification.exception);
    }

    // Lazy-load on first activation of the annotate tab.
    var tabBtn = document.getElementById('advgrd-tab-annotate');
    if (tabBtn) {
        tabBtn.addEventListener('shown.bs.tab', function() {
            if (!loaded) {
                loadAnnotate('');
            }
        });
    }

    // Deep-link via #advgrd-pane-annotate fragment.
    if (window.location.hash === '#advgrd-pane-annotate' && tabBtn) {
        tabBtn.click();
    }

    // Event delegation for editor + comment-list interactions.
    document.addEventListener('click', function(ev) {
        var actionTarget = ev.target.closest('[data-action]');
        if (actionTarget) {
            var action = actionTarget.dataset.action;
            if (action === 'post-comment') {
                ev.preventDefault();
                postComment();
                return;
            } else if (action === 'delete-comment') {
                ev.preventDefault();
                ev.stopPropagation();
                deleteComment(actionTarget.dataset.id);
                return;
            } else if (action === 'grab-current-time') {
                ev.preventDefault();
                grabCurrentTime();
                return;
            }
        }
        var commentLi = ev.target.closest('.advgrd-comment');
        if (commentLi && commentLi.dataset.timestamp) {
            seekTo(parseInt(commentLi.dataset.timestamp, 10));
        }
    });

    document.addEventListener('change', function(ev) {
        if (ev.target && ev.target.id === 'advgrd-recording-picker') {
            loadAnnotate(ev.target.value);
        }
    });
});
JS);

echo html_writer::link($listurl, get_string('grade_back_to_list', 'bbbext_advgrd'), ['class' => 'btn btn-link mt-3']);

echo $OUTPUT->footer();
