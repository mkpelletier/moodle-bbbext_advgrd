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
use moodle_exception;

/**
 * Unit tests for the annotation CRUD service: text + audio create, scoped list,
 * delete-cascades-files, and category guarding.
 *
 * @covers \bbbext_advgrd\local\annotations
 */
final class annotations_test extends advanced_testcase {
    public function test_create_text_persists_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();

        $row = annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            12000,
            'Nice answer at 0:12.',
            'praise'
        );

        $this->assertGreaterThan(0, $row->id);
        $stored = $DB->get_record('bbbext_advgrd_annotation', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame('text', $stored->kind);
        $this->assertSame('praise', $stored->commenttype);
        $this->assertSame(12000, (int) $stored->timestampms);
        $this->assertSame('rec-1', $stored->recordingid);
        $this->assertSame((int) $bbb->id, (int) $stored->bigbluebuttonbnid);
    }

    public function test_create_text_rejects_empty_body(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $this->expectException(moodle_exception::class);
        annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '   ',
            'general'
        );
    }

    public function test_create_text_rejects_unknown_category(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $this->expectException(moodle_exception::class);
        annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            'hello',
            'random'
        );
    }

    public function test_list_for_review_is_scoped(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $other = $this->getDataGenerator()->create_user();

        annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            1000,
            'For target',
            'general'
        );
        annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $other->id,
            (int) $grader->id,
            2000,
            'For other student',
            'general'
        );
        annotations::create_text(
            (int) $bbb->id,
            'rec-2',
            (int) $target->id,
            (int) $grader->id,
            3000,
            'Different recording',
            'general'
        );

        $rows = annotations::list_for_review((int) $bbb->id, 'rec-1', (int) $target->id);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('For target', $row->body);
    }

    public function test_delete_removes_row(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $row = annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            500,
            'to delete',
            'general'
        );
        annotations::delete((int) $row->id);
        $this->assertFalse(
            $DB->record_exists('bbbext_advgrd_annotation', ['id' => $row->id])
        );
    }

    public function test_create_audio_persists_kind(): void {
        global $DB;
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $row = annotations::create_audio(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            45000,
            'Quick verbal note',
            'suggestion'
        );
        $stored = $DB->get_record('bbbext_advgrd_annotation', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame('audio', $stored->kind);
        $this->assertSame('suggestion', $stored->commenttype);
    }

    public function test_delete_audio_removes_attached_file(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $row = annotations::create_audio(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            'caption',
            'general'
        );

        $context = annotations::context_for_annotation($row);
        $fs = get_file_storage();
        $fs->create_file_from_string(
            (object) [
                'contextid' => $context->id,
                'component' => 'bbbext_advgrd',
                'filearea'  => annotations::AUDIO_FILEAREA,
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
            annotations::AUDIO_FILEAREA,
            $row->id,
            'id',
            false
        ));

        annotations::delete((int) $row->id);
        $this->assertEmpty($fs->get_area_files(
            $context->id,
            'bbbext_advgrd',
            annotations::AUDIO_FILEAREA,
            $row->id,
            'id',
            false
        ));
    }

    public function test_context_for_annotation_returns_module_context(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader] = $this->seed();
        $row = annotations::create_text(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            'hello',
            'general'
        );
        $context = annotations::context_for_annotation($row);
        $this->assertSame(CONTEXT_MODULE, $context->contextlevel);
    }

    /**
     * Seed a BBB activity + addressed student + grader.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function seed(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $target = $dg->create_user();
        $dg->enrol_user($target->id, $course->id, 'student');
        $grader = $dg->create_user();
        $dg->enrol_user($grader->id, $course->id, 'editingteacher');
        return [$bbb, $target, $grader];
    }
}
