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
 * External function: delete a library entry owned by the caller.
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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Delete a library entry. The service helper rejects deletes that aren't owned by the
 * caller, so course-shared entries can only be removed by their author.
 */
class delete_library_comment extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bbbid' => new external_value(PARAM_INT, 'BBB instance id; gates access via the activity context'),
            'id'    => new external_value(PARAM_INT, 'Library entry id'),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $bbbid
     * @param int $id
     * @return array
     */
    public static function execute(int $bbbid, int $id): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['bbbid' => $bbbid, 'id' => $id]);
        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        comment_library::delete($params['id'], (int) $USER->id);
        return ['status' => 'ok'];
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok on success'),
        ]);
    }
}
