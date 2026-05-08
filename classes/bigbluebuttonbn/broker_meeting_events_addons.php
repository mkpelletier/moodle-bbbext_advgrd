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
 * Snapshots BBB engagement metrics into immutable evidence rows on meeting end.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\bigbluebuttonbn;

use bbbext_advgrd\local\metrics;

/**
 * Receives the end-of-meeting webhook payload and writes per-user evidence rows.
 *
 * The BBB broker invokes process_action() once per registered extension after a
 * successful end-of-meeting callback. The raw JSON we receive in $this->data is
 * the exact body BBB sent — see meeting::meeting_events() and process_meeting_events().
 */
class broker_meeting_events_addons extends \mod_bigbluebuttonbn\local\extension\broker_meeting_events_addons {

    /**
     * Parse attendees, accumulate their metrics into bbbext_advgrd_grade.evidence,
     * preserving any existing evidence by summing across multiple sessions.
     */
    public function process_action() {
        global $DB;

        $instanceid = $this->instance->get_instance_id();
        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $instanceid]);
        if (!$config || $config->gradingmethod === 'none') {
            return;
        }

        $payload = json_decode($this->data);
        if (!is_object($payload) || empty($payload->data->attendees) || !is_array($payload->data->attendees)) {
            return;
        }

        foreach ($payload->data->attendees as $attendee) {
            // BBB sets ext_user_id to the Moodle userid we passed at join time; guests are non-numeric and skipped.
            if (empty($attendee->ext_user_id) || !is_numeric($attendee->ext_user_id)) {
                continue;
            }
            $userid = (int) $attendee->ext_user_id;
            $session = metrics::extract_attendee_session($attendee);

            $existing = $DB->get_record('bbbext_advgrd_grade', [
                'configid' => $config->id,
                'userid'   => $userid,
            ]);

            if ($existing) {
                $existing->evidence = json_encode(metrics::accumulate_session(
                    json_decode($existing->evidence ?: '{}', true) ?: [],
                    $session
                ));
                $DB->update_record('bbbext_advgrd_grade', $existing);
            } else {
                $DB->insert_record('bbbext_advgrd_grade', (object) [
                    'configid'   => $config->id,
                    'userid'     => $userid,
                    'evidence'   => json_encode($session),
                    'timegraded' => null,
                ]);
            }
        }
    }
}
