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
 * Multipart POST endpoint for audio-comment uploads. JS records via MediaRecorder, then posts
 * the webm blob + metadata here. The endpoint persists the annotation row, saves the file to
 * the bbbext_advgrd/audiocomment filearea keyed by the row id, and returns the row's JSON shape.
 *
 * External AJAX endpoints can't take multipart uploads cleanly, hence the dedicated PHP file.
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

require_sesskey();

$bbbid = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$recordingid = required_param('recordingid', PARAM_RAW_TRIMMED);
$timestampms = required_param('timestampms', PARAM_INT);
$commenttype = required_param('commenttype', PARAM_ALPHA);
$caption = optional_param('body', '', PARAM_RAW_TRIMMED);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];

require_login($bbb->course, false, $cm);
require_capability('bbbext/advgrd:grade', $context);

if (empty($_FILES['audiofile']) || $_FILES['audiofile']['error'] !== UPLOAD_ERR_OK) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'no_file']);
    exit;
}

// Conservative 25MB cap. 300s of opus@~32kbps is ~1.2MB; the cap is a defence-in-depth bound.
$maxbytes = 25 * 1024 * 1024;
if ($_FILES['audiofile']['size'] > $maxbytes) {
    header('HTTP/1.1 413 Payload Too Large');
    echo json_encode(['error' => 'file_too_large']);
    exit;
}

$row = annotations::create_audio(
    $bbbid,
    $recordingid,
    $userid,
    (int) $USER->id,
    $timestampms,
    $caption,
    $commenttype
);

$fs = get_file_storage();
$filerecord = (object) [
    'contextid' => $context->id,
    'component' => 'bbbext_advgrd',
    'filearea'  => annotations::AUDIO_FILEAREA,
    'itemid'    => $row->id,
    'filepath'  => '/',
    'filename'  => 'audio.webm',
    'mimetype'  => 'audio/webm',
];
$fs->create_file_from_pathname($filerecord, $_FILES['audiofile']['tmp_name']);

$author = $DB->get_record('user', ['id' => $row->graderid], 'id, firstname, lastname');
header('Content-Type: application/json');
echo json_encode([
    'id'           => (int) $row->id,
    'timestampms'  => (int) $row->timestampms,
    'kind'         => 'audio',
    'body'         => (string) ($row->body ?? ''),
    'commenttype'  => $row->commenttype,
    'graderid'     => (int) $row->graderid,
    'gradername'   => $author ? fullname($author) : '',
    'timecreated'  => (int) $row->timecreated,
]);
