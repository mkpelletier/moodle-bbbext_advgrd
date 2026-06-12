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
 * Privacy subsystem provider for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Declares the per-user data this plugin stores and how to export, delete, and discover it.
 *
 * The plugin owns five tables:
 *   - bbbext_advgrd_config       — per-activity setup, no per-user data.
 *   - bbbext_advgrd_metric_map   — teacher-defined criterion/metric mappings, no per-user data.
 *   - bbbext_advgrd_grade        — per-user grade + frozen engagement evidence (PII).
 *   - bbbext_advgrd_annotation   — per-(recording, student) teacher feedback comments (PII).
 *   - bbbext_advgrd_rec_probe    — cached server-side recording probes, no per-user data.
 *
 * Plus the bbbext_advgrd/comment filearea (audio/image attached to annotation bodies).
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the per-user data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'bbbext_advgrd_grade',
            [
                'userid'     => 'privacy:metadata:bbbext_advgrd_grade:userid',
                'graderid'   => 'privacy:metadata:bbbext_advgrd_grade:graderid',
                'rawscore'   => 'privacy:metadata:bbbext_advgrd_grade:rawscore',
                'finalscore' => 'privacy:metadata:bbbext_advgrd_grade:finalscore',
                'evidence'   => 'privacy:metadata:bbbext_advgrd_grade:evidence',
                'timegraded' => 'privacy:metadata:bbbext_advgrd_grade:timegraded',
            ],
            'privacy:metadata:bbbext_advgrd_grade'
        );
        $collection->add_database_table(
            'bbbext_advgrd_annotation',
            [
                'recordingid'  => 'privacy:metadata:bbbext_advgrd_annotation:recordingid',
                'targetuserid' => 'privacy:metadata:bbbext_advgrd_annotation:targetuserid',
                'graderid'     => 'privacy:metadata:bbbext_advgrd_annotation:graderid',
                'body'         => 'privacy:metadata:bbbext_advgrd_annotation:body',
                'commenttype'  => 'privacy:metadata:bbbext_advgrd_annotation:commenttype',
                'timestampms'  => 'privacy:metadata:bbbext_advgrd_annotation:timestampms',
                'timecreated'  => 'privacy:metadata:bbbext_advgrd_annotation:timecreated',
            ],
            'privacy:metadata:bbbext_advgrd_annotation'
        );
        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:bbbext_advgrd_comment'
        );
        return $collection;
    }

    /**
     * Find every BBB activity context that holds a grade row for this user (as graded user
     * or as the rater).
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {bbbext_advgrd_grade} g
                  JOIN {bbbext_advgrd_config} c ON c.id = g.configid
                  JOIN {course_modules} cm ON cm.instance = c.bigbluebuttonbnid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :clmod
                 WHERE g.userid = :userid OR g.graderid = :graderid";
        $contextlist->add_from_sql($sql, [
            'modname'  => 'bigbluebuttonbn',
            'clmod'    => CONTEXT_MODULE,
            'userid'   => $userid,
            'graderid' => $userid,
        ]);

        $annsql = "SELECT ctx.id
                     FROM {bbbext_advgrd_annotation} a
                     JOIN {course_modules} cm ON cm.instance = a.bigbluebuttonbnid
                     JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                     JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :clmod
                    WHERE a.targetuserid = :tuid OR a.graderid = :guid";
        $contextlist->add_from_sql($annsql, [
            'modname' => 'bigbluebuttonbn',
            'clmod'   => CONTEXT_MODULE,
            'tuid'    => $userid,
            'guid'    => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Find every user with a grade row in the given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT g.userid AS uid
                  FROM {bbbext_advgrd_grade} g
                  JOIN {bbbext_advgrd_config} c ON c.id = g.configid
                  JOIN {course_modules} cm ON cm.instance = c.bigbluebuttonbnid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid
                 UNION
                SELECT g.graderid AS uid
                  FROM {bbbext_advgrd_grade} g
                  JOIN {bbbext_advgrd_config} c ON c.id = g.configid
                  JOIN {course_modules} cm ON cm.instance = c.bigbluebuttonbnid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname2
                 WHERE cm.id = :cmid2 AND g.graderid IS NOT NULL";

        $userlist->add_from_sql('uid', $sql, [
            'modname'  => 'bigbluebuttonbn',
            'cmid'     => $context->instanceid,
            'modname2' => 'bigbluebuttonbn',
            'cmid2'    => $context->instanceid,
        ]);

        $annsql = "SELECT a.targetuserid AS uid
                     FROM {bbbext_advgrd_annotation} a
                     JOIN {course_modules} cm ON cm.instance = a.bigbluebuttonbnid
                     JOIN {modules} m ON m.id = cm.module AND m.name = :modname3
                    WHERE cm.id = :cmid3
                    UNION
                   SELECT a.graderid AS uid
                     FROM {bbbext_advgrd_annotation} a
                     JOIN {course_modules} cm ON cm.instance = a.bigbluebuttonbnid
                     JOIN {modules} m ON m.id = cm.module AND m.name = :modname4
                    WHERE cm.id = :cmid4 AND a.graderid IS NOT NULL";
        $userlist->add_from_sql('uid', $annsql, [
            'modname3' => 'bigbluebuttonbn',
            'cmid3'    => $context->instanceid,
            'modname4' => 'bigbluebuttonbn',
            'cmid4'    => $context->instanceid,
        ]);
    }

    /**
     * Export this user's grade rows in each approved context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $subcontext = [get_string('pluginname', 'bbbext_advgrd')];

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('bigbluebuttonbn', $context->instanceid, 0, false);
            if (!$cm) {
                continue;
            }
            $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $cm->instance]);
            if (!$config) {
                continue;
            }
            $rows = $DB->get_records_select(
                'bbbext_advgrd_grade',
                'configid = :configid AND (userid = :userid OR graderid = :graderid)',
                ['configid' => $config->id, 'userid' => $userid, 'graderid' => $userid]
            );
            if (!$rows) {
                continue;
            }
            $exported = [];
            foreach ($rows as $row) {
                $exported[] = (object) [
                    'role'       => ((int) $row->userid === $userid) ? 'graded' : 'grader',
                    'rawscore'   => $row->rawscore !== null ? (float) $row->rawscore : null,
                    'finalscore' => $row->finalscore !== null ? (float) $row->finalscore : null,
                    'evidence'   => $row->evidence,
                    'timegraded' => $row->timegraded ? userdate($row->timegraded) : null,
                ];
            }
            writer::with_context($context)->export_data($subcontext, (object) ['grades' => $exported]);
        }

        // Annotations - exported separately so each row carries its filearea attachments.
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('bigbluebuttonbn', $context->instanceid, 0, false);
            if (!$cm) {
                continue;
            }
            $rows = $DB->get_records_select(
                'bbbext_advgrd_annotation',
                'bigbluebuttonbnid = :bbbid AND (targetuserid = :tuid OR graderid = :guid)',
                ['bbbid' => $cm->instance, 'tuid' => $userid, 'guid' => $userid]
            );
            if (!$rows) {
                continue;
            }
            $exported = [];
            foreach ($rows as $row) {
                $exported[] = (object) [
                    'role'        => ((int) $row->targetuserid === $userid) ? 'addressed' : 'author',
                    'recordingid' => $row->recordingid,
                    'timestampms' => (int) $row->timestampms,
                    'commenttype' => $row->commenttype,
                    'body'        => writer::with_context($context)->rewrite_pluginfile_urls(
                        $subcontext,
                        'bbbext_advgrd',
                        \bbbext_advgrd\local\annotations::FILEAREA,
                        $row->id,
                        $row->body
                    ),
                    'timecreated' => userdate($row->timecreated),
                ];
                writer::with_context($context)->export_area_files(
                    $subcontext,
                    'bbbext_advgrd',
                    \bbbext_advgrd\local\annotations::FILEAREA,
                    $row->id
                );
            }
            writer::with_context($context)->export_data(
                $subcontext,
                (object) ['annotations' => $exported]
            );
        }
    }

    /**
     * Delete all grade rows in a given context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('bigbluebuttonbn', $context->instanceid, 0, false);
        if (!$cm) {
            return;
        }
        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $cm->instance]);
        if (!$config) {
            return;
        }
        $DB->delete_records('bbbext_advgrd_grade', ['configid' => $config->id]);

        // Annotations + their files for this activity.
        $annids = $DB->get_fieldset_select(
            'bbbext_advgrd_annotation',
            'id',
            'bigbluebuttonbnid = :bbbid',
            ['bbbid' => $cm->instance]
        );
        if ($annids) {
            $fs = get_file_storage();
            foreach ($annids as $annid) {
                $fs->delete_area_files(
                    $context->id,
                    'bbbext_advgrd',
                    \bbbext_advgrd\local\annotations::FILEAREA,
                    $annid
                );
            }
            $DB->delete_records('bbbext_advgrd_annotation', ['bigbluebuttonbnid' => $cm->instance]);
        }
    }

    /**
     * Delete the user's grade rows across the approved context list. We delete rows where the
     * user was the graded subject; rows where they were merely the rater are anonymised by
     * nulling graderid (so the gradee's record is preserved as required by audit policy).
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('bigbluebuttonbn', $context->instanceid, 0, false);
            if (!$cm) {
                continue;
            }
            $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $cm->instance]);
            if (!$config) {
                continue;
            }
            $DB->delete_records('bbbext_advgrd_grade', ['configid' => $config->id, 'userid' => $userid]);
            $DB->set_field(
                'bbbext_advgrd_grade',
                'graderid',
                null,
                ['configid' => $config->id, 'graderid' => $userid]
            );

            // Annotations: delete rows where the user was addressed (target), anonymise
            // graderid where they were the author. Audit policy: addressed students get
            // their record erased; teacher-authored content stays but loses authorship.
            $targetannids = $DB->get_fieldset_select(
                'bbbext_advgrd_annotation',
                'id',
                'bigbluebuttonbnid = :bbbid AND targetuserid = :tuid',
                ['bbbid' => $cm->instance, 'tuid' => $userid]
            );
            if ($targetannids) {
                $fs = get_file_storage();
                foreach ($targetannids as $annid) {
                    $fs->delete_area_files(
                        $context->id,
                        'bbbext_advgrd',
                        \bbbext_advgrd\local\annotations::FILEAREA,
                        $annid
                    );
                }
                $DB->delete_records_list('bbbext_advgrd_annotation', 'id', $targetannids);
            }
            $DB->set_field(
                'bbbext_advgrd_annotation',
                'graderid',
                null,
                ['bigbluebuttonbnid' => $cm->instance, 'graderid' => $userid]
            );
        }
    }

    /**
     * Bulk delete for the given userlist in a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('bigbluebuttonbn', $context->instanceid, 0, false);
        if (!$cm) {
            return;
        }
        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $cm->instance]);
        if (!$config) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['configid' => $config->id] + $inparams;
        $DB->delete_records_select('bbbext_advgrd_grade', "configid = :configid AND userid {$insql}", $params);

        // Anonymise rater references for the same user list.
        $DB->execute(
            "UPDATE {bbbext_advgrd_grade}
                SET graderid = NULL
              WHERE configid = :configid AND graderid {$insql}",
            $params
        );

        // Annotations: same audit policy as the single-user delete - addressed-student rows
        // are removed (files too), grader references are anonymised.
        $annparams = ['bbbid' => $cm->instance] + $inparams;
        $targetannids = $DB->get_fieldset_select(
            'bbbext_advgrd_annotation',
            'id',
            "bigbluebuttonbnid = :bbbid AND targetuserid {$insql}",
            $annparams
        );
        if ($targetannids) {
            $fs = get_file_storage();
            foreach ($targetannids as $annid) {
                $fs->delete_area_files(
                    $context->id,
                    'bbbext_advgrd',
                    \bbbext_advgrd\local\annotations::FILEAREA,
                    $annid
                );
            }
            $DB->delete_records_list('bbbext_advgrd_annotation', 'id', $targetannids);
        }
        $DB->execute(
            "UPDATE {bbbext_advgrd_annotation}
                SET graderid = NULL
              WHERE bigbluebuttonbnid = :bbbid AND graderid {$insql}",
            $annparams
        );
    }
}
