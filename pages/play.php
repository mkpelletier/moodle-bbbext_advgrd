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
 * Same-origin media proxy for a probed BBB recording.
 *
 * BigBlueButton gates the raw recording files (video-0.m4v and friends) behind an
 * authorisation cookie that its own playback page sets. The annotation overlay mounts a
 * plain HTML5 <video>, and the browser has no such cookie - so pointing that element
 * straight at the BBB host yields a 403 and a silent black box. It only ever worked after
 * the marker had separately opened the recording from the BBB activity, because that
 * top-level navigation set the cookie first-party in their browser.
 *
 * This endpoint closes that gap: it replays the /capture/ handshake server-side, keeps the
 * resulting cookie in a per-user jar, and streams the media back from Moodle's own origin.
 * Byte ranges are forwarded in both directions - without them the overlay's click-to-seek,
 * which is the entire point of the own-player path, cannot work.
 *
 * The machinery lives in bbbext_advgrd\local\media_proxy; this file is only the entry point,
 * so that nothing the plugin defines lands in the global namespace.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Bootstrap moodle: use SCRIPT_FILENAME instead of __DIR__ so the page works when the plugin
// source is symlinked from outside the moodle tree (a common dev workflow). String ops only -
// any '..' path resolution would traverse the dev symlink and miss config.php. Kept inline
// rather than in a named variable so the page adds nothing to the global scope config.php
// is about to populate.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
require(implode('/', array_slice(explode('/', $_SERVER['SCRIPT_FILENAME'] ?? __FILE__), 0, -6)) . '/config.php');
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\media_proxy;

$bbbid = required_param('id', PARAM_INT);
// A BBB recordID is <internal-meeting-sha1>-<epoch-millis>, so PARAM_ALPHANUMEXT ([a-zA-Z0-9_-])
// covers every legitimate value. Anything else is not a recording we could match anyway, and
// cleaning it here keeps the string out of the cookie-jar filename and the probe lookup below.
$recordingid = required_param('recordingid', PARAM_ALPHANUMEXT);

$info = grader::bootstrap($bbbid);
$bbb = $info['bbb'];
$cm = $info['cm'];
$context = $info['context'];

require_login($bbb->course, false, $cm);
// Same gate as the probe that produced this row: anyone who can view the activity's own
// report can reach the recording through the activity itself, so proxying it grants nothing
// extra. Students and teachers both pass per db/access.php.
require_capability('bbbext/advgrd:viewownreport', $context);

$probe = $DB->get_record('bbbext_advgrd_rec_probe', [
    'bigbluebuttonbnid' => $bbbid,
    'recordingid'       => $recordingid,
]);
if (!media_proxy::probe_is_proxyable($probe)) {
    // Nothing usable to proxy. The overlay's own error handling takes it from here and falls
    // back to BBB's hosted player.
    media_proxy::abort(404);
}

// The stream can run for the length of a lecture. Release the session lock first or every
// other request this user makes - including the grading page they are marking on - blocks
// behind it.
\core\session\manager::write_close();

// Nothing below writes through Moodle's output layer; the response is raw media bytes.
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', 'Off');
core_php_time_limit::raise(0);

$jar = media_proxy::cookie_jar($USER->id, $recordingid);
if (media_proxy::jar_is_stale($jar)) {
    media_proxy::handshake($probe->captureurl, $jar);
}

$status = media_proxy::stream($probe->mediaurl, $jar);
if ($status === 401 || $status === 403) {
    // Cookie expired or was never granted. Nothing has been sent to the client yet - the
    // streamer withholds output until it knows the upstream status - so one clean retry
    // behind a fresh handshake is safe.
    media_proxy::handshake($probe->captureurl, $jar, true);
    $status = media_proxy::stream($probe->mediaurl, $jar);
}
if ($status < 200 || $status >= 300) {
    media_proxy::abort($status === 404 ? 404 : 502);
}
exit;
