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
 * Test data generator for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;

/**
 * Generator. Accessed via $dg->get_plugin_generator('bbbext_advgrd').
 */
class bbbext_advgrd_generator extends component_generator_base {
    /**
     * Insert (or update) a bbbext_advgrd_config row for a BBB instance.
     *
     * @param int $bbbid bigbluebuttonbn.id
     * @param array $opts gradingmethod (rubric|guide|none), scoremode (composite|analytic), passthroughtogradebook (bool)
     * @return stdClass The persisted config row.
     */
    public function create_config(int $bbbid, array $opts = []): stdClass {
        global $DB;

        $now = time();
        $row = (object) array_merge([
            'bigbluebuttonbnid'      => $bbbid,
            'gradingmethod'          => 'rubric',
            'scoremode'              => 'composite',
            'passthroughtogradebook' => 1,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ], $opts);

        $existing = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $bbbid]);
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('bbbext_advgrd_config', $row);
        } else {
            $row->id = $DB->insert_record('bbbext_advgrd_config', $row);
        }
        return $DB->get_record('bbbext_advgrd_config', ['id' => $row->id], '*', MUST_EXIST);
    }

    /**
     * Import a starter template (default: 'coi') into the BBB activity's grading area.
     *
     * @param int $bbbid
     * @param string $templateid
     */
    public function import_template(int $bbbid, string $templateid = 'coi'): void {
        grader::import_template($bbbid, $templateid);
    }

    /**
     * Write a frozen evidence snapshot directly into bbbext_advgrd_grade.
     *
     * @param int $bbbid BBB instance id (config row must already exist).
     * @param int $userid
     * @param array $session metric => value (uses metrics::* keys).
     */
    public function seed_evidence(int $bbbid, int $userid, array $session): stdClass {
        global $DB;

        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $bbbid], '*', MUST_EXIST);
        $payload = array_merge(array_fill_keys(metrics::metric_keys(), 0), $session);

        $existing = $DB->get_record('bbbext_advgrd_grade', [
            'configid' => $config->id, 'userid' => $userid,
        ]);

        if ($existing) {
            $existing->evidence = json_encode($payload);
            $DB->update_record('bbbext_advgrd_grade', $existing);
            return $existing;
        }

        $id = $DB->insert_record('bbbext_advgrd_grade', (object) [
            'configid' => $config->id,
            'userid'   => $userid,
            'evidence' => json_encode($payload),
        ]);
        return $DB->get_record('bbbext_advgrd_grade', ['id' => $id]);
    }

    /**
     * Insert a SUMMARY log row directly into bigbluebuttonbn_logs, mimicking what the BBB
     * end-of-meeting webhook would produce. Useful for testing metrics::aggregate_from_logs.
     *
     * @param int $bbbid
     * @param int $userid
     * @param array $session metric => value.
     */
    public function seed_summary_log(int $bbbid, int $userid, array $session): void {
        global $DB;

        $bbb = $DB->get_record('bigbluebuttonbn', ['id' => $bbbid], '*', MUST_EXIST);

        $meta = (object) [
            'recordid' => 'rec-' . $bbbid . '-' . $userid . '-' . time(),
            'data' => (object) [
                'ext_user_id' => $userid,
                'duration' => $session[metrics::METRIC_DURATION] ?? 0,
                'engagement' => (object) [
                    'talks' => $session[metrics::METRIC_TALKS] ?? 0,
                    'chats' => $session[metrics::METRIC_CHATS] ?? 0,
                    'raisehand' => $session[metrics::METRIC_RAISEHAND] ?? 0,
                    'poll_votes' => $session[metrics::METRIC_POLLS] ?? 0,
                    'emojis' => $session[metrics::METRIC_EMOJIS] ?? 0,
                ],
            ],
        ];

        $DB->insert_record('bigbluebuttonbn_logs', (object) [
            'courseid'           => $bbb->course,
            'bigbluebuttonbnid'  => $bbb->id,
            'userid'             => $userid,
            'meetingid'          => 'meeting-' . $bbb->id,
            'timecreated'        => time(),
            'log'                => 'Summary',
            'meta'               => json_encode($meta),
        ]);
    }
}
