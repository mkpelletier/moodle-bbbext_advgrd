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
 * Custom Behat steps for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use bbbext_advgrd\local\grader;
use bbbext_advgrd\local\metrics;

/**
 * Custom step definitions for setting up advanced-grading test fixtures.
 *
 * Bypasses the BBB module's join-meeting flow (which would need the BBB mock server) by
 * driving our own database tables directly via the data generator.
 */
class behat_bbbext_advgrd extends behat_base {
    /**
     * Configure a BBB activity's bbbext_advgrd grading method (rubric / guide / none).
     *
     * @Given the BigBlueButton activity :bbb has advanced grading method :method
     * @param string $bbb     Activity name (its instance.name).
     * @param string $method  One of rubric|guide|none.
     */
    public function bbb_has_grading_method(string $bbb, string $method): void {
        $bbbid = $this->resolve_bbb_id($bbb);
        /** @var bbbext_advgrd_generator $gen */
        $gen = behat_util::get_data_generator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbbid, ['gradingmethod' => $method]);
    }

    /**
     * Import the Community of Inquiry rubric template into a BBB activity.
     *
     * @Given the Community of Inquiry rubric template has been imported into :bbb
     * @param string $bbb Activity name (its instance.name).
     */
    public function coi_template_imported(string $bbb): void {
        $bbbid = $this->resolve_bbb_id($bbb);
        /** @var bbbext_advgrd_generator $gen */
        $gen = behat_util::get_data_generator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbbid, ['gradingmethod' => 'rubric']);
        $gen->import_template($bbbid);
    }

    /**
     * Seed a frozen evidence snapshot for a user in a BBB activity.
     *
     * Table columns: metric, value. Allowed metrics: duration, talks, chats, raisehand, polls,
     * emojis (any subset).
     *
     * @Given the user :username has BigBlueButton engagement evidence in :bbb:
     * @param string    $username User's username.
     * @param string    $bbb      Activity name.
     * @param TableNode $table    Metric/value rows.
     */
    public function user_has_evidence(string $username, string $bbb, TableNode $table): void {
        global $DB;
        $bbbid = $this->resolve_bbb_id($bbb);
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);

        $session = [];
        foreach ($table->getHash() as $row) {
            $session[$row['metric']] = (int) $row['value'];
        }

        /** @var bbbext_advgrd_generator $gen */
        $gen = behat_util::get_data_generator()->get_plugin_generator('bbbext_advgrd');
        $gen->seed_evidence($bbbid, $userid, $session);
    }

    /**
     * Assert the engagement-evidence panel is visible on the current page.
     *
     * @Then I should see the engagement evidence panel
     */
    public function evidence_panel_visible(): void {
        $this->execute(
            'behat_general::assert_page_contains_text',
            [get_string('evidence_heading', 'bbbext_advgrd')]
        );
    }

    /**
     * Resolve a BBB activity by name (its instance.name) to its bigbluebuttonbn.id.
     */
    protected function resolve_bbb_id(string $name): int {
        global $DB;
        $id = $DB->get_field('bigbluebuttonbn', 'id', ['name' => $name]);
        if (!$id) {
            throw new Exception("BBB activity '{$name}' not found.");
        }
        return (int) $id;
    }
}
