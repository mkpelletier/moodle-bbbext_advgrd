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
 * Tests for bbbext_advgrd\local\metrics.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\metrics;
use mod_bigbluebuttonbn\instance;

/**
 * @covers \bbbext_advgrd\local\metrics
 */
final class metrics_test extends advanced_testcase {

    public function test_metric_keys_are_canonical(): void {
        $this->assertSame(
            ['duration', 'talks', 'chats', 'raisehand', 'polls', 'emojis'],
            metrics::metric_keys()
        );
    }

    public function test_extract_attendee_session_new_shape(): void {
        $attendee = (object) [
            'ext_user_id' => 7,
            'duration' => 1800,
            'engagement' => (object) [
                'talks' => 300, 'chats' => 6, 'raisehand' => 2,
                'poll_votes' => 1, 'emojis' => 4,
            ],
        ];
        $this->assertSame([
            'duration' => 1800, 'talks' => 300, 'chats' => 6,
            'raisehand' => 2, 'polls' => 1, 'emojis' => 4,
        ], metrics::extract_attendee_session($attendee));
    }

    public function test_extract_attendee_session_falls_back_to_data_block(): void {
        $attendee = (object) ['data' => (object) [
            'duration' => 600,
            'engagement' => (object) ['talks' => 30, 'polls' => 2],
        ]];
        $session = metrics::extract_attendee_session($attendee);
        $this->assertSame(600, $session['duration']);
        $this->assertSame(30, $session['talks']);
        $this->assertSame(2, $session['polls']);
        $this->assertSame(0, $session['chats']);
    }

    public function test_extract_attendee_session_defaults_to_zero(): void {
        $session = metrics::extract_attendee_session((object) []);
        foreach (metrics::metric_keys() as $k) {
            $this->assertSame(0, $session[$k], "metric $k should default to 0");
        }
    }

    public function test_accumulate_session_sums_metrics(): void {
        $a = ['duration' => 100, 'chats' => 3, 'talks' => 50];
        $b = ['duration' => 50, 'chats' => 1, 'raisehand' => 2];
        $sum = metrics::accumulate_session($a, $b);
        $this->assertSame(150, $sum['duration']);
        $this->assertSame(4, $sum['chats']);
        $this->assertSame(50, $sum['talks']);
        $this->assertSame(2, $sum['raisehand']);
        $this->assertSame(0, $sum['polls']);
    }

    public function test_composite_score_zero_for_empty_metrics(): void {
        $score = metrics::composite_score(array_fill_keys(metrics::metric_keys(), 0));
        $this->assertSame(0.0, $score);
    }

    public function test_composite_score_clamps_to_one(): void {
        // Pass values well above every reference value.
        $maxedout = [
            'duration' => 999999, 'talks' => 999999, 'chats' => 999,
            'raisehand' => 99, 'polls' => 99, 'emojis' => 99,
        ];
        $this->assertSame(1.0, metrics::composite_score($maxedout));
    }

    public function test_composite_score_uses_weighted_average(): void {
        // Half-saturated duration, nothing else; composite should be roughly half its weight share.
        $half = ['duration' => 1800] + array_fill_keys(metrics::metric_keys(), 0);
        $score = metrics::composite_score($half);
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(0.5, $score);
    }

    public function test_suggest_level_picks_highest_match(): void {
        $thresholds = [3 => 8, 2 => 4, 1 => 1, 0 => 0];
        $this->assertSame(3, metrics::suggest_level(10, $thresholds));
        $this->assertSame(3, metrics::suggest_level(8, $thresholds));
        $this->assertSame(2, metrics::suggest_level(7, $thresholds));
        $this->assertSame(1, metrics::suggest_level(1, $thresholds));
        $this->assertSame(0, metrics::suggest_level(0, $thresholds));
    }

    public function test_suggest_level_returns_null_when_no_thresholds(): void {
        $this->assertNull(metrics::suggest_level(10, []));
    }

    public function test_suggest_level_handles_unsorted_thresholds(): void {
        // Caller passes them in any order — we still pick the highest matching key.
        $thresholds = [1 => 1, 3 => 8, 0 => 0, 2 => 4];
        $this->assertSame(3, metrics::suggest_level(9, $thresholds));
        $this->assertSame(2, metrics::suggest_level(5, $thresholds));
    }

    public function test_for_user_prefers_evidence_over_logs(): void {
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        $gen->create_config($bbb->id);
        $gen->seed_summary_log($bbb->id, $user->id, ['duration' => 1, 'chats' => 999]);
        $gen->seed_evidence($bbb->id, $user->id, ['duration' => 600, 'chats' => 2]);

        $instance = instance::get_from_instanceid($bbb->id);
        $result = metrics::for_user($instance, $user->id);
        $this->assertSame(600, $result['duration']);
        $this->assertSame(2, $result['chats']);
    }

    public function test_for_user_falls_back_to_logs_when_no_evidence(): void {
        $this->resetAfterTest();
        [$bbb, $user] = $this->seed_bbb_with_user();

        /** @var \bbbext_advgrd_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('bbbext_advgrd');
        // No config row, no evidence — only a log row.
        $gen->seed_summary_log($bbb->id, $user->id,
            ['duration' => 900, 'talks' => 60, 'chats' => 4]);
        $gen->seed_summary_log($bbb->id, $user->id,
            ['duration' => 600, 'talks' => 20]);

        $instance = instance::get_from_instanceid($bbb->id);
        $result = metrics::for_user($instance, $user->id);
        $this->assertSame(1500, $result['duration']);
        $this->assertSame(80, $result['talks']);
        $this->assertSame(4, $result['chats']);
    }

    /**
     * Helper: create a course, a BBB activity, an enrolled student.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function seed_bbb_with_user(): array {
        $dg = $this->getDataGenerator();
        $course = $dg->create_course();
        $bbb = $dg->create_module('bigbluebuttonbn', ['course' => $course->id, 'grade' => 100]);
        $user = $dg->create_user();
        $dg->enrol_user($user->id, $course->id, 'student');
        return [$bbb, $user];
    }
}
