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
 * Tests for bbbext_advgrd\local\annotations.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\annotations;
use context_module;
use moodle_exception;

/**
 * Unit tests for the rich-text annotation CRUD service.
 *
 * @covers \bbbext_advgrd\local\annotations
 */
final class annotations_test extends advanced_testcase {
    public function test_create_persists_row_and_rewrites_body(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $draftitemid = file_get_unused_draft_itemid();

        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            12000,
            '<p>Good answer at 12s.</p>',
            FORMAT_HTML,
            'praise',
            $draftitemid,
            $context
        );

        $this->assertGreaterThan(0, $row->id);
        $stored = $DB->get_record('bbbext_advgrd_annotation', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame('praise', $stored->commenttype);
        $this->assertSame(12000, (int) $stored->timestampms);
        $this->assertSame('rec-1', $stored->recordingid);
        $this->assertStringContainsString('Good answer at 12s', $stored->body);
    }

    public function test_create_rejects_empty_body(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $this->expectException(moodle_exception::class);
        annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '   ',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );
    }

    public function test_create_accepts_media_only_body(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '<audio src="@@PLUGINFILE@@/audio.webm"></audio>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );
        $this->assertGreaterThan(0, $row->id);
        $this->assertStringContainsString('audio', $row->body);
    }

    public function test_create_rejects_unknown_category(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $this->expectException(moodle_exception::class);
        annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '<p>hello</p>',
            FORMAT_HTML,
            'random',
            file_get_unused_draft_itemid(),
            $context
        );
    }

    public function test_list_for_review_is_scoped(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $other = $this->getDataGenerator()->create_user();

        annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            1000,
            '<p>For target</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );
        annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $other->id,
            (int) $grader->id,
            2000,
            '<p>For other student</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );
        annotations::create(
            (int) $bbb->id,
            'rec-2',
            (int) $target->id,
            (int) $grader->id,
            3000,
            '<p>Different recording</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );

        $rows = annotations::list_for_review((int) $bbb->id, 'rec-1', (int) $target->id);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertStringContainsString('For target', $row->body);
    }

    /**
     * recording_ids_for_user() returns the distinct recordings a user has feedback
     * on, scoped to that user — the lookup hosts use to rescue a student's feedback
     * from BBB's group-based recording visibility.
     */
    public function test_recording_ids_for_user_returns_distinct_scoped_ids(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $other = $this->getDataGenerator()->create_user();
        $nofeedback = $this->getDataGenerator()->create_user();

        $mk = function (string $recid, int $userid, int $ts) use ($bbb, $grader, $context) {
            annotations::create(
                (int) $bbb->id, $recid, $userid, (int) $grader->id, $ts,
                '<p>c</p>', FORMAT_HTML, 'general', file_get_unused_draft_itemid(), $context
            );
        };
        $mk('rec-1', (int) $target->id, 1000);
        $mk('rec-1', (int) $target->id, 1500);   // same recording — must dedupe
        $mk('rec-2', (int) $target->id, 3000);
        $mk('rec-1', (int) $other->id, 2000);    // another student — must be excluded

        $ids = annotations::recording_ids_for_user((int) $bbb->id, (int) $target->id);
        sort($ids);
        $this->assertSame(['rec-1', 'rec-2'], $ids);

        // A user with no feedback gets an empty list.
        $this->assertSame([], annotations::recording_ids_for_user((int) $bbb->id, (int) $nofeedback->id));
    }

    public function test_delete_removes_row_and_files(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            500,
            '<p>to delete</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );

        $fs = get_file_storage();
        $fs->create_file_from_string(
            (object) [
                'contextid' => $context->id,
                'component' => 'bbbext_advgrd',
                'filearea'  => annotations::FILEAREA,
                'itemid'    => $row->id,
                'filepath'  => '/',
                'filename'  => 'audio.webm',
                'mimetype'  => 'audio/webm',
            ],
            'fake-binary'
        );
        $this->assertNotEmpty($fs->get_area_files(
            $context->id,
            'bbbext_advgrd',
            annotations::FILEAREA,
            $row->id,
            'id',
            false
        ));

        annotations::delete((int) $row->id);

        $this->assertFalse($DB->record_exists('bbbext_advgrd_annotation', ['id' => $row->id]));
        $this->assertEmpty($fs->get_area_files(
            $context->id,
            'bbbext_advgrd',
            annotations::FILEAREA,
            $row->id,
            'id',
            false
        ));
    }

    public function test_update_replaces_body_and_category(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            1000,
            '<p>original</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );

        $updated = annotations::update(
            (int) $row->id,
            5000,
            '<p>revised</p>',
            FORMAT_HTML,
            'correction',
            file_get_unused_draft_itemid(),
            $context
        );

        $this->assertSame(5000, (int) $updated->timestampms);
        $this->assertSame('correction', $updated->commenttype);
        $stored = $DB->get_record('bbbext_advgrd_annotation', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertStringContainsString('revised', $stored->body);
    }

    public function test_context_for_annotation_returns_module_context(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();
        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '<p>hello</p>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );
        $resolved = annotations::context_for_annotation($row);
        $this->assertSame($context->id, $resolved->id);
    }

    /**
     * Seed a BBB activity + addressed student + grader + activity context.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: context_module}
     */
    private function seed(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $target = $dg->create_user();
        $dg->enrol_user($target->id, $course->id, 'student');
        $grader = $dg->create_user();
        $dg->enrol_user($grader->id, $course->id, 'editingteacher');
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', (int) $bbb->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        // Authenticate as the grader: file_get_unused_draft_itemid() scopes the draft area
        // by $USER->id and rejects the guest user with "No guests here!". Tests call
        // create()/update() which exercise that path.
        $this->setUser($grader);
        return [$bbb, $target, $grader, $context];
    }
}
