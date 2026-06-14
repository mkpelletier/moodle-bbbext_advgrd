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
 * External function: list the caller's personal + the course-shared library entries.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\external;

use bbbext_advgrd\local\comment_library;
use bbbext_advgrd\local\grader;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns personal + course-shared library entries, each shaped with an `isowner` flag so
 * the client knows when to render the delete button.
 */
class get_library extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bbbid' => new external_value(PARAM_INT, 'BBB instance id; gates access via the activity context'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $bbbid
     * @return array
     */
    public static function execute(int $bbbid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['bbbid' => $bbbid]);
        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        $userid = (int) $USER->id;
        $courseid = (int) $info['bbb']->course;
        $data = comment_library::fetch($userid, $courseid);

        $shape = function (\stdClass $row) use ($userid) {
            return [
                'id'           => (int) $row->id,
                'commenttype'  => $row->commenttype,
                'commenttext'  => (string) $row->commenttext,
                'isowner'      => (int) $row->userid === $userid,
                'timemodified' => (int) $row->timemodified,
            ];
        };

        return [
            'personal' => array_map($shape, $data['personal']),
            'shared'   => array_map($shape, $data['shared']),
        ];
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $entry = new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'Library entry id'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'Category key'),
            'commenttext'  => new external_value(PARAM_RAW, 'Stored body HTML'),
            'isowner'      => new external_value(PARAM_BOOL, 'True when the caller owns the entry'),
            'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
        ]);
        return new external_single_structure([
            'personal' => new external_multiple_structure($entry),
            'shared'   => new external_multiple_structure($entry),
        ]);
    }
}
