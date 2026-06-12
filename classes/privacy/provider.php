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
 * The plugin owns five tables; per-user data lives in two of them:
 *   - bbbext_advgrd_grade        — per-user grade + frozen engagement evidence (PII).
 *   - bbbext_advgrd_annotation   — teacher-authored timestamped feedback on a BBB recording
 *                                  addressed to one student (PII; audio bodies are voice
 *                                  recordings of staff, biometric-adjacent).
 *
 * The remaining three (config, metric_map, rec_probe) are activity-level configuration with
 * no per-user data and are not declared here.
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
                'targetuserid' => 'privacy:metadata:bbbext_advgrd_annotation:targetuserid',
                'graderid'     => 'privacy:metadata:bbbext_advgrd_annotation:graderid',
                'kind'         => 'privacy:metadata:bbbext_advgrd_annotation:kind',
                'timestampms'  => 'privacy:metadata:bbbext_advgrd_annotation:timestampms',
                'body'         => 'privacy:metadata:bbbext_advgrd_annotation:body',
                'commenttype'  => 'privacy:metadata:bbbext_advgrd_annotation:commenttype',
                'recordingid'  => 'privacy:metadata:bbbext_advgrd_annotation:recordingid',
                'timecreated'  => 'privacy:metadata:bbbext_advgrd_annotation:timecreated',
            ],
            'privacy:metadata:bbbext_advgrd_annotation'
        );
        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:bbbext_advgrd_audiocomment'
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
        $gradesql = "SELECT ctx.id
                       FROM {bbbext_advgrd_grade} g
                       JOIN {bbbext_advgrd_config} c ON c.id = g.configid
                       JOIN {course_modules} cm ON cm.instance = c.bigbluebuttonbnid
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :clmod
                      WHERE g.userid = :userid OR g.graderid = :graderid";
        $contextlist->add_from_sql($gradesql, [
            'modname'  => 'bigbluebuttonbn',
            'clmod'    => CONTEXT_MODULE,
            'userid'   => $userid,
            'graderid' => $userid,
        ]);
        $annotsql = "SELECT ctx.id
                       FROM {bbbext_advgrd_annotation} a
                       JOIN {course_modules} cm ON cm.instance = a.bigbluebuttonbnid
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :clmod
                      WHERE a.targetuserid = :targetuserid OR a.graderid = :graderid";
        $contextlist->add_from_sql($annotsql, [
            'modname'      => 'bigbluebuttonbn',
            'clmod'        => CONTEXT_MODULE,
            'targetuserid' => $userid,
            'graderid'     => $userid,
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
                 WHERE cm.id = :cmid2 AND g.graderid IS NOT NULL
                 UNION
                SELECT a.targetuserid AS uid
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

        $userlist->add_from_sql('uid', $sql, [
            'modname'  => 'bigbluebuttonbn',
            'cmid'     => $context->instanceid,
            'modname2' => 'bigbluebuttonbn',
            'cmid2'    => $context->instanceid,
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

            // Annotations addressed to or authored by this user, in the same activity context.
            $annotations = $DB->get_records_select(
                'bbbext_advgrd_annotation',
                'bigbluebuttonbnid = :bbb AND (targetuserid = :targetuserid OR graderid = :graderid)',
                ['bbb' => $cm->instance, 'targetuserid' => $userid, 'graderid' => $userid]
            );
            if ($annotations) {
                $exportedannots = [];
                $fs = get_file_storage();
                foreach ($annotations as $annot) {
                    $exportedannots[] = (object) [
                        'role'        => ((int) $annot->targetuserid === $userid) ? 'addressed' : 'author',
                        'kind'        => $annot->kind,
                        'commenttype' => $annot->commenttype,
                        'timestampms' => (int) $annot->timestampms,
                        'body'        => $annot->body,
                        'recordingid' => $annot->recordingid,
                        'timecreated' => $annot->timecreated ? userdate($annot->timecreated) : null,
                    ];
                    if ($annot->kind === 'audio') {
                        // Export the audio binary alongside the row.
                        foreach (
                            $fs->get_area_files(
                                $context->id,
                                'bbbext_advgrd',
                                \bbbext_advgrd\local\annotations::AUDIO_FILEAREA,
                                $annot->id,
                                'itemid, filepath, filename',
                                false
                            ) as $file
                        ) {
                            writer::with_context($context)->export_file(
                                array_merge($subcontext, ['audio']),
                                $file
                            );
                        }
                    }
                }
                writer::with_context($context)->export_data(
                    $subcontext,
                    (object) ['annotations' => $exportedannots]
                );
            }
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
        if ($config) {
            $DB->delete_records('bbbext_advgrd_grade', ['configid' => $config->id]);
        }
        // Annotations and their audio files.
        $fs = get_file_storage();
        $fs->delete_area_files(
            $context->id,
            'bbbext_advgrd',
            \bbbext_advgrd\local\annotations::AUDIO_FILEAREA
        );
        $DB->delete_records('bbbext_advgrd_annotation', ['bigbluebuttonbnid' => $cm->instance]);
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

            // Annotations: delete rows addressed to the user (and their audio files); anonymise
            // rows the user only authored (so the gradee's record is preserved).
            $addressed = $DB->get_records(
                'bbbext_advgrd_annotation',
                ['bigbluebuttonbnid' => $cm->instance, 'targetuserid' => $userid]
            );
            $fs = get_file_storage();
            foreach ($addressed as $row) {
                if ($row->kind === 'audio') {
                    $fs->delete_area_files(
                        $context->id,
                        'bbbext_advgrd',
                        \bbbext_advgrd\local\annotations::AUDIO_FILEAREA,
                        $row->id
                    );
                }
            }
            $DB->delete_records(
                'bbbext_advgrd_annotation',
                ['bigbluebuttonbnid' => $cm->instance, 'targetuserid' => $userid]
            );
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
        if ($config) {
            $params = ['configid' => $config->id] + $inparams;
            $DB->delete_records_select(
                'bbbext_advgrd_grade',
                "configid = :configid AND userid {$insql}",
                $params
            );
            $DB->execute(
                "UPDATE {bbbext_advgrd_grade}
                    SET graderid = NULL
                  WHERE configid = :configid AND graderid {$insql}",
                $params
            );
        }

        // Annotations: delete those addressed to listed users (with audio files); anonymise
        // those they only authored.
        $aparams = ['bbb' => $cm->instance] + $inparams;
        $addressed = $DB->get_records_select(
            'bbbext_advgrd_annotation',
            "bigbluebuttonbnid = :bbb AND targetuserid {$insql}",
            $aparams
        );
        $fs = get_file_storage();
        foreach ($addressed as $row) {
            if ($row->kind === 'audio') {
                $fs->delete_area_files(
                    $context->id,
                    'bbbext_advgrd',
                    \bbbext_advgrd\local\annotations::AUDIO_FILEAREA,
                    $row->id
                );
            }
        }
        $DB->delete_records_select(
            'bbbext_advgrd_annotation',
            "bigbluebuttonbnid = :bbb AND targetuserid {$insql}",
            $aparams
        );
        $DB->execute(
            "UPDATE {bbbext_advgrd_annotation}
                SET graderid = NULL
              WHERE bigbluebuttonbnid = :bbb AND graderid {$insql}",
            $aparams
        );
    }
}
