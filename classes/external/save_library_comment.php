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
 * External function: persist a library entry (insert or update).
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
 * Save (insert or update) a library entry. Scope is decided by the caller via `scope`:
 *   - 'personal' forces courseid=0
 *   - 'course' uses the activity's course id
 * Course-scope writes don't need a separate capability in 0.2.x - any grader can save
 * shared entries. Same as ytsubmission's policy.
 */
class save_library_comment extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'bbbid'       => new external_value(PARAM_INT, 'BBB instance id; gates access via the activity context'),
            'commenttext' => new external_value(PARAM_RAW, 'Body HTML to save'),
            'commenttype' => new external_value(PARAM_ALPHA, 'Category key'),
            'scope'       => new external_value(PARAM_ALPHA, "'personal' or 'course'"),
            'itemid'      => new external_value(PARAM_INT, 'Existing library entry id; 0 to insert', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int    $bbbid
     * @param string $commenttext
     * @param string $commenttype
     * @param string $scope
     * @param int    $itemid
     * @return array
     */
    public static function execute(int $bbbid, string $commenttext, string $commenttype, string $scope, int $itemid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'bbbid'       => $bbbid,
            'commenttext' => $commenttext,
            'commenttype' => $commenttype,
            'scope'       => $scope,
            'itemid'      => $itemid,
        ]);
        $info = grader::bootstrap($params['bbbid']);
        self::validate_context($info['context']);
        require_capability('bbbext/advgrd:grade', $info['context']);

        $userid = (int) $USER->id;
        $courseid = $params['scope'] === 'course' ? (int) $info['bbb']->course : 0;

        $row = comment_library::save(
            $userid,
            $courseid,
            $params['commenttext'],
            $params['commenttype'],
            $params['itemid']
        );

        return [
            'id'           => (int) $row->id,
            'commenttype'  => $row->commenttype,
            'commenttext'  => (string) $row->commenttext,
            'isowner'      => true,
            'timemodified' => (int) $row->timemodified,
        ];
    }

    /**
     * Return-shape declaration.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'Library entry id'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'Category key'),
            'commenttext'  => new external_value(PARAM_RAW, 'Stored body HTML'),
            'isowner'      => new external_value(PARAM_BOOL, 'Always true for the save returnshape'),
            'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
        ]);
    }
}
