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
 * Reusable renderer for the BBB recording-annotation overlay.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use context_module;
use html_writer;
use html_table;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\recording;
use moodle_url;

/**
 * Builds the player + timeline + comment form + comments-list overlay HTML and queues the
 * driving JS. The same instance backs the standalone grading page and the unifiedgrader
 * preview pane - host plugins call render() and embed the returned HTML wherever they want
 * the overlay to live.
 */
class overlay {
    /** @var string Grader mode renders the editor + library + delete buttons. */
    public const MODE_GRADER = 'grader';

    /** @var string Student mode is read-only: player + timeline + comment list, no authoring. */
    public const MODE_STUDENT = 'student';

    /**
     * Render the overlay.
     *
     * @param int             $bbbid           BBB activity instance id.
     * @param int             $userid          Target student id.
     * @param string|null     $recordingidparam Active recording id (URL param); null picks the first.
     * @param context_module  $context         Activity context.
     * @param \stdClass       $user            Target student record.
     * @param string          $mode            self::MODE_GRADER or self::MODE_STUDENT.
     * @return string The overlay HTML. Empty string when the activity has no recordings.
     */
    public static function render(
        int $bbbid,
        int $userid,
        ?string $recordingidparam,
        context_module $context,
        \stdClass $user,
        string $mode = self::MODE_GRADER
    ): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/repository/lib.php');

        $bbbinstance = instance::get_from_instanceid($bbbid);
        $recordings = recording::get_recordings_for_instance($bbbinstance);
        if (empty($recordings)) {
            return '';
        }

        $activerecording = null;
        if ($recordingidparam !== null && $recordingidparam !== '') {
            foreach ($recordings as $rec) {
                if ($rec->get('recordingid') === $recordingidparam) {
                    $activerecording = $rec;
                    break;
                }
            }
        }
        if (!$activerecording) {
            $activerecording = reset($recordings);
        }
        $activerecordingid = $activerecording->get('recordingid');

        // Pick the richest fallback playback URL. out(false) explicit - casting moodle_url
        // to (string) invokes out(true) which HTML-escapes & and bites us downstream once
        // html_writer escapes the attribute value a second time.
        $bytype = [];
        foreach ($activerecording->get('playbacks') ?? [] as $pb) {
            if (!isset($pb['type'], $pb['url']) || empty($pb['url'])) {
                continue;
            }
            $u = $pb['url'];
            $bytype[$pb['type']] = $u instanceof moodle_url ? $u->out(false) : (string) $u;
        }
        $fallbackurl = '';
        if (!empty($bytype)) {
            $fallbackurl = $bytype['presentation'] ?? $bytype['video'] ?? reset($bytype);
        }

        $existing = annotations::list_for_review($bbbid, $activerecordingid, $userid);
        $isgrader = $mode === self::MODE_GRADER;

        // Editor wiring is only relevant in grader mode - students can't author comments.
        $draftitemid = 0;
        $editor = null;
        if ($isgrader) {
            $draftitemid = file_get_unused_draft_itemid();
            $editor = editors_get_preferred_editor(FORMAT_HTML);
            $editor->head_setup();
        }

        $out = html_writer::start_tag('div', ['class' => 'card mt-4 advgrd-annotate']);
        $out .= html_writer::start_tag('div', ['class' => 'card-body']);
        $heading = get_string('annotate_heading', 'bbbext_advgrd');
        $out .= html_writer::tag('h3', $heading, ['class' => 'card-title']);

        // Recording picker (only when there's more than one).
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
            $out .= html_writer::start_tag('div', ['class' => 'mb-3 d-flex align-items-center']);
            $pickerlabel = get_string('annotate_recording_picker', 'bbbext_advgrd');
            $out .= html_writer::tag('label', $pickerlabel, [
                'for'   => 'advgrd-recording-picker',
                'class' => 'me-2 fw-bold',
            ]);
            $out .= html_writer::select(
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
            $out .= html_writer::end_tag('div');
        }

        // Player wrapper (position:relative so the media-comment callout can overlay it).
        $out .= html_writer::start_tag('div', ['class' => 'advgrd-player-wrapper position-relative mb-2']);
        $out .= html_writer::start_tag('div', [
            'class'             => 'ratio ratio-16x9',
            'data-region'       => 'player',
            'data-bbbid'        => $bbbid,
            'data-recordingid'  => $activerecordingid,
            'data-fallback-url' => $fallbackurl,
        ]);
        $loading = get_string('annotate_loading', 'bbbext_advgrd');
        $out .= html_writer::div($loading, 'd-flex align-items-center justify-content-center text-muted');
        $out .= html_writer::end_tag('div');
        $out .= html_writer::start_tag('div', [
            'class'       => 'advgrd-comment-callout d-none',
            'data-region' => 'comment-callout',
        ]);
        $out .= html_writer::end_tag('div');
        $out .= html_writer::end_tag('div');

        // Timeline strip + playhead.
        $out .= html_writer::start_tag('div', [
            'class'       => 'advgrd-timeline-container',
            'data-region' => 'timeline',
        ]);
        $out .= html_writer::div('', 'advgrd-timeline-bar', ['data-region' => 'timeline-bar']);
        $out .= html_writer::div('', 'advgrd-timeline-playhead', ['data-region' => 'timeline-playhead']);
        $out .= html_writer::end_tag('div');

        // Current-time readout.
        $out .= html_writer::start_tag('div', ['class' => 'mb-3 d-flex align-items-center gap-2']);
        $currentlabel = get_string('annotate_currenttime', 'bbbext_advgrd');
        $out .= html_writer::tag('strong', $currentlabel . ': ');
        $out .= html_writer::tag('span', '00:00', [
            'data-region' => 'current-time',
            'class'       => 'advgrd-time-display',
        ]);
        $out .= html_writer::end_tag('div');

        if ($isgrader) {
            // Comment form (with the same surface treatment as ytsubmission).
            $out .= html_writer::start_tag('div', ['class' => 'advgrd-comment-form mb-3']);

            // Timestamp.
            $out .= html_writer::start_tag('div', ['class' => 'row g-2 align-items-end mb-2']);
            $out .= html_writer::start_tag('div', ['class' => 'col-md-3']);
            $tslabel = get_string('annotate_timestamp', 'bbbext_advgrd');
            $out .= html_writer::tag('label', $tslabel, ['for' => 'advgrd-timestamp', 'class' => 'form-label']);
            $out .= html_writer::start_tag('div', ['class' => 'input-group']);
            $out .= html_writer::empty_tag('input', [
                'id'          => 'advgrd-timestamp',
                'type'        => 'text',
                'class'       => 'form-control',
                'placeholder' => 'mm:ss',
                'pattern'     => '^[0-9]{1,3}:[0-5][0-9]$',
                'value'       => '0:00',
                'data-region' => 'timestamp-input',
            ]);
            $usenowlabel = get_string('annotate_use_current_time', 'bbbext_advgrd');
            $out .= html_writer::tag('button', '⏱', [
                'type'        => 'button',
                'class'       => 'btn btn-outline-secondary',
                'data-action' => 'grab-current-time',
                'title'       => $usenowlabel,
            ]);
            $out .= html_writer::end_tag('div');
            $out .= html_writer::end_tag('div');

            // Category dropdown.
            $out .= html_writer::start_tag('div', ['class' => 'col-md-3']);
            $catlabel = get_string('annotate_category', 'bbbext_advgrd');
            $out .= html_writer::tag('label', $catlabel, ['for' => 'advgrd-category', 'class' => 'form-label']);
            $catoptions = [];
            foreach (annotations::CATEGORIES as $cat) {
                $catoptions[$cat] = get_string('annotate_category_' . $cat, 'bbbext_advgrd');
            }
            $out .= html_writer::select($catoptions, 'commenttype', 'general', false, [
                'id'    => 'advgrd-category',
                'class' => 'form-select',
            ]);
            $out .= html_writer::end_tag('div');
            $out .= html_writer::end_tag('div');

            // Editor.
            $bodylabel = get_string('annotate_body', 'bbbext_advgrd');
            $out .= html_writer::tag('label', $bodylabel, ['for' => 'advgrd-body', 'class' => 'form-label']);
            $out .= html_writer::tag('textarea', '', [
                'id'    => 'advgrd-body',
                'name'  => 'advgrd-body',
                'class' => 'form-control',
                'rows'  => 10,
            ]);
            $out .= html_writer::empty_tag('input', [
                'type'  => 'hidden',
                'id'    => 'advgrd-draftitemid',
                'value' => $draftitemid,
            ]);

            $editoroptions = annotations::editor_options($context);
            $fpoptions = [];
            $imgargs = new \stdClass();
            $imgargs->accepted_types = ['image'];
            $imgargs->return_types = FILE_INTERNAL | FILE_EXTERNAL;
            $imgargs->context = $context;
            $imgargs->env = 'editor';
            $imgpicker = initialise_filepicker($imgargs);
            $imgpicker->itemid = $draftitemid;
            $fpoptions['image'] = $imgpicker;
            $mediaargs = new \stdClass();
            $mediaargs->accepted_types = ['video', 'audio'];
            $mediaargs->return_types = FILE_INTERNAL | FILE_EXTERNAL;
            $mediaargs->context = $context;
            $mediaargs->env = 'editor';
            $mediapicker = initialise_filepicker($mediaargs);
            $mediapicker->itemid = $draftitemid;
            $fpoptions['media'] = $mediapicker;
            $editor->use_editor('advgrd-body', $editoroptions, $fpoptions);

            // Action row.
            $out .= html_writer::start_tag('div', ['class' => 'mt-2 d-flex flex-wrap gap-2']);
            $addlabel = get_string('annotate_add', 'bbbext_advgrd');
            $out .= html_writer::tag('button', $addlabel, [
                'type'        => 'button',
                'class'       => 'btn btn-primary',
                'data-action' => 'add-comment',
            ]);
            $insertlibicon = html_writer::tag('i', '', ['class' => 'fa fa-book me-1']);
            $insertliblabel = get_string('annotate_library_insert', 'bbbext_advgrd');
            $out .= html_writer::tag('button', $insertlibicon . $insertliblabel, [
                'type'        => 'button',
                'class'       => 'btn btn-outline-secondary',
                'data-action' => 'library-open',
            ]);
            $savelibicon = html_writer::tag('i', '', ['class' => 'fa fa-save me-1']);
            $saveliblabel = get_string('annotate_library_save', 'bbbext_advgrd');
            $out .= html_writer::tag('button', $savelibicon . $saveliblabel, [
                'type'        => 'button',
                'class'       => 'btn btn-outline-secondary',
                'data-action' => 'library-save',
            ]);
            $out .= html_writer::end_tag('div');

            // Library panel slot.
            $out .= html_writer::start_tag('div', [
                'class'       => 'advgrd-library-panel mt-2 d-none',
                'data-region' => 'library-panel',
            ]);
            $out .= html_writer::end_tag('div');

            $out .= html_writer::end_tag('div');
        }

        // Existing-comments list.
        $existingheading = get_string('annotate_existing', 'bbbext_advgrd');
        $out .= html_writer::tag('h4', $existingheading, ['class' => 'mt-3']);
        $out .= html_writer::start_tag('div', ['data-region' => 'comment-list']);
        if (empty($existing)) {
            $noneyet = get_string('annotate_no_comments', 'bbbext_advgrd');
            $out .= html_writer::tag('p', $noneyet, [
                'class'       => 'text-muted',
                'data-region' => 'empty-state',
            ]);
        }
        foreach ($existing as $row) {
            $out .= self::render_comment_item($row, $context, $isgrader);
        }
        $out .= html_writer::end_tag('div');

        $out .= html_writer::end_tag('div');
        $out .= html_writer::end_tag('div');

        // Queue the inline JS via $PAGE.
        self::queue_inline_js($bbbid, $userid, $activerecordingid, $fallbackurl, $user);

        return $out;
    }

    /**
     * Render one comment item server-side. Mirrored in JS for client-side inserts after add /
     * delete - keep the two in sync.
     *
     * @param \stdClass       $row
     * @param context_module  $context
     * @param bool            $isgrader Render the delete button only when the viewer can author.
     * @return string
     */
    public static function render_comment_item(\stdClass $row, context_module $context, bool $isgrader = true): string {
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

        $catlabel = get_string('annotate_category_' . $row->commenttype, 'bbbext_advgrd');
        $confirm = get_string('annotate_delete_confirm', 'bbbext_advgrd');

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
        $left = html_writer::start_tag('div', [
            'class' => 'flex-grow-1 d-flex flex-wrap align-items-center gap-2',
        ]);
        $clockicon = html_writer::tag('i', '', ['class' => 'fa fa-clock-o']);
        $left .= html_writer::link('#', $clockicon . ' ' . $time, [
            'class'          => 'advgrd-timestamp-link badge text-white',
            'data-action'    => 'seek',
            'data-timestamp' => $row->timestampms,
        ]);
        $left .= html_writer::tag('span', $catlabel, [
            'class' => 'badge advgrd-badge advgrd-cat-' . $row->commenttype,
        ]);
        if ($author) {
            $left .= html_writer::tag('small', fullname($author), ['class' => 'text-muted']);
        }
        $left .= html_writer::end_tag('div');
        $del = '';
        if ($isgrader) {
            $trashicon = html_writer::tag('i', '', ['class' => 'fa fa-trash']);
            $del = html_writer::tag('button', $trashicon, [
                'type'        => 'button',
                'class'       => 'btn btn-sm btn-link text-danger',
                'data-action' => 'delete-comment',
                'data-id'     => $row->id,
                'title'       => $confirm,
            ]);
        }
        $out .= $left . $del;
        $out .= html_writer::end_tag('div');
        $out .= html_writer::tag('div', $cleaned, ['class' => 'mt-2 advgrd-comment-body']);
        $out .= html_writer::end_tag('div');
        $out .= html_writer::end_tag('div');
        return $out;
    }

    /**
     * Queue the inline JS that drives the overlay. Bound by data-region selectors that are
     * scoped to one overlay instance per page - rendering the overlay twice on the same page
     * will collide; the assumption is that each host page renders one overlay only.
     *
     * @param int       $bbbid
     * @param int       $userid
     * @param string    $activerecordingid
     * @param string    $fallbackurl
     * @param \stdClass $user
     * @return void
     */
    private static function queue_inline_js(
        int $bbbid,
        int $userid,
        string $activerecordingid,
        string $fallbackurl,
        \stdClass $user
    ): void {
        global $PAGE;

        $jsbbbid = (int) $bbbid;
        $jsuserid = (int) $userid;
        $jsrecordingid = json_encode($activerecordingid);
        $jsfallback = json_encode($fallbackurl);
        $jsstrings = json_encode([
            'iframenotice'    => get_string('annotate_iframe_notice', 'bbbext_advgrd'),
            'unavailable'     => get_string('annotate_video_unavailable', 'bbbext_advgrd'),
            'seekunsup'       => get_string('annotate_seek_unsupported', 'bbbext_advgrd'),
            'savefailed'      => get_string('annotate_save_failed', 'bbbext_advgrd'),
            'emptybody'       => get_string('annotation_emptybody', 'bbbext_advgrd'),
            'deleteconfirm'   => get_string('annotate_delete_confirm', 'bbbext_advgrd'),
            'noplayback'      => get_string('annotate_no_playback', 'bbbext_advgrd'),
            'nocomments'      => get_string('annotate_no_comments', 'bbbext_advgrd'),
            'youlabel'        => get_string('annotate_comment_for', 'bbbext_advgrd', fullname($user)),
            'libheading'      => get_string('annotate_library_heading', 'bbbext_advgrd'),
            'libsearch'       => get_string('annotate_library_search', 'bbbext_advgrd'),
            'libfilterall'    => get_string('annotate_library_filter_all', 'bbbext_advgrd'),
            'libpersonal'     => get_string('annotate_library_personal_heading', 'bbbext_advgrd'),
            'libcourse'       => get_string('annotate_library_course_heading', 'bbbext_advgrd'),
            'libpersonalnone' => get_string('annotate_library_personal_none', 'bbbext_advgrd'),
            'libcoursenone'   => get_string('annotate_library_course_none', 'bbbext_advgrd'),
            'libclose'        => get_string('closebuttontitle', 'moodle'),
            'libdeleteconf'   => get_string('annotate_library_delete_confirm', 'bbbext_advgrd'),
            'libsaveas'       => get_string('annotate_library_save_as', 'bbbext_advgrd'),
            'libsavepersonal' => get_string('annotate_library_save_personal', 'bbbext_advgrd'),
            'libsavecourse'   => get_string('annotate_library_save_course', 'bbbext_advgrd'),
            'libsavedone'     => get_string('annotate_library_save_done', 'bbbext_advgrd'),
            'libemptysave'    => get_string('annotate_library_save_empty', 'bbbext_advgrd'),
            'markermediahint' => get_string('annotate_marker_media_hint', 'bbbext_advgrd'),
        ]);
        $jscategories = json_encode(annotations::CATEGORIES);
        $catlabels = [];
        foreach (annotations::CATEGORIES as $cat) {
            $catlabels[$cat] = get_string('annotate_category_' . $cat, 'bbbext_advgrd');
        }
        $jscatlabels = json_encode($catlabels);

        $PAGE->requires->js_amd_inline(<<<JS
require(['core/ajax', 'core/notification', 'core/templates'], function(Ajax, Notification) {
    var BBBID = {$jsbbbid};
    var USERID = {$jsuserid};
    var RECORDINGID = {$jsrecordingid};
    var FALLBACK_URL = {$jsfallback};
    var STR = {$jsstrings};
    var CATEGORIES = {$jscategories};
    var CATEGORY_LABELS = {$jscatlabels};

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

    function readCommentMeta(card) {
        var bodyEl = card.querySelector('.advgrd-comment-body');
        var hasAudio = !!card.querySelector('.advgrd-comment-body audio');
        var hasVideo = !!card.querySelector('.advgrd-comment-body video');
        var preview = '';
        if (bodyEl) {
            preview = (bodyEl.innerText || bodyEl.textContent || '').replace(/\s+/g, ' ').trim();
            if (preview.length > 100) preview = preview.substring(0, 100) + '…';
        }
        return {hasAudio: hasAudio, hasVideo: hasVideo, hasMedia: hasAudio || hasVideo, preview: preview};
    }

    function renderTimelineFromDom() {
        var bar = document.querySelector('[data-region="timeline-bar"]');
        if (!bar || videoDuration <= 0) return;
        var existingMarkers = bar.querySelectorAll('.advgrd-timeline-marker');
        existingMarkers.forEach(function(m) { m.remove(); });
        var items = document.querySelectorAll('[data-region="comment-list"] [data-region="comment-item"]');
        items.forEach(function(item) {
            var meta = readCommentMeta(item);
            addMarker({
                id: parseInt(item.dataset.id, 10),
                timestampms: parseInt(item.dataset.timestamp, 10),
                commenttype: item.dataset.category || 'general',
                hasAudio: meta.hasAudio,
                hasVideo: meta.hasVideo,
                hasMedia: meta.hasMedia,
                preview: meta.preview
            });
        });
    }

    function addMarker(row) {
        var bar = document.querySelector('[data-region="timeline-bar"]');
        if (!bar || videoDuration <= 0) return;
        var existing = bar.querySelector('[data-marker-id="' + row.id + '"]');
        if (existing) existing.remove();
        var ratio = (row.timestampms / 1000) / videoDuration;
        var percent = Math.min(100, Math.max(0, ratio * 100));
        var marker = document.createElement('div');
        var kindcls = row.hasAudio ? ' advgrd-marker-audio' : (row.hasVideo ? ' advgrd-marker-video' : ' advgrd-marker-text');
        marker.className = 'advgrd-timeline-marker advgrd-cat-' + row.commenttype + kindcls;
        marker.style.left = percent + '%';
        marker.dataset.markerId = row.id;
        marker.dataset.timestampms = row.timestampms;

        if (row.hasAudio) {
            var iconA = document.createElement('i');
            iconA.className = 'fa fa-volume-up';
            iconA.setAttribute('aria-hidden', 'true');
            marker.appendChild(iconA);
        } else if (row.hasVideo) {
            var iconV = document.createElement('i');
            iconV.className = 'fa fa-video-camera';
            iconV.setAttribute('aria-hidden', 'true');
            marker.appendChild(iconV);
        }

        var tooltip = document.createElement('div');
        tooltip.className = 'advgrd-timeline-tooltip';
        var label = CATEGORY_LABELS[row.commenttype] || row.commenttype;
        var time = formatTimestamp(row.timestampms);
        if (row.hasMedia) {
            tooltip.textContent = '[' + label + '] ' + time + ' — ' + STR.markermediahint;
        } else {
            tooltip.textContent = '[' + label + '] ' + time + (row.preview ? ' — ' + row.preview : '');
        }
        marker.appendChild(tooltip);

        marker.addEventListener('click', function(ev) {
            ev.stopPropagation();
            if (row.hasMedia) {
                playCommentMedia(row.id, row.timestampms);
            } else {
                showTextCallout(row.id, row.timestampms);
            }
        });

        // Tooltip edge-clip fix: when the marker is near the bar's left or right edge, the
        // default centred tooltip extends past the viewport. Pin the tooltip's left or right
        // edge to the marker instead so it stays on screen.
        if (percent < 15) {
            marker.classList.add('advgrd-tooltip-anchor-left');
        } else if (percent > 85) {
            marker.classList.add('advgrd-tooltip-anchor-right');
        }

        bar.appendChild(marker);
    }

    function showTextCallout(commentid, timestampms) {
        var card = document.querySelector('[data-region="comment-item"][data-id="' + commentid + '"]');
        if (!card) return;
        var bodyEl = card.querySelector('.advgrd-comment-body');
        if (!bodyEl) return;
        var callout = document.querySelector('[data-region="comment-callout"]');
        if (!callout) return;
        var commenttype = card.dataset.category || 'general';
        var label = CATEGORY_LABELS[commenttype] || commenttype;

        // Pause the recording and anchor it at the comment's timestamp - same UX shape as
        // the media callout: hit play after closing the callout and you're back where the
        // comment was made.
        if (videoEl && !videoEl.paused) {
            try { videoEl.pause(); } catch (e) { /* ignore */ }
        }
        if (videoEl && ownPlayer && !isNaN(videoEl.duration)) {
            videoEl.currentTime = timestampms / 1000;
        }

        callout.innerHTML = '';
        // Reset any prior variant class before applying the text squircle.
        callout.classList.remove('advgrd-audio-callout');
        callout.classList.add('advgrd-text-callout');

        var body = document.createElement('div');
        body.className = 'advgrd-comment-callout-body advgrd-text-body';
        body.innerHTML = bodyEl.innerHTML;
        callout.appendChild(body);

        // External chrome (chip + close) - same pattern as the media callout.
        var wrapper = callout.parentNode;
        var oldchip = wrapper.querySelector('[data-region="callout-chip"]');
        if (oldchip) oldchip.remove();
        var oldclose = wrapper.querySelector('[data-region="callout-close"]');
        if (oldclose) oldclose.remove();
        var oldexpand = wrapper.querySelector('[data-region="callout-expand"]');
        if (oldexpand) oldexpand.remove();
        callout.classList.remove('advgrd-expanded');

        var chip = document.createElement('span');
        chip.className = 'advgrd-callout-chip badge advgrd-badge advgrd-cat-' + commenttype;
        chip.dataset.region = 'callout-chip';
        chip.textContent = label;
        wrapper.appendChild(chip);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'advgrd-callout-close';
        close.dataset.region = 'callout-close';
        close.dataset.action = 'callout-close';
        close.setAttribute('aria-label', STR.libclose);
        close.innerHTML = '<i class="fa fa-times"></i>';
        wrapper.appendChild(close);

        callout.classList.remove('d-none');
    }

    function playCommentMedia(commentid, timestampms) {
        var card = document.querySelector('[data-region="comment-item"][data-id="' + commentid + '"]');
        if (!card) return;
        var source = card.querySelector('.advgrd-comment-body audio, .advgrd-comment-body video');
        if (!source) {
            seekTo(timestampms);
            return;
        }
        if (videoEl && !videoEl.paused) {
            try { videoEl.pause(); } catch (e) { /* ignore */ }
        }
        if (videoEl && ownPlayer && !isNaN(videoEl.duration)) {
            videoEl.currentTime = timestampms / 1000;
        }
        document.querySelectorAll('.advgrd-comment-body audio, .advgrd-comment-body video').forEach(function(el) {
            if (!el.paused) {
                try { el.pause(); } catch (e) { /* ignore */ }
            }
        });
        showCommentCallout(card, source, timestampms);
    }

    function showCommentCallout(card, source, timestampms) {
        var callout = document.querySelector('[data-region="comment-callout"]');
        if (!callout) return;
        var isVideo = source.tagName.toLowerCase() === 'video';
        var commenttype = card.dataset.category || 'general';
        var label = CATEGORY_LABELS[commenttype] || commenttype;

        callout.innerHTML = '';
        // Reset any prior variant class before applying this kind - the user can click
        // between text / audio / video markers without closing the callout in between.
        callout.classList.remove('advgrd-text-callout');
        callout.classList.remove('advgrd-audio-callout');
        // Audio gets the squircle layout; video stays as the circle bubble. Both share
        // the same play/pause + progress controls and external chip + close chrome.
        if (!isVideo) {
            callout.classList.add('advgrd-audio-callout');
        }

        var body = document.createElement('div');
        body.className = 'advgrd-comment-callout-body';
        var newMedia = document.createElement(isVideo ? 'video' : 'audio');
        newMedia.src = source.currentSrc || source.getAttribute('src') || '';
        newMedia.preload = 'metadata';
        newMedia.className = 'advgrd-callout-media';
        newMedia.controls = false;
        body.appendChild(newMedia);

        var playbtn = document.createElement('button');
        playbtn.type = 'button';
        playbtn.className = 'advgrd-callout-play';
        playbtn.setAttribute('aria-label', 'Play');
        playbtn.innerHTML = '<i class="fa fa-play"></i>';
        body.appendChild(playbtn);

        var timeDisplay = document.createElement('div');
        timeDisplay.className = 'advgrd-callout-time';
        timeDisplay.textContent = '0:00';
        body.appendChild(timeDisplay);

        var progress = document.createElement('div');
        progress.className = 'advgrd-callout-progress';
        var fill = document.createElement('div');
        fill.className = 'advgrd-callout-progress-fill';
        progress.appendChild(fill);
        body.appendChild(progress);

        callout.appendChild(body);

        // External chrome (category chip + close button) lives as siblings of the callout
        // inside the player wrapper - so the bubble's overflow:hidden can't clip them. The
        // chip sits to the left of the bubble; the close X sits at the top-right corner
        // of the bubble's bounding box (in the "empty corner" outside the visible circle).
        var wrapper = callout.parentNode;
        var oldchip = wrapper.querySelector('[data-region="callout-chip"]');
        if (oldchip) oldchip.remove();
        var oldclose = wrapper.querySelector('[data-region="callout-close"]');
        if (oldclose) oldclose.remove();
        var oldexpand = wrapper.querySelector('[data-region="callout-expand"]');
        if (oldexpand) oldexpand.remove();
        callout.classList.remove('advgrd-expanded');

        var chip = document.createElement('span');
        chip.className = 'advgrd-callout-chip badge advgrd-badge advgrd-cat-' + commenttype;
        chip.dataset.region = 'callout-chip';
        chip.textContent = label;
        wrapper.appendChild(chip);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'advgrd-callout-close';
        close.dataset.region = 'callout-close';
        close.dataset.action = 'callout-close';
        close.setAttribute('aria-label', STR.libclose);
        close.innerHTML = '<i class="fa fa-times"></i>';
        wrapper.appendChild(close);

        // Video-only: expand toggle. Pops the circle bubble out to a rounded rectangle
        // (480x300) with the feedback video at proper aspect, so screencast detail is
        // legible. Audio + text comments don't get this since the squircle's already roomy.
        if (isVideo) {
            var expand = document.createElement('button');
            expand.type = 'button';
            expand.className = 'advgrd-callout-expand';
            expand.dataset.region = 'callout-expand';
            expand.dataset.action = 'callout-expand';
            expand.setAttribute('aria-label', 'Expand');
            expand.innerHTML = '<i class="fa fa-expand"></i>';
            wrapper.appendChild(expand);
        }

        function fmtSec(s) {
            if (!isFinite(s) || isNaN(s) || s < 0) s = 0;
            var t = Math.floor(s);
            return Math.floor(t / 60) + ':' + (t % 60 < 10 ? '0' : '') + (t % 60);
        }
        function updateTimeDisplay() {
            var cur = fmtSec(newMedia.currentTime || 0);
            // Some browsers report duration=Infinity for variable-bitrate audio until the
            // file has been fully scanned. Hide the duration in that case rather than
            // emit "Infinity:NaN" - just show the live current time.
            var d = newMedia.duration;
            var dur = (isFinite(d) && d > 0) ? fmtSec(d) : null;
            timeDisplay.textContent = dur ? cur + ' / ' + dur : cur;
        }
        function syncPlayIcon() {
            if (newMedia.paused) {
                playbtn.innerHTML = '<i class="fa fa-play"></i>';
                playbtn.setAttribute('aria-label', 'Play');
                callout.classList.remove('advgrd-playing');
            } else {
                playbtn.innerHTML = '<i class="fa fa-pause"></i>';
                playbtn.setAttribute('aria-label', 'Pause');
                callout.classList.add('advgrd-playing');
            }
        }
        function toggle() {
            if (newMedia.paused) {
                newMedia.play().catch(function() { /* autoplay block */ });
            } else {
                newMedia.pause();
            }
        }
        playbtn.addEventListener('click', toggle);
        newMedia.addEventListener('play', syncPlayIcon);
        newMedia.addEventListener('pause', syncPlayIcon);
        newMedia.addEventListener('ended', syncPlayIcon);
        newMedia.addEventListener('loadedmetadata', updateTimeDisplay);
        newMedia.addEventListener('timeupdate', function() {
            if (!isNaN(newMedia.duration) && newMedia.duration > 0) {
                fill.style.width = Math.min(100, (newMedia.currentTime / newMedia.duration) * 100) + '%';
            }
            updateTimeDisplay();
        });
        progress.addEventListener('click', function(ev) {
            if (isNaN(newMedia.duration) || newMedia.duration <= 0) return;
            var rect = progress.getBoundingClientRect();
            var ratio = (ev.clientX - rect.left) / rect.width;
            newMedia.currentTime = Math.max(0, Math.min(newMedia.duration, newMedia.duration * ratio));
        });
        if (isVideo) {
            newMedia.addEventListener('click', toggle);
        }

        callout.classList.remove('d-none');
        newMedia.play().catch(function() { /* autoplay block - controls still work */ });
    }

    function toggleCalloutExpand(btn) {
        var callout = document.querySelector('[data-region="comment-callout"]');
        if (!callout) return;
        var expanding = !callout.classList.contains('advgrd-expanded');
        callout.classList.toggle('advgrd-expanded', expanding);
        btn.innerHTML = '<i class="fa fa-' + (expanding ? 'compress' : 'expand') + '"></i>';
        btn.setAttribute('aria-label', expanding ? 'Compress' : 'Expand');
    }

    function hideCommentCallout() {
        var callout = document.querySelector('[data-region="comment-callout"]');
        if (!callout) return;
        var media = callout.querySelector('audio, video');
        if (media) {
            try { media.pause(); } catch (e) { /* ignore */ }
        }
        var chip = document.querySelector('[data-region="callout-chip"]');
        if (chip) chip.remove();
        var closebtn = document.querySelector('[data-region="callout-close"]');
        if (closebtn) closebtn.remove();
        var expandbtn = document.querySelector('[data-region="callout-expand"]');
        if (expandbtn) expandbtn.remove();
        callout.classList.add('d-none');
        callout.classList.remove('advgrd-text-callout');
        callout.classList.remove('advgrd-audio-callout');
        callout.classList.remove('advgrd-expanded');
        callout.innerHTML = '';
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
            if (t) {
                t.save();
                return t.getContent();
            }
        }
        var ta = document.getElementById('advgrd-body');
        return ta ? ta.value : '';
    }

    function setEditorContent(html) {
        if (window.tinymce) {
            var t = window.tinymce.get('advgrd-body');
            if (t) {
                t.setContent(html || '');
                return;
            }
        }
        var ta = document.getElementById('advgrd-body');
        if (ta) ta.value = html || '';
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
            Notification.alert('', STR.emptybody);
            return;
        }
        var tsEl = document.getElementById('advgrd-timestamp');
        var ms;
        if (ownPlayer && videoEl && !isNaN(videoEl.currentTime)) {
            ms = Math.floor(videoEl.currentTime * 1000);
            tsEl.value = formatTimestamp(ms);
        } else {
            ms = parseTimestamp(tsEl.value);
            if (ms === null) ms = 0;
        }
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
        }])[0].then(function(row) {
            clearEditor();
            tsEl.value = '0:00';
            appendComment(row);
        }).catch(Notification.exception);
    }

    function appendComment(row) {
        var list = document.querySelector('[data-region="comment-list"]');
        if (!list) return;
        var empty = list.querySelector('[data-region="empty-state"]');
        if (empty) empty.remove();
        var card = buildCommentItem(row);
        card.classList.add('advgrd-fade-in');
        list.appendChild(card);
        var meta = readCommentMeta(card);
        addMarker({
            id: row.id,
            timestampms: row.timestampms,
            commenttype: row.commenttype,
            hasAudio: meta.hasAudio,
            hasVideo: meta.hasVideo,
            hasMedia: meta.hasMedia,
            preview: meta.preview
        });
        card.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function deleteComment(id) {
        if (!window.confirm(STR.deleteconfirm)) return;
        var intid = parseInt(id, 10);
        Ajax.call([{
            methodname: 'bbbext_advgrd_delete_annotation',
            args: {id: intid}
        }])[0].then(function() {
            var card = document.querySelector('[data-region="comment-item"][data-id="' + intid + '"]');
            if (card) {
                card.classList.add('advgrd-fade-out');
                setTimeout(function() {
                    card.remove();
                    var list = document.querySelector('[data-region="comment-list"]');
                    if (list && !list.querySelector('[data-region="comment-item"]')) {
                        var p = document.createElement('p');
                        p.className = 'text-muted';
                        p.dataset.region = 'empty-state';
                        p.textContent = STR.nocomments;
                        list.appendChild(p);
                    }
                }, 250);
            }
            var bar = document.querySelector('[data-region="timeline-bar"]');
            if (bar) {
                var marker = bar.querySelector('[data-marker-id="' + intid + '"]');
                if (marker) marker.remove();
            }
        }).catch(Notification.exception);
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
        left.className = 'flex-grow-1 d-flex flex-wrap align-items-center gap-2';

        var seek = document.createElement('a');
        seek.href = '#';
        seek.className = 'advgrd-timestamp-link badge text-white';
        seek.dataset.action = 'seek';
        seek.dataset.timestamp = row.timestampms;
        seek.innerHTML = '<i class="fa fa-clock-o"></i> ' + formatTimestamp(row.timestampms);
        left.appendChild(seek);

        var pill = document.createElement('span');
        pill.className = 'badge advgrd-badge advgrd-cat-' + row.commenttype;
        pill.textContent = CATEGORY_LABELS[row.commenttype] || row.commenttype;
        left.appendChild(pill);

        if (row.gradername) {
            var author = document.createElement('small');
            author.className = 'text-muted';
            author.textContent = row.gradername;
            left.appendChild(author);
        }

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-link text-danger';
        del.dataset.action = 'delete-comment';
        del.dataset.id = row.id;
        del.setAttribute('title', STR.deleteconfirm);
        del.innerHTML = '<i class="fa fa-trash"></i>';
        meta.appendChild(left);
        meta.appendChild(del);

        var content = document.createElement('div');
        content.className = 'mt-2 advgrd-comment-body';
        content.innerHTML = row.body;

        body.appendChild(meta);
        body.appendChild(content);
        card.appendChild(body);
        return card;
    }

    var libraryCache = null;
    var libraryFilter = {type: 'all', query: ''};

    function escapeAttr(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(s == null ? '' : s)));
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    function categoryColor(key) {
        switch (key) {
            case 'praise': return '#28a745';
            case 'correction': return '#dc3545';
            case 'suggestion': return '#0d6efd';
            case 'question': return '#6f42c1';
            default: return '#6c757d';
        }
    }

    function openLibrary() {
        var panel = document.querySelector('[data-region="library-panel"]');
        if (!panel) return;
        if (libraryCache) {
            renderLibraryPanel(libraryCache);
            panel.classList.remove('d-none');
            return;
        }
        Ajax.call([{
            methodname: 'bbbext_advgrd_get_library',
            args: {bbbid: BBBID}
        }])[0].then(function(data) {
            libraryCache = data;
            renderLibraryPanel(data);
            panel.classList.remove('d-none');
        }).catch(Notification.exception);
    }

    function closeLibrary() {
        var panel = document.querySelector('[data-region="library-panel"]');
        if (panel) panel.classList.add('d-none');
    }

    function renderLibraryPanel(data) {
        var panel = document.querySelector('[data-region="library-panel"]');
        if (!panel) return;
        var html = '<div class="card">';
        html += '<div class="card-body">';

        html += '<div class="d-flex justify-content-between align-items-center mb-3">';
        html += '<h5 class="mb-0">' + escapeAttr(STR.libheading) + '</h5>';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary" ' +
                'data-action="library-close" title="' + escapeAttr(STR.libclose) + '">' +
                '<i class="fa fa-times"></i></button>';
        html += '</div>';

        html += '<input type="text" class="form-control form-control-sm mb-2" ' +
                'data-region="library-search" placeholder="' + escapeAttr(STR.libsearch) + '">';

        html += '<div class="mb-3 d-flex flex-wrap gap-1">';
        html += '<span class="badge bg-secondary advgrd-library-filter" data-filter="all" ' +
                'role="button" data-active="1">' + escapeAttr(STR.libfilterall) + '</span>';
        CATEGORIES.forEach(function(key) {
            html += '<span class="badge advgrd-library-filter" data-filter="' + key + '" ' +
                    'role="button" style="background-color:' + categoryColor(key) +
                    ';color:#fff;cursor:pointer;">' + escapeAttr(CATEGORY_LABELS[key] || key) + '</span>';
        });
        html += '</div>';

        html += '<div class="advgrd-library-section" data-scope="personal">';
        html += '<h6>' + escapeAttr(STR.libpersonal) + '</h6>';
        if (!data.personal.length) {
            html += '<p class="text-muted small mb-0">' + escapeAttr(STR.libpersonalnone) + '</p>';
        } else {
            data.personal.forEach(function(item) { html += renderLibraryItem(item); });
        }
        html += '</div>';

        html += '<hr>';
        html += '<div class="advgrd-library-section" data-scope="course">';
        html += '<h6>' + escapeAttr(STR.libcourse) + '</h6>';
        if (!data.shared.length) {
            html += '<p class="text-muted small mb-0">' + escapeAttr(STR.libcoursenone) + '</p>';
        } else {
            data.shared.forEach(function(item) { html += renderLibraryItem(item); });
        }
        html += '</div>';

        html += '</div></div>';
        panel.innerHTML = html;
        applyLibraryFilter();
    }

    function renderLibraryItem(item) {
        var key = item.commenttype || 'general';
        var color = categoryColor(key);
        var label = CATEGORY_LABELS[key] || key;
        var plain = (item.commenttext || '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
        var preview = plain.length > 140 ? plain.substring(0, 140) + '…' : plain;

        var html = '<div class="advgrd-library-item d-flex align-items-start p-2 mb-1 border rounded" ' +
                   'data-region="library-item" data-itemid="' + item.id + '" ' +
                   'data-commenttype="' + key + '" data-commenttext="' + escapeAttr(item.commenttext) +
                   '" data-plain="' + escapeAttr(plain) + '">';
        html += '<span class="badge me-2" style="background-color:' + color +
                ';color:#fff;min-width:75px;font-size:0.75em;">' + escapeAttr(label) + '</span>';
        html += '<span class="flex-grow-1 small advgrd-library-item-text" data-action="library-insert" ' +
                'role="button">' + escapeAttr(preview) + '</span>';
        if (item.isowner) {
            html += '<button type="button" class="btn btn-sm btn-outline-danger ms-2" ' +
                    'data-action="library-delete"><i class="fa fa-trash"></i></button>';
        }
        html += '</div>';
        return html;
    }

    function applyLibraryFilter() {
        var panel = document.querySelector('[data-region="library-panel"]');
        if (!panel) return;
        var items = panel.querySelectorAll('[data-region="library-item"]');
        var query = libraryFilter.query;
        var type = libraryFilter.type;
        items.forEach(function(item) {
            var matchType = type === 'all' || item.dataset.commenttype === type;
            var matchQuery = !query || (item.dataset.plain || '').toLowerCase().indexOf(query) !== -1;
            item.style.display = (matchType && matchQuery) ? '' : 'none';
        });
    }

    function insertFromLibrary(itemEl) {
        var text = itemEl.dataset.commenttext || '';
        var type = itemEl.dataset.commenttype || 'general';
        setEditorContent(text);
        var catSel = document.getElementById('advgrd-category');
        if (catSel) catSel.value = type;
        closeLibrary();
    }

    function deleteLibraryItem(itemEl) {
        if (!window.confirm(STR.libdeleteconf)) return;
        var id = parseInt(itemEl.dataset.itemid, 10);
        Ajax.call([{
            methodname: 'bbbext_advgrd_delete_library_comment',
            args: {bbbid: BBBID, id: id}
        }])[0].then(function() {
            libraryCache = null;
            itemEl.remove();
        }).catch(Notification.exception);
    }

    function showLibrarySaveScopePicker() {
        var bodyhtml = (getEditorContent() || '').trim();
        var plain = bodyhtml.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '').trim();
        if (!plain) {
            Notification.alert('', STR.libemptysave);
            return;
        }
        var existing = document.querySelector('[data-region="library-save-picker"]');
        if (existing) { existing.remove(); return; }
        var card = document.createElement('div');
        card.className = 'card p-2 mt-2 mb-2';
        card.dataset.region = 'library-save-picker';
        var p = document.createElement('p');
        p.className = 'mb-2 small';
        p.textContent = STR.libsaveas;
        card.appendChild(p);
        var personalBtn = document.createElement('button');
        personalBtn.type = 'button';
        personalBtn.className = 'btn btn-sm btn-primary me-2';
        personalBtn.dataset.action = 'library-save-scope';
        personalBtn.dataset.scope = 'personal';
        personalBtn.textContent = STR.libsavepersonal;
        card.appendChild(personalBtn);
        var courseBtn = document.createElement('button');
        courseBtn.type = 'button';
        courseBtn.className = 'btn btn-sm btn-outline-primary';
        courseBtn.dataset.action = 'library-save-scope';
        courseBtn.dataset.scope = 'course';
        courseBtn.textContent = STR.libsavecourse;
        card.appendChild(courseBtn);
        var panel = document.querySelector('[data-region="library-panel"]');
        if (panel) {
            panel.parentNode.insertBefore(card, panel);
        }
    }

    function saveLibraryWithScope(scope) {
        var bodyhtml = (getEditorContent() || '').trim();
        var plain = bodyhtml.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '').trim();
        if (!plain) {
            Notification.alert('', STR.libemptysave);
            return;
        }
        var commenttype = document.getElementById('advgrd-category').value;
        if (CATEGORIES.indexOf(commenttype) === -1) commenttype = 'general';
        var picker = document.querySelector('[data-region="library-save-picker"]');
        if (picker) picker.remove();
        Ajax.call([{
            methodname: 'bbbext_advgrd_save_library_comment',
            args: {
                bbbid: BBBID,
                commenttext: bodyhtml,
                commenttype: commenttype,
                scope: scope,
                itemid: 0
            }
        }])[0].then(function() {
            libraryCache = null;
            Notification.alert('', STR.libsavedone);
        }).catch(Notification.exception);
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
                } else if (action === 'library-open') {
                    ev.preventDefault();
                    openLibrary();
                } else if (action === 'library-close') {
                    ev.preventDefault();
                    closeLibrary();
                } else if (action === 'library-insert') {
                    ev.preventDefault();
                    var item = t.closest('[data-region="library-item"]');
                    if (item) insertFromLibrary(item);
                } else if (action === 'library-delete') {
                    ev.preventDefault();
                    var ditem = t.closest('[data-region="library-item"]');
                    if (ditem) deleteLibraryItem(ditem);
                } else if (action === 'library-save') {
                    ev.preventDefault();
                    showLibrarySaveScopePicker();
                } else if (action === 'library-save-scope') {
                    ev.preventDefault();
                    saveLibraryWithScope(t.dataset.scope);
                } else if (action === 'callout-close') {
                    ev.preventDefault();
                    hideCommentCallout();
                } else if (action === 'callout-expand') {
                    ev.preventDefault();
                    toggleCalloutExpand(t);
                }
            }

            var pill = ev.target.closest('.advgrd-library-filter');
            if (pill) {
                var panel = document.querySelector('[data-region="library-panel"]');
                if (panel) {
                    panel.querySelectorAll('.advgrd-library-filter').forEach(function(p) {
                        p.removeAttribute('data-active');
                    });
                    pill.setAttribute('data-active', '1');
                }
                libraryFilter.type = pill.dataset.filter || 'all';
                applyLibraryFilter();
                return;
            }

            if (ev.target.closest('audio, input, button, a')) return;
            var commentLi = ev.target.closest('.advgrd-comment-item');
            if (commentLi && commentLi.dataset.timestamp) {
                seekTo(parseInt(commentLi.dataset.timestamp, 10));
            }
        });

        document.addEventListener('input', function(ev) {
            if (ev.target && ev.target.dataset.region === 'library-search') {
                libraryFilter.query = (ev.target.value || '').toLowerCase();
                applyLibraryFilter();
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
}
