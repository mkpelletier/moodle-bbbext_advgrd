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
 * Tests for bbbext_advgrd\privacy\provider.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\privacy\provider;
use context_module;
use core_privacy\local\request\approved_userlist;

/**
 * Unit tests for the bulk userlist delete path.
 *
 * @covers \bbbext_advgrd\privacy\provider
 */
final class privacy_provider_test extends advanced_testcase {
    /**
     * Rows addressed to a listed user go; rows merely rated by a listed user stay
     * but lose the rater reference. Users outside the list are untouched.
     */
    public function test_delete_data_for_users_deletes_owned_rows_and_anonymises_rater(): void {
        global $DB;
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $listedstudent = $dg->create_user();
        $otherstudent = $dg->create_user();
        $listedgrader = $dg->create_user();
        $othergrader = $dg->create_user();
        foreach ([$listedstudent, $otherstudent] as $student) {
            $dg->enrol_user($student->id, $course->id, 'student');
        }
        foreach ([$listedgrader, $othergrader] as $grader) {
            $dg->enrol_user($grader->id, $course->id, 'editingteacher');
        }

        $config = $dg->get_plugin_generator('bbbext_advgrd')->create_config((int) $bbb->id);
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', (int) $bbb->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Grades: the listed student's own row, plus another student's row rated by the
        // listed grader and one rated by a grader who is not on the list.
        $ownedgrade = $this->seed_grade($config, $listedstudent->id, $othergrader->id);
        $ratedgrade = $this->seed_grade($config, $otherstudent->id, $listedgrader->id);
        $untouchedgrade = $this->seed_grade($config, $dg->create_user()->id, $othergrader->id);

        // Annotations follow the same shape.
        $ownedann = $this->seed_annotation($bbb->id, $listedstudent->id, $othergrader->id);
        $ratedann = $this->seed_annotation($bbb->id, $otherstudent->id, $listedgrader->id);
        $untouchedann = $this->seed_annotation($bbb->id, $otherstudent->id, $othergrader->id);

        provider::delete_data_for_users(new approved_userlist(
            $context,
            'bbbext_advgrd',
            [$listedstudent->id, $listedgrader->id]
        ));

        $this->assertFalse($DB->record_exists('bbbext_advgrd_grade', ['id' => $ownedgrade]));
        $this->assertNull($DB->get_field('bbbext_advgrd_grade', 'graderid', ['id' => $ratedgrade]));
        $this->assertEquals(
            $othergrader->id,
            $DB->get_field('bbbext_advgrd_grade', 'graderid', ['id' => $untouchedgrade])
        );

        $this->assertFalse($DB->record_exists('bbbext_advgrd_annotation', ['id' => $ownedann]));
        $this->assertNull($DB->get_field('bbbext_advgrd_annotation', 'graderid', ['id' => $ratedann]));
        $this->assertEquals(
            $othergrader->id,
            $DB->get_field('bbbext_advgrd_annotation', 'graderid', ['id' => $untouchedann])
        );
    }

    /**
     * Insert a grade row.
     *
     * @param \stdClass $config bbbext_advgrd_config row.
     * @param int $userid Graded user.
     * @param int $graderid Rater.
     * @return int The new row id.
     */
    private function seed_grade(\stdClass $config, int $userid, int $graderid): int {
        global $DB;
        return $DB->insert_record('bbbext_advgrd_grade', (object) [
            'bigbluebuttonbnid' => $config->bigbluebuttonbnid,
            'configid' => $config->id,
            'userid' => $userid,
            'graderid' => $graderid,
            'evidence' => '{}',
            'timegraded' => time(),
        ]);
    }

    /**
     * Insert an annotation row.
     *
     * @param int $bbbid bigbluebuttonbn.id
     * @param int $targetuserid Addressed student.
     * @param int $graderid Author.
     * @return int The new row id.
     */
    private function seed_annotation(int $bbbid, int $targetuserid, int $graderid): int {
        global $DB;
        $now = time();
        return $DB->insert_record('bbbext_advgrd_annotation', (object) [
            'bigbluebuttonbnid' => $bbbid,
            'recordingid' => 'rec-1',
            'targetuserid' => $targetuserid,
            'graderid' => $graderid,
            'timestampms' => 1000,
            'body' => '<p>Note.</p>',
            'bodyformat' => FORMAT_HTML,
            'commenttype' => 'general',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
