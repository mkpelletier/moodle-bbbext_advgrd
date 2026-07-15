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
 * CRUD service for BBB recording annotations.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use context_module;
use moodle_exception;

/**
 * Stateless CRUD helper for the bbbext_advgrd_annotation table.
 *
 * The body is a rich-text HTML blob produced by the Atto/TinyMCE editor on the grading page.
 * Audio recordings the teacher embeds via the editor's file picker land in a draft area, and
 * file_save_draft_area_files() carries them into the bbbext_advgrd/comment filearea keyed by
 * the new row id - the same pattern Moodle uses everywhere a rich-text body holds attachments.
 */
class annotations {
    /** @var string[] Allowed comment-type values, matched to ytsubmission's palette. */
    public const CATEGORIES = ['general', 'praise', 'correction', 'suggestion', 'question'];

    /** @var string Filearea for files (audio, image, video) embedded in a comment body. */
    public const FILEAREA = 'comment';

    /**
     * Create an annotation from editor data: persists the row, then saves draft files and
     * rewrites @@PLUGINFILE@@ tokens in the body to permanent URLs keyed by the new row id.
     *
     * @param int    $bbbid
     * @param string $recordingid BBB recording id string.
     * @param int    $targetuserid The student the comment is addressed to.
     * @param int    $graderid The teacher authoring the comment.
     * @param int    $timestampms Recording position in milliseconds.
     * @param string $bodyhtml Raw HTML from the editor (may include @@PLUGINFILE@@ tokens).
     * @param int    $bodyformat Moodle text-format constant (typically FORMAT_HTML).
     * @param string $commenttype One of {@see CATEGORIES}.
     * @param int    $draftitemid Draft itemid the editor used for embedded files.
     * @param context_module $context Activity context (target for the permanent filearea).
     * @return \stdClass The persisted row, with body rewritten.
     */
    public static function create(
        int $bbbid,
        string $recordingid,
        int $targetuserid,
        int $graderid,
        int $timestampms,
        string $bodyhtml,
        int $bodyformat,
        string $commenttype,
        int $draftitemid,
        context_module $context
    ): \stdClass {
        global $DB;
        self::guard_category($commenttype);

        $stripped = trim(strip_tags($bodyhtml, '<audio><video><img><source>'));
        if ($stripped === '') {
            throw new moodle_exception('annotation_emptybody', 'bbbext_advgrd');
        }

        $now = time();
        $row = (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid'       => $recordingid,
            'targetuserid'      => $targetuserid,
            'graderid'          => $graderid,
            'timestampms'       => max(0, $timestampms),
            'body'              => '',
            'bodyformat'        => $bodyformat,
            'commenttype'       => $commenttype,
            'timecreated'       => $now,
            'timemodified'      => $now,
        ];
        $row->id = $DB->insert_record('bbbext_advgrd_annotation', $row);

        // Carry editor draft files into the permanent filearea keyed by the new row id, then
        // rewrite @@PLUGINFILE@@ tokens so the body links resolve via the pluginfile callback.
        $rewritten = file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'bbbext_advgrd',
            self::FILEAREA,
            $row->id,
            self::editor_options($context),
            $bodyhtml
        );
        $DB->set_field('bbbext_advgrd_annotation', 'body', $rewritten, ['id' => $row->id]);
        $row->body = $rewritten;
        return $row;
    }

    /**
     * Update an existing annotation - timestamp, category, or body. Caller has already
     * checked the bbbext/advgrd:grade capability on the activity context.
     *
     * @param int            $annotationid
     * @param int            $timestampms
     * @param string         $bodyhtml
     * @param int            $bodyformat
     * @param string         $commenttype
     * @param int            $draftitemid
     * @param context_module $context
     * @return \stdClass
     */
    public static function update(
        int $annotationid,
        int $timestampms,
        string $bodyhtml,
        int $bodyformat,
        string $commenttype,
        int $draftitemid,
        context_module $context
    ): \stdClass {
        global $DB;
        self::guard_category($commenttype);
        $row = $DB->get_record('bbbext_advgrd_annotation', ['id' => $annotationid], '*', MUST_EXIST);

        $rewritten = file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'bbbext_advgrd',
            self::FILEAREA,
            $row->id,
            self::editor_options($context),
            $bodyhtml
        );

        $row->timestampms = max(0, $timestampms);
        $row->body = $rewritten;
        $row->bodyformat = $bodyformat;
        $row->commenttype = $commenttype;
        $row->timemodified = time();
        $DB->update_record('bbbext_advgrd_annotation', $row);
        return $row;
    }

    /**
     * Delete an annotation row and every file in its slot of the comment filearea. Caller
     * has already checked the bbbext/advgrd:grade capability on the activity context.
     *
     * @param int $annotationid
     * @return void
     */
    public static function delete(int $annotationid): void {
        global $DB;
        $row = $DB->get_record('bbbext_advgrd_annotation', ['id' => $annotationid], '*', MUST_EXIST);
        $context = self::context_for_annotation($row);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'bbbext_advgrd', self::FILEAREA, $row->id);
        $DB->delete_records('bbbext_advgrd_annotation', ['id' => $annotationid]);
    }

    /**
     * List annotations for a (bbbid, recordingid, targetuserid) tuple, ordered by
     * timestampms. The view consumer is the grading page's comment list and timeline.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @return \stdClass[]
     */
    public static function list_for_review(int $bbbid, string $recordingid, int $targetuserid): array {
        global $DB;
        return $DB->get_records(
            'bbbext_advgrd_annotation',
            [
                'bigbluebuttonbnid' => $bbbid,
                'recordingid'       => $recordingid,
                'targetuserid'      => $targetuserid,
            ],
            'timestampms ASC, id ASC'
        );
    }

    /**
     * Distinct BBB recording ids that carry at least one annotation addressed to
     * a given user in a given activity.
     *
     * Used to rescue a student's feedback from BBB's group-based recording
     * visibility: in a separate-groups activity the recording a comment lives on
     * may be hidden from the student (wrong group, or groupid 0), so hosts look up
     * the recordings a student actually has feedback on and surface them by id
     * regardless of the group filter.
     *
     * @param int $bbbid The bigbluebuttonbn instance id.
     * @param int $userid The addressed (target) user id.
     * @return string[] Distinct recordingid strings (may be empty).
     */
    public static function recording_ids_for_user(int $bbbid, int $userid): array {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'bbbext_advgrd_annotation',
            'DISTINCT recordingid',
            'bigbluebuttonbnid = :bbbid AND targetuserid = :userid',
            ['bbbid' => $bbbid, 'userid' => $userid]
        );
        return array_values(array_filter($ids, fn($rid) => (string) $rid !== ''));
    }

    /**
     * Resolve the activity context for a given annotation row.
     *
     * @param \stdClass $row
     * @return context_module
     */
    public static function context_for_annotation(\stdClass $row): context_module {
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', (int) $row->bigbluebuttonbnid, 0, false, MUST_EXIST);
        return context_module::instance($cm->id);
    }

    /**
     * Editor options shared by create + update so the same restrictions apply both ways.
     *
     * @param context_module $context
     * @return array
     */
    public static function editor_options(context_module $context): array {
        global $CFG;
        // EDITOR_UNLIMITED_FILES is defined in lib/formslib.php (= -1). The page-rendering
        // path loads it via the rubric mform; the AJAX service router does not, and this
        // method is called from both paths. Hardcoding -1 avoids a conditional require()
        // and keeps the helper context-agnostic.
        return [
            'maxfiles'  => -1,
            'maxbytes'  => $CFG->maxbytes,
            'context'   => $context,
            'noclean'   => false,
            'subdirs'   => 0,
            'trusttext' => false,
        ];
    }

    /**
     * Validate a comment-type string. Throws if not in CATEGORIES.
     *
     * @param string $commenttype
     * @return void
     */
    public static function guard_category(string $commenttype): void {
        if (!in_array($commenttype, self::CATEGORIES, true)) {
            throw new moodle_exception('annotation_badcategory', 'bbbext_advgrd', '', $commenttype);
        }
    }
}
