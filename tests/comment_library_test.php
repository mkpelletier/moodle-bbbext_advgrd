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
 * Tests for bbbext_advgrd\local\comment_library.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\comment_library;

/**
 * Unit tests for the grader comment library.
 *
 * @covers \bbbext_advgrd\local\comment_library
 */
final class comment_library_test extends advanced_testcase {
    /**
     * The endpoint takes the snippet as PARAM_RAW because it is editor HTML, so the cleaning
     * has to happen here - a course-shared entry is read by graders other than its author.
     */
    public function test_save_cleans_stored_html(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);

        $row = comment_library::save(
            (int) $user->id,
            (int) $course->id,
            '<p>Well argued</p><script>alert(1)</script>',
            'praise',
            0
        );

        $stored = $DB->get_record('bbbext_advgrd_comlib', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $stored->commenttext);
        $this->assertStringContainsString('Well argued', $stored->commenttext);
    }

    /**
     * Rows written before save() cleaned must not reach another grader's browser intact.
     */
    public function test_fetch_cleans_legacy_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        // Write straight to the table to mimic a row stored before save() cleaned.
        $now = time();
        $DB->insert_record('bbbext_advgrd_comlib', (object) [
            'userid'       => (int) $author->id,
            'courseid'     => (int) $course->id,
            'commenttext'  => '<p>Legacy</p><script>alert(1)</script>',
            'commenttype'  => 'general',
            'sortorder'    => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        $this->setUser($reader);
        $data = comment_library::fetch((int) $reader->id, (int) $course->id);

        $this->assertCount(1, $data['shared']);
        $this->assertStringNotContainsString('<script', $data['shared'][0]->commenttext);
        $this->assertStringContainsString('Legacy', $data['shared'][0]->commenttext);
    }

    /**
     * A snippet that is markup-only after cleaning is still rejected as empty.
     */
    public function test_save_rejects_empty_body(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        comment_library::save((int) $user->id, (int) $course->id, '   ', 'general', 0);
    }
}
