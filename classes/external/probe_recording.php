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
 * External function: probe a BBB recording for a directly playable media URL.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\external;

use bbbext_advgrd\local\grader;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\recording;

/**
 * Tries to extract a direct media URL from BBB's /capture/ playback HTML so the grading
 * client can mount its own HTML5 <video> with click-to-seek. Caches the result per recording
 * so repeat grading visits skip the network round-trip.
 */
class probe_recording extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bbbid'       => new external_value(PARAM_INT, 'BBB instance id'),
            'recordingid' => new external_value(PARAM_RAW_TRIMMED, 'BBB recording id'),
            'refresh'     => new external_value(PARAM_BOOL, 'Force re-probe', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Execute.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param bool   $refresh
     * @return array
     */
    public static function execute(int $bbbid, string $recordingid, bool $refresh = false): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'bbbid'       => $bbbid,
            'recordingid' => $recordingid,
            'refresh'     => $refresh,
        ]);

        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        // The probe just resolves the BBB recording's playback URL, which the user can
        // already reach via the activity itself - any viewer of the activity (student or
        // teacher) gets it, not just graders. require_capability('viewownreport') passes
        // for both archetypes per db/access.php.
        require_capability('bbbext/advgrd:viewownreport', $info['context']);

        if (!$params['refresh']) {
            $cached = $DB->get_record('bbbext_advgrd_rec_probe', ['recordingid' => $params['recordingid']]);
            if ($cached) {
                return self::shape($cached);
            }
        } else {
            $DB->delete_records('bbbext_advgrd_rec_probe', ['recordingid' => $params['recordingid']]);
        }

        $bbbinstance = instance::get_from_instanceid($params['bbbid']);
        $recordings = recording::get_recordings_for_instance($bbbinstance);
        $rec = null;
        foreach ($recordings as $candidate) {
            if ($candidate->get('recordingid') === $params['recordingid']) {
                $rec = $candidate;
                break;
            }
        }
        if (!$rec) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, null, 'failed', 0));
        }

        // Different BBB builds expose the camera-only player under different playback type
        // names. Recent ones we've seen call it 'video' (length-3 short videos), older ones
        // call it 'capture'. Both point at the same /capture/ player HTML where the m4v
        // lives in a <source> child. Try in that order; 'presentation' is excluded because
        // it's the full slides+video+chat player and the m4v isn't on the top-level HTML.
        $captureurl = null;
        foreach (['video', 'capture'] as $type) {
            $candidate = $rec->get_remote_playback_url($type);
            if ($candidate) {
                $captureurl = $candidate;
                break;
            }
        }
        if (!$captureurl) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, null, 'iframe', 0));
        }

        $curl = new \curl();
        $html = $curl->get($captureurl);
        if ($curl->get_errno() || (int) ($curl->info['http_code'] ?? 0) !== 200) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, null, 'failed', 0));
        }

        // BBB's /capture/ player renders an HTML5 <video> with the media file in a
        // child <source src="video-0.m4v" type="video/mp4"> element rather than on the
        // <video> tag itself. Try both shapes and resolve relative URLs against the
        // capture URL base.
        $mediaurl = null;
        if (preg_match('#<source[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $matches)) {
            $mediaurl = trim($matches[1]);
        } else if (preg_match('#<video[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $matches)) {
            $mediaurl = trim($matches[1]);
        }
        if ($mediaurl === null) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, null, 'iframe', 0));
        }

        if (parse_url($mediaurl, PHP_URL_SCHEME) === null) {
            // Resolve the relative URL against the capture page URL. The capture URL itself
            // may or may not have a trailing slash - normalise to ensure relative resolution
            // treats it as a directory.
            $base = $captureurl;
            if (substr($base, -1) !== '/') {
                $base .= '/';
            }
            $mediaurl = $base . ltrim($mediaurl, '/');
        }

        $durationms = 0;
        if (preg_match('#\bdata-length\s*=\s*["\'](\d+)["\']#i', $html, $dm)) {
            $durationms = (int) $dm[1] * 1000;
        }

        return self::shape(self::persist(
            $params['bbbid'],
            $params['recordingid'],
            $mediaurl,
            $captureurl,
            'ok',
            $durationms
        ));
    }

    /**
     * Persist + return the probe row.
     *
     * @param int         $bbbid
     * @param string      $recordingid
     * @param string|null $mediaurl
     * @param string|null $captureurl
     * @param string      $status
     * @param int         $durationms
     * @return \stdClass
     */
    protected static function persist(
        int $bbbid,
        string $recordingid,
        ?string $mediaurl,
        ?string $captureurl,
        string $status,
        int $durationms
    ): \stdClass {
        global $DB;
        $row = (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid'       => $recordingid,
            'mediaurl'          => $mediaurl,
            'captureurl'        => $captureurl,
            'probestatus'       => $status,
            'durationms'        => $durationms,
            'iframeurl'         => null,
            'timeprobed'        => time(),
        ];
        // The recordingid column is uniquely indexed. Two grading tabs opened at once both
        // miss the cache and both probe, so tolerate the loser of that race rather than
        // throwing a duplicate-key exception into an otherwise fine page load.
        if ($existing = $DB->get_record('bbbext_advgrd_rec_probe', ['recordingid' => $recordingid], 'id')) {
            $row->id = $existing->id;
            $DB->update_record('bbbext_advgrd_rec_probe', $row);
        } else {
            $row->id = $DB->insert_record('bbbext_advgrd_rec_probe', $row);
        }
        return $row;
    }

    /**
     * Shape a probe row for the JSON response.
     *
     * The scraped BBB media URL is deliberately NOT returned. BBB gates its raw recording
     * files behind an authorisation cookie that only its own playback page sets, and this
     * probe earns that cookie server-side in a throwaway curl jar - the browser has none,
     * so a <video> pointed straight at the BBB host 403s and renders a black box. The
     * client gets a same-origin Moodle URL instead and pages/play.php proxies the bytes.
     *
     * @param \stdClass $row
     * @return array
     */
    protected static function shape(\stdClass $row): array {
        $mediaurl = '';
        if ((string) $row->probestatus === 'ok' && !empty($row->mediaurl)) {
            $mediaurl = (new \moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/play.php', [
                'id'          => (int) $row->bigbluebuttonbnid,
                'recordingid' => (string) $row->recordingid,
            ]))->out(false);
        }
        return [
            'status'     => (string) $row->probestatus,
            'mediaurl'   => $mediaurl,
            'durationms' => (int) ($row->durationms ?? 0),
        ];
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'     => new external_value(PARAM_ALPHA, 'ok | iframe | failed'),
            'mediaurl'   => new external_value(PARAM_RAW, 'Direct media URL when status=ok'),
            'durationms' => new external_value(PARAM_INT, 'Duration in ms if detected'),
        ]);
    }
}
