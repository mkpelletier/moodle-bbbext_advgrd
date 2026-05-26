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
 * Lifecycle hooks for the BBB Advanced Grading extension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\bigbluebuttonbn;

use stdClass;

/**
 * Persists per-instance config rows and cleans up on delete.
 */
class mod_instance_helper extends \mod_bigbluebuttonbn\local\extension\mod_instance_helper {
    /**
     * Insert config row when a new BBB instance is created.
     *
     * @param stdClass $bigbluebuttonbn BBB form data (already inserted, has ->id).
     */
    public function add_instance(stdClass $bigbluebuttonbn) {
        $this->upsert_config((int) $bigbluebuttonbn->id, $bigbluebuttonbn);
    }

    /**
     * Update or insert config row when a BBB instance is updated.
     *
     * @param stdClass $bigbluebuttonbn BBB form data.
     */
    public function update_instance(stdClass $bigbluebuttonbn): void {
        global $PAGE;
        $instanceid = $bigbluebuttonbn->id ?? ($PAGE->cm->instance ?? null);
        if (!$instanceid) {
            return;
        }
        $this->upsert_config((int) $instanceid, $bigbluebuttonbn);
    }

    /**
     * Delete config, metric mappings, grade rows, and the grading area when the BBB instance is removed.
     *
     * @param int $id BBB instance id (despite the parent's "$cmid" naming, the parent calls this with the
     *                bigbluebuttonbn instance id — same convention as bbbext_lad).
     */
    public function delete_instance(int $id): void {
        global $DB, $CFG;

        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $id]);
        if (!$config) {
            return;
        }

        // Remove our derived rows. The grading subsystem owns its own rows in grading_areas/_definitions/_instances
        // keyed on (contextid, component='bbbext_advgrd', areaname='participation'); the BBB module deletes the
        // course_module before this hook fires, so the context is gone and core grading cleans up via the
        // contextid foreign key. We only need to clean up our extension-owned tables.
        $DB->delete_records('bbbext_advgrd_grade', ['configid' => $config->id]);
        $DB->delete_records('bbbext_advgrd_metric_map', ['configid' => $config->id]);
        $DB->delete_records('bbbext_advgrd_config', ['id'       => $config->id]);

        // Tear down any analytic gradebook items we created (itemnumbers 1..3). The BBB module
        // handles itemnumber 0 itself.
        require_once($CFG->libdir . '/gradelib.php');
        $bbb = $DB->get_record('bigbluebuttonbn', ['id' => $id]);
        if ($bbb) {
            for ($itemnumber = 1; $itemnumber <= 3; $itemnumber++) {
                grade_update(
                    source: 'mod/bigbluebuttonbn',
                    courseid: $bbb->course,
                    itemtype: 'mod',
                    itemmodule: 'bigbluebuttonbn',
                    iteminstance: $bbb->id,
                    itemnumber: $itemnumber,
                    grades: null,
                    itemdetails: ['deleted' => 1]
                );
            }
        }
    }

    /**
     * Tables that BBB should join 1:1 against the bigbluebuttonbn row when retrieving instance
     * info or producing backups.
     *
     * Only `bbbext_advgrd_config` is listed: it's a 1:1 join on bigbluebuttonbnid. The
     * `bbbext_advgrd_metric_map` and `bbbext_advgrd_grade` tables are 1:N (many criteria per
     * activity, many users per activity), so listing them here would make BBB's
     * `get_instance_info_retriever()` SQL return duplicate rows ("Duplicate value found in
     * column cid" / coding exception). Their backup/restore is handled separately at a
     * future point via the plugin's own backup steps.
     *
     * @return string[]
     */
    public function get_join_tables(): array {
        return ['bbbext_advgrd_config'];
    }

    /**
     * Insert a new config row or update an existing one for this BBB instance.
     *
     * @param int $instanceid
     * @param stdClass $formdata
     */
    protected function upsert_config(int $instanceid, stdClass $formdata): void {
        global $DB;

        $now = time();
        $existing = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $instanceid]);

        $record = (object) [
            'bigbluebuttonbnid'      => $instanceid,
            'gradingmethod'          => $formdata->advgrd_gradingmethod ?? 'none',
            'scoremode'              => $formdata->advgrd_scoremode ?? 'composite',
            'passthroughtogradebook' => (int) ($formdata->advgrd_passthroughtogradebook ?? 1),
            'timemodified'           => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('bbbext_advgrd_config', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('bbbext_advgrd_config', $record);
        }
    }
}
