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
 * External function: persist an annotation comment authored in the Atto editor.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\external;

use bbbext_advgrd\local\annotations;
use bbbext_advgrd\local\grader;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * AJAX endpoint that takes editor output (HTML body + draftitemid carrying any embedded audio
 * or image files) and persists a new annotation row, returning a payload the client uses to
 * refresh the comment list and timeline.
 */
class add_annotation extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bbbid'        => new external_value(PARAM_INT, 'BBB instance id'),
            'recordingid'  => new external_value(PARAM_RAW_TRIMMED, 'BBB recording id'),
            'targetuserid' => new external_value(PARAM_INT, 'Addressed student id'),
            'timestampms'  => new external_value(PARAM_INT, 'Position in the recording (ms)'),
            'body'         => new external_value(PARAM_RAW, 'Editor HTML body'),
            'bodyformat'   => new external_value(PARAM_INT, 'Moodle text format', VALUE_DEFAULT, FORMAT_HTML),
            'commenttype'  => new external_value(PARAM_ALPHA, 'One of: general, praise, correction, suggestion, question'),
            'draftitemid'  => new external_value(PARAM_INT, 'Editor draft itemid for attached files'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @param int    $timestampms
     * @param string $body
     * @param int    $bodyformat
     * @param string $commenttype
     * @param int    $draftitemid
     * @return array
     */
    public static function execute(
        int $bbbid,
        string $recordingid,
        int $targetuserid,
        int $timestampms,
        string $body,
        int $bodyformat,
        string $commenttype,
        int $draftitemid
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'bbbid'        => $bbbid,
            'recordingid'  => $recordingid,
            'targetuserid' => $targetuserid,
            'timestampms'  => $timestampms,
            'body'         => $body,
            'bodyformat'   => $bodyformat,
            'commenttype'  => $commenttype,
            'draftitemid'  => $draftitemid,
        ]);

        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        $row = annotations::create(
            $params['bbbid'],
            $params['recordingid'],
            $params['targetuserid'],
            (int) $USER->id,
            $params['timestampms'],
            $params['body'],
            $params['bodyformat'],
            $params['commenttype'],
            $params['draftitemid'],
            $info['context']
        );

        return shaper::shape_row($row, $info['context']);
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return shaper::row_structure();
    }
}
