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
 * Shared row-shaping helper for annotation external functions.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\external;

use bbbext_advgrd\local\annotations;
use context_module;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shapes a stored annotation row into the array form returned by add_annotation and
 * list_annotations, and declares the corresponding external_single_structure. Centralised so
 * the body-rewrite + author-fullname lookup logic stays in one place.
 */
class shaper {
    /**
     * Render a stored row for the JS client.
     *
     * @param \stdClass      $row
     * @param context_module $context
     * @return array
     */
    public static function shape_row(\stdClass $row, context_module $context): array {
        global $DB;
        $author = $row->graderid
            ? $DB->get_record('user', ['id' => $row->graderid], 'id, firstname, lastname')
            : null;

        $renderedbody = file_rewrite_pluginfile_urls(
            $row->body,
            'pluginfile.php',
            $context->id,
            'bbbext_advgrd',
            annotations::FILEAREA,
            $row->id
        );

        return [
            'id'           => (int) $row->id,
            'timestampms'  => (int) $row->timestampms,
            'commenttype'  => $row->commenttype,
            'body'         => $renderedbody,
            'bodyformat'   => (int) $row->bodyformat,
            'graderid'     => (int) ($row->graderid ?? 0),
            'gradername'   => $author ? fullname($author) : '',
            'timecreated'  => (int) $row->timecreated,
            'timemodified' => (int) $row->timemodified,
        ];
    }

    /**
     * Return-shape declaration shared by add + list endpoints.
     *
     * @return external_single_structure
     */
    public static function row_structure(): external_single_structure {
        return new external_single_structure([
            'id'           => new external_value(PARAM_INT, 'Annotation id'),
            'timestampms'  => new external_value(PARAM_INT, 'Anchor position in ms'),
            'commenttype'  => new external_value(PARAM_ALPHA, 'Category key'),
            'body'         => new external_value(PARAM_RAW, 'Rendered HTML body (pluginfile URLs resolved)'),
            'bodyformat'   => new external_value(PARAM_INT, 'Text format'),
            'graderid'     => new external_value(PARAM_INT, 'Author id (0 if anonymised)'),
            'gradername'   => new external_value(PARAM_TEXT, 'Author full name'),
            'timecreated'  => new external_value(PARAM_INT, 'Creation timestamp'),
            'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
        ]);
    }
}
