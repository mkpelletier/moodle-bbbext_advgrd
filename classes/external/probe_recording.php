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
        require_capability('bbbext/advgrd:grade', $info['context']);

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
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, 'failed', 0));
        }

        $captureurl = $rec->get_remote_playback_url('capture');
        if (!$captureurl) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, 'iframe', 0));
        }

        $curl = new \curl();
        $html = $curl->get($captureurl);
        if ($curl->get_errno() || (int) ($curl->info['http_code'] ?? 0) !== 200) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, 'failed', 0));
        }

        // BBB's /capture/ player renders an HTML5 <video> with a direct media src - usually
        // an .m4v on /presentation/<id>/video/webcams.m4v.
        if (!preg_match('#<video[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $matches)) {
            return self::shape(self::persist($params['bbbid'], $params['recordingid'], null, 'iframe', 0));
        }

        $mediaurl = trim($matches[1]);
        if (parse_url($mediaurl, PHP_URL_SCHEME) === null) {
            $base = preg_replace('#/[^/]*$#', '/', $captureurl);
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
     * @param string      $status
     * @param int         $durationms
     * @return \stdClass
     */
    protected static function persist(
        int $bbbid,
        string $recordingid,
        ?string $mediaurl,
        string $status,
        int $durationms
    ): \stdClass {
        global $DB;
        $row = (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid'       => $recordingid,
            'mediaurl'          => $mediaurl,
            'probestatus'       => $status,
            'durationms'        => $durationms,
            'iframeurl'         => null,
            'timeprobed'        => time(),
        ];
        $row->id = $DB->insert_record('bbbext_advgrd_rec_probe', $row);
        return $row;
    }

    /**
     * Shape a probe row for the JSON response.
     *
     * @param \stdClass $row
     * @return array
     */
    protected static function shape(\stdClass $row): array {
        return [
            'status'     => (string) $row->probestatus,
            'mediaurl'   => $row->mediaurl ?? '',
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
