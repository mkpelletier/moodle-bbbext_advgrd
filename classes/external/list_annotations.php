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
 * External function: list annotations for a (recording, target student) pair.
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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the timeline-ready annotation list. Used by the JS to refresh after a post or delete.
 */
class list_annotations extends external_api {
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
        ]);
    }

    /**
     * Fetch the list.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @return array
     */
    public static function execute(int $bbbid, string $recordingid, int $targetuserid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'bbbid'        => $bbbid,
            'recordingid'  => $recordingid,
            'targetuserid' => $targetuserid,
        ]);

        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        $rows = annotations::list_for_review($params['bbbid'], $params['recordingid'], $params['targetuserid']);
        $payload = [];
        foreach ($rows as $row) {
            $payload[] = add_annotation::row_to_payload($row, (int) $USER->id);
        }
        return $payload;
    }

    /**
     * Return-shape declaration.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'Annotation id'),
            'timestampms'  => new external_value(PARAM_INT, 'Anchor position in ms'),
            'kind'         => new external_value(PARAM_ALPHA, 'text or audio'),
            'body'         => new external_value(PARAM_RAW, 'Comment text'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'Category key'),
            'graderid'     => new external_value(PARAM_INT, 'Author id'),
            'gradername'   => new external_value(PARAM_TEXT, 'Author full name'),
            'timecreated'  => new external_value(PARAM_INT, 'Creation timestamp'),
        ]));
    }
}
