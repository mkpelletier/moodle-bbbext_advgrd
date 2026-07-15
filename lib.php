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
/**
 * Render the bbbext_advgrd recording-annotation overlay for embedding in a host page (e.g.
 * local_unifiedgrader's preview pane). Returns null when the activity isn't a BBB instance,
 * the caller lacks the grading capability, or the activity has no recordings.
 *
 * Other plugins call this without depending on bbbext_advgrd: `function_exists()` gates it,
 * so the integration degrades cleanly when bbbext_advgrd isn't installed.
 *
 * @param int         $cmid             Course-module id of the BBB activity.
 * @param int         $userid           Target student id.
 * @param string|null $recordingidparam Active recording id (URL param); null picks the first.
 * @return string|null Overlay HTML, or null if nothing to render.
 */
function bbbext_advgrd_render_overlay(int $cmid, int $userid, ?string $recordingidparam = null): ?string {
    global $DB, $USER;
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $cmid, 0, false);
    if (!$cm) {
        return null;
    }
    $context = context_module::instance($cm->id);

    // Auto-detect the render mode: when the caller is viewing their OWN feedback we render
    // the read-only student overlay (no editor, no library, no delete buttons). When they're
    // viewing someone else's, they need the grader capability for the full authoring UI.
    $mode = \bbbext_advgrd\local\overlay::MODE_GRADER;
    if ((int) $userid === (int) $USER->id && has_capability('bbbext/advgrd:viewownreport', $context)) {
        $mode = \bbbext_advgrd\local\overlay::MODE_STUDENT;
    } else if (!has_capability('bbbext/advgrd:grade', $context)) {
        return null;
    }

    $user = $DB->get_record('user', ['id' => $userid]);
    if (!$user) {
        return null;
    }
    $html = \bbbext_advgrd\local\overlay::render(
        (int) $cm->instance,
        $userid,
        $recordingidparam,
        $context,
        $user,
        $mode
    );
    return $html !== '' ? $html : null;
}

/**
 * BBB recording ids that carry annotation feedback for a given user in an activity.
 *
 * A public, dependency-free entry point (gated by function_exists() on the caller
 * side, like bbbext_advgrd_render_overlay) so hosts such as local_unifiedgrader can
 * surface a student's feedback recordings even when BBB's group filter would hide
 * them. Returns an empty array when the activity isn't a BBB instance.
 *
 * @param int $cmid Course-module id of the BBB activity.
 * @param int $userid Target (addressed) user id.
 * @return string[] Distinct recordingid strings (possibly empty).
 */
function bbbext_advgrd_recording_ids_with_feedback(int $cmid, int $userid): array {
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $cmid, 0, false);
    if (!$cm) {
        return [];
    }
    return \bbbext_advgrd\local\annotations::recording_ids_for_user((int) $cm->instance, $userid);
}

/**
 * Serve files from the bbbext_advgrd/comment filearea (audio + image attached to annotation
 * bodies). Discoverable by Moodle's file_pluginfile router. Graders get any file in the
 * activity; students may only stream files attached to annotations addressed to them.
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
    global $DB, $USER;
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }
    if ($filearea !== \bbbext_advgrd\local\annotations::FILEAREA) {
        return false;
    }
    require_login($course, false, $cm);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    // Access control: graders can read any file in the activity; students can read only
    // files attached to comments addressed to them. The itemid is the annotation row id, so
    // a single lookup tells us the addressed user.
    if (!has_capability('bbbext/advgrd:grade', $context)) {
        if (!has_capability('bbbext/advgrd:viewownreport', $context)) {
            return false;
        }
        $targetuserid = $DB->get_field('bbbext_advgrd_annotation', 'targetuserid', ['id' => $itemid]);
        if (!$targetuserid || (int) $targetuserid !== (int) $USER->id) {
            return false;
        }
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'bbbext_advgrd', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
