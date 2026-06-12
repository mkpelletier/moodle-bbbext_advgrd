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
 * Public callbacks for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Declare the gradable areas this plugin owns. Discovered by core grading_manager.
 *
 * @return array areaname => human label
 */
function bbbext_advgrd_grading_areas_list(): array {
    return [
        'participation' => get_string('gradingarea_participation', 'bbbext_advgrd'),
    ];
}

/**
 * Serve files from the bbbext_advgrd/comment filearea (audio + image attached to annotation
 * bodies). Discoverable by Moodle's file_pluginfile router.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context  $context
 * @param string   $filearea
 * @param array    $args [itemid, ...path, filename]
 * @param bool     $forcedownload
 * @param array    $options
 * @return bool
 */
function bbbext_advgrd_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }
    if ($filearea !== \bbbext_advgrd\local\annotations::FILEAREA) {
        return false;
    }
    require_login($course, false, $cm);
    require_capability('bbbext/advgrd:grade', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'bbbext_advgrd', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
