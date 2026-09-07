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
 * Tests for bbbext_advgrd\external\shaper.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\external\shaper;
use bbbext_advgrd\local\annotations;
use context_module;

/**
 * Unit tests for the annotation row shaper shared by the add + list endpoints.
 *
 * @covers \bbbext_advgrd\external\shaper
 */
final class shaper_test extends advanced_testcase {
    /**
     * The overlay JS assigns the shaped body to innerHTML, so the endpoint must hand back
     * cleaned HTML rather than whatever the editor posted.
     */
    public function test_shape_row_cleans_scriptable_body(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();

        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            1000,
            '<p>Nice work</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
            FORMAT_HTML,
            'praise',
            file_get_unused_draft_itemid(),
            $context
        );

        $shaped = shaper::shape_row($row, $context);

        $this->assertStringNotContainsString('<script', $shaped['body']);
        $this->assertStringNotContainsString('onerror', $shaped['body']);
        $this->assertStringContainsString('Nice work', $shaped['body']);
    }

    /**
     * Cleaning must not eat the media embeds the annotation feature is built around - the
     * timeline markers key off <audio>/<video> surviving in the rendered body.
     */
    public function test_shape_row_keeps_media_embeds(): void {
        $this->resetAfterTest();
        [$bbb, $target, $grader, $context] = $this->seed();

        $row = annotations::create(
            (int) $bbb->id,
            'rec-1',
            (int) $target->id,
            (int) $grader->id,
            0,
            '<p>Listen</p><audio controls src="https://example.com/a.mp3"></audio>',
            FORMAT_HTML,
            'general',
            file_get_unused_draft_itemid(),
            $context
        );

        $shaped = shaper::shape_row($row, $context);
        $this->assertStringContainsString('<audio', $shaped['body']);
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
        $this->setUser($grader);
        return [$bbb, $target, $grader, $context];
    }
}
