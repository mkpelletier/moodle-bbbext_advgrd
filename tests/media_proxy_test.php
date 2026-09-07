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
 * Tests for bbbext_advgrd\local\media_proxy.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\media_proxy;
use stdClass;

/**
 * Unit tests for the parts of the recording media proxy that need no BBB host: cookie-jar
 * naming, jar staleness, and the probe-row guard that keeps the endpoint from being an
 * open proxy.
 *
 * @covers \bbbext_advgrd\local\media_proxy
 */
final class media_proxy_test extends advanced_testcase {
    public function test_cookie_jar_is_per_user_and_per_recording(): void {
        global $CFG;
        $this->resetAfterTest();

        $one = media_proxy::cookie_jar(11, 'abc123-1700000000000');
        $two = media_proxy::cookie_jar(12, 'abc123-1700000000000');
        $three = media_proxy::cookie_jar(11, 'def456-1700000000000');

        $this->assertNotEquals($one, $two, 'Two users must not share one authorisation jar.');
        $this->assertNotEquals($one, $three, 'Two recordings must not share one jar.');
        $this->assertSame($one, media_proxy::cookie_jar(11, 'abc123-1700000000000'));

        // The recording id must never reach the filename; it is hashed.
        $this->assertStringStartsWith($CFG->tempdir . '/bbbext_advgrd/cookies/', $one);
        $this->assertStringEndsWith('.txt', $one);
        $this->assertStringNotContainsString('abc123', $one);
        $this->assertDirectoryExists($CFG->tempdir . '/bbbext_advgrd/cookies');
    }

    public function test_jar_is_stale_when_missing_or_expired(): void {
        $this->resetAfterTest();

        $jar = media_proxy::cookie_jar(21, 'stale-1700000000000');
        @unlink($jar);
        $this->assertTrue(media_proxy::jar_is_stale($jar), 'A jar that was never written is stale.');

        file_put_contents($jar, '# cookies');
        $this->assertFalse(media_proxy::jar_is_stale($jar));

        touch($jar, time() - media_proxy::COOKIE_TTL - 60);
        clearstatcache(true, $jar);
        $this->assertTrue(media_proxy::jar_is_stale($jar));

        @unlink($jar);
    }

    /**
     * The probe-row guard accepts only complete rows whose media and capture URLs agree.
     *
     * @dataProvider probe_provider
     * @param mixed $probe
     * @param bool  $expected
     */
    public function test_probe_is_proxyable($probe, bool $expected): void {
        $this->assertSame($expected, media_proxy::probe_is_proxyable($probe));
    }

    /**
     * Probe rows and whether the proxy should be willing to fetch them.
     *
     * @return array[]
     */
    public static function probe_provider(): array {
        $row = function (array $fields): stdClass {
            return (object) ($fields + [
                'probestatus' => 'ok',
                'mediaurl'    => 'https://bbb.example.org/presentation/x/video-0.m4v',
                'captureurl'  => 'https://bbb.example.org/playback/capture/x/',
            ]);
        };

        return [
            'good row' => [$row([]), true],
            'host case differs' => [$row(['captureurl' => 'https://BBB.example.org/playback/']), true],
            'no row' => [false, false],
            'null row' => [null, false],
            'probe failed' => [$row(['probestatus' => 'failed']), false],
            'no media url' => [$row(['mediaurl' => '']), false],
            'pre-0.4.2 row without capture url' => [$row(['captureurl' => '']), false],
            'media on another host' => [$row(['mediaurl' => 'https://evil.example.net/x.m4v']), false],
            'file scheme' => [
                $row(['mediaurl' => 'file:///etc/passwd', 'captureurl' => 'file:///etc/passwd']),
                false,
            ],
            'hostless url' => [$row(['mediaurl' => '/presentation/x/video-0.m4v']), false],
        ];
    }
}
