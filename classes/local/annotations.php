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
 * CRUD service for timestamped feedback annotations on BBB recordings.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use context_module;
use moodle_exception;
use stored_file;

/**
 * Stateless helpers around the bbbext_advgrd_annotation table and its
 * companion audio filearea (component=bbbext_advgrd, filearea=audiocomment,
 * itemid=annotation.id).
 *
 * Comments are scoped to one (recording, addressed-student) pair. The
 * graderid field is the author; it is nullable so that privacy-driven
 * deletion of a grader's account can anonymise the row without losing the
 * addressed student's feedback record.
 *
 * Categories mirror assignsubmission_ytsubmission's palette (general,
 * praise, correction, suggestion, question) so the visual language carries
 * between the two tools even though their tables are not shared.
 */
class annotations {
    /** @var string[] Allowed category values for new annotations. */
    public const CATEGORIES = ['general', 'praise', 'correction', 'suggestion', 'question'];

    /** @var string Audio comments live in this filearea, keyed by annotation.id. */
    public const AUDIO_FILEAREA = 'audiocomment';

    /** @var int Hard cap on audio comment length, in seconds (matches AMD recorder). */
    public const AUDIO_MAX_SECONDS = 300;

    /**
     * Create a text annotation.
     *
     * @param int    $bbbid BBB instance id.
     * @param string $recordingid BBB's recording id (string, max 128 chars).
     * @param int    $targetuserid The student the comment is addressed to.
     * @param int    $graderid The author (teacher) recording the comment.
     * @param int    $timestampms Recording position, milliseconds.
     * @param string $body Comment text.
     * @param string $commenttype One of {@see CATEGORIES}.
     * @return \stdClass The persisted row.
     */
    public static function create_text(
        int $bbbid,
        string $recordingid,
        int $targetuserid,
        int $graderid,
        int $timestampms,
        string $body,
        string $commenttype
    ): \stdClass {
        global $DB;
        self::guard_category($commenttype);
        $now = time();
        $row = (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid'       => $recordingid,
            'targetuserid'      => $targetuserid,
            'graderid'          => $graderid,
            'kind'              => 'text',
            'timestampms'       => max(0, $timestampms),
            'body'              => trim($body),
            'commenttype'       => $commenttype,
            'timecreated'       => $now,
            'timemodified'      => $now,
        ];
        if ($row->body === '') {
            throw new moodle_exception('annotation_emptybody', 'bbbext_advgrd');
        }
        $row->id = $DB->insert_record('bbbext_advgrd_annotation', $row);
        return $row;
    }

    /**
     * Create an audio annotation. The caller has already uploaded the audio file via
     * file_save_draft_area_files() into the AUDIO_FILEAREA keyed by the new row id.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @param int    $graderid
     * @param int    $timestampms
     * @param string $caption Optional short text shown next to the audio play button.
     * @param string $commenttype
     * @return \stdClass The persisted row (with id) — caller can now attach the file by id.
     */
    public static function create_audio(
        int $bbbid,
        string $recordingid,
        int $targetuserid,
        int $graderid,
        int $timestampms,
        string $caption,
        string $commenttype
    ): \stdClass {
        global $DB;
        self::guard_category($commenttype);
        $now = time();
        $row = (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid'       => $recordingid,
            'targetuserid'      => $targetuserid,
            'graderid'          => $graderid,
            'kind'              => 'audio',
            'timestampms'       => max(0, $timestampms),
            'body'              => trim($caption),
            'commenttype'       => $commenttype,
            'timecreated'       => $now,
            'timemodified'      => $now,
        ];
        $row->id = $DB->insert_record('bbbext_advgrd_annotation', $row);
        return $row;
    }

    /**
     * Delete an annotation and any audio file attached to it. Caller must check the
     * bbbext/advgrd:grade capability before invoking — this method does not re-check.
     *
     * @param int $annotationid
     * @return void
     */
    public static function delete(int $annotationid): void {
        global $DB;
        $row = $DB->get_record('bbbext_advgrd_annotation', ['id' => $annotationid], '*', MUST_EXIST);
        if ($row->kind === 'audio') {
            $context = self::context_for_annotation($row);
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'bbbext_advgrd', self::AUDIO_FILEAREA, $row->id);
        }
        $DB->delete_records('bbbext_advgrd_annotation', ['id' => $annotationid]);
    }

    /**
     * Fetch every annotation for (recording, target student), ordered by timestamp.
     * Returned rows are suitable for handing straight to the timeline JS.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $targetuserid
     * @return \stdClass[]
     */
    public static function list_for_review(int $bbbid, string $recordingid, int $targetuserid): array {
        global $DB;
        $sql = "SELECT *
                  FROM {bbbext_advgrd_annotation}
                 WHERE bigbluebuttonbnid = :bbbid
                   AND recordingid = :rid
                   AND targetuserid = :uid
              ORDER BY timestampms ASC, id ASC";
        return array_values($DB->get_records_sql($sql, [
            'bbbid' => $bbbid,
            'rid'   => $recordingid,
            'uid'   => $targetuserid,
        ]));
    }

    /**
     * Fetch every annotation a given grader has authored on a recording (across students).
     * Useful for the teacher's own review/audit; not used by students.
     *
     * @param int    $bbbid
     * @param string $recordingid
     * @param int    $graderid
     * @return \stdClass[]
     */
    public static function list_for_grader(int $bbbid, string $recordingid, int $graderid): array {
        global $DB;
        $sql = "SELECT *
                  FROM {bbbext_advgrd_annotation}
                 WHERE bigbluebuttonbnid = :bbbid
                   AND recordingid = :rid
                   AND graderid = :gid
              ORDER BY timestampms ASC, id ASC";
        return array_values($DB->get_records_sql($sql, [
            'bbbid' => $bbbid,
            'rid'   => $recordingid,
            'gid'   => $graderid,
        ]));
    }

    /**
     * Fetch the audio file attached to an annotation (or null if none / not audio).
     *
     * @param \stdClass $annotation
     * @return stored_file|null
     */
    public static function get_audio_file(\stdClass $annotation): ?stored_file {
        if ($annotation->kind !== 'audio') {
            return null;
        }
        $context = self::context_for_annotation($annotation);
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'bbbext_advgrd',
            self::AUDIO_FILEAREA,
            $annotation->id,
            'itemid, filepath, filename',
            false
        );
        foreach ($files as $file) {
            return $file;
        }
        return null;
    }

    /**
     * Resolve the context module that owns an annotation (via its BBB instance).
     *
     * @param \stdClass $annotation
     * @return context_module
     */
    public static function context_for_annotation(\stdClass $annotation): context_module {
        $cm = get_coursemodule_from_instance(
            'bigbluebuttonbn',
            $annotation->bigbluebuttonbnid,
            0,
            false,
            MUST_EXIST
        );
        return context_module::instance($cm->id);
    }

    /**
     * Throw if a caller passes a category that isn't one of the canonical five. Centralised
     * so the validation message is consistent across entry points.
     *
     * @param string $commenttype
     * @return void
     */
    protected static function guard_category(string $commenttype): void {
        if (!in_array($commenttype, self::CATEGORIES, true)) {
            throw new moodle_exception(
                'annotation_badcategory',
                'bbbext_advgrd',
                '',
                $commenttype
            );
        }
    }
}
