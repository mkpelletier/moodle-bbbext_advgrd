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
 * CRUD service for the grader comment library.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use moodle_exception;

/**
 * Reusable comment-library helper. Two scopes per row:
 *   - personal  (courseid = 0): visible only to the author.
 *   - course    (courseid > 0): visible to anyone with bbbext/advgrd:grade in that course.
 *
 * Mirrors assignsubmission_ytsubmission's comment-library shape so graders learn one
 * mental model. Bodies are stored as raw HTML; we don't run @@PLUGINFILE@@ rewrites
 * here because library entries don't carry file attachments - they're text snippets the
 * teacher reuses, not media payloads.
 */
class comment_library {
    /**
     * Fetch a user's library entries (personal) and the course-scoped shared entries.
     *
     * @param int $userid
     * @param int $courseid 0 to skip the shared section.
     * @return array{personal: \stdClass[], shared: \stdClass[]}
     */
    public static function fetch(int $userid, int $courseid): array {
        global $DB;
        $personal = $DB->get_records(
            'bbbext_advgrd_comlib',
            ['userid' => $userid, 'courseid' => 0],
            'sortorder ASC, id ASC'
        );
        $shared = [];
        if ($courseid > 0) {
            $shared = $DB->get_records(
                'bbbext_advgrd_comlib',
                ['courseid' => $courseid],
                'sortorder ASC, id ASC'
            );
        }
        return ['personal' => array_values($personal), 'shared' => array_values($shared)];
    }

    /**
     * Persist a library entry. If $existingid is non-zero we update; otherwise insert.
     * Updates only succeed when the row belongs to the caller - graders can edit their
     * own course-shared entries but not someone else's.
     *
     * @param int    $userid
     * @param int    $courseid 0 = personal, course id = shared at that course.
     * @param string $commenttext HTML body.
     * @param string $commenttype One of annotations::CATEGORIES.
     * @param int    $existingid 0 for insert.
     * @return \stdClass The persisted row.
     */
    public static function save(int $userid, int $courseid, string $commenttext, string $commenttype, int $existingid): \stdClass {
        global $DB;
        annotations::guard_category($commenttype);
        $plain = trim(strip_tags($commenttext));
        if ($plain === '') {
            throw new moodle_exception('annotation_emptybody', 'bbbext_advgrd');
        }

        $now = time();
        if ($existingid > 0) {
            $row = $DB->get_record('bbbext_advgrd_comlib', ['id' => $existingid], '*', MUST_EXIST);
            if ((int) $row->userid !== $userid) {
                throw new moodle_exception('nopermissions', 'error', '', 'edit library item');
            }
            $row->commenttext = $commenttext;
            $row->commenttype = $commenttype;
            $row->courseid = $courseid;
            $row->timemodified = $now;
            $DB->update_record('bbbext_advgrd_comlib', $row);
            return $row;
        }

        $row = (object) [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'commenttext'  => $commenttext,
            'commenttype'  => $commenttype,
            'sortorder'    => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $row->id = $DB->insert_record('bbbext_advgrd_comlib', $row);
        return $row;
    }

    /**
     * Delete a library entry owned by $userid. No-ops on a missing row, throws on a row
     * owned by someone else.
     *
     * @param int $itemid
     * @param int $userid
     * @return void
     */
    public static function delete(int $itemid, int $userid): void {
        global $DB;
        $row = $DB->get_record('bbbext_advgrd_comlib', ['id' => $itemid]);
        if (!$row) {
            return;
        }
        if ((int) $row->userid !== $userid) {
            throw new moodle_exception('nopermissions', 'error', '', 'delete library item');
        }
        $DB->delete_records('bbbext_advgrd_comlib', ['id' => $itemid]);
    }
}
