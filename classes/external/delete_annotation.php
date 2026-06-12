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
 * External function: delete an annotation row and its attached files.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\external;

use bbbext_advgrd\local\annotations;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Removes one annotation. Capability gate: bbbext/advgrd:grade in the activity context. v0.2
 * keeps it deliberately simple - any grader can delete any annotation in the activity, same
 * policy as the existing rubric-grade flow.
 */
class delete_annotation extends external_api {
    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Annotation id'),
        ]);
    }

    /**
     * Delete.
     *
     * @param int $id
     * @return array
     */
    public static function execute(int $id): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        $row = $DB->get_record('bbbext_advgrd_annotation', ['id' => $params['id']]);
        if (!$row) {
            throw new moodle_exception('invalidrecord', 'error');
        }
        $context = annotations::context_for_annotation($row);
        self::validate_context($context);
        require_capability('bbbext/advgrd:grade', $context);

        annotations::delete($params['id']);
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
