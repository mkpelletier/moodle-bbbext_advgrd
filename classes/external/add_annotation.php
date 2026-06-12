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
 * External function: persist a text annotation on a BBB recording.
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
 * AJAX endpoint called by grade_page.js when the teacher posts a text comment.
 *
 * Audio comments take a different code path (multipart upload + create_audio); this endpoint
 * handles text only.
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
            'recordingid'  => new external_value(PARAM_RAW_TRIMMED, 'BBB recording id', VALUE_REQUIRED),
            'targetuserid' => new external_value(PARAM_INT, 'Addressed student id'),
            'timestampms'  => new external_value(PARAM_INT, 'Position in the recording (ms)'),
            'body'         => new external_value(PARAM_RAW_TRIMMED, 'Comment text'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'One of: general, praise, correction, suggestion, question'),
        ]);
    }

    /**
     * Persist a text annotation.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @param int    $timestampms
     * @param string $body
     * @param string $commenttype
     * @return array
     */
    public static function execute(int $bbbid, string $recordingid, int $targetuserid,
                                   int $timestampms, string $body, string $commenttype): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'bbbid'        => $bbbid,
            'recordingid'  => $recordingid,
            'targetuserid' => $targetuserid,
            'timestampms'  => $timestampms,
            'body'         => $body,
            'commenttype'  => $commenttype,
        ]);

        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        $row = annotations::create_text(
            $params['bbbid'],
            $params['recordingid'],
            $params['targetuserid'],
            (int) $USER->id,
            $params['timestampms'],
            $params['body'],
            $params['commenttype']
        );

        return self::row_to_payload($row, (int) $USER->id);
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'Annotation id'),
            'timestampms'  => new external_value(PARAM_INT, 'Anchor position in ms'),
            'kind'         => new external_value(PARAM_ALPHA, 'text or audio'),
            'body'         => new external_value(PARAM_RAW, 'Comment text'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'Category key'),
            'graderid'     => new external_value(PARAM_INT, 'Author id'),
            'gradername'   => new external_value(PARAM_TEXT, 'Author full name'),
            'timecreated'  => new external_value(PARAM_INT, 'Creation timestamp'),
        ]);
    }

    /**
     * Shape an annotation row for the JS timeline. Shared with list_annotations so the
     * client always sees the same fields.
     *
     * @param \stdClass $row
     * @param int       $graderid
     * @return array
     */
    public static function row_to_payload(\stdClass $row, int $graderid): array {
        global $DB;
        $author = $DB->get_record('user', ['id' => $row->graderid ?? $graderid], 'id, firstname, lastname');
        return [
            'id'           => (int) $row->id,
            'timestampms'  => (int) $row->timestampms,
            'kind'         => $row->kind,
            'body'         => (string) ($row->body ?? ''),
            'commenttype'  => $row->commenttype,
            'graderid'     => (int) ($row->graderid ?? $graderid),
            'gradername'   => $author ? fullname($author) : '',
            'timecreated'  => (int) $row->timecreated,
        ];
    }
}
