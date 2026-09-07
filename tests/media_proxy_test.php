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

    /**
     * The stream reads its status out of the curl wrapper's response array, where the status
     * line is stored like any other header, keyed by its first token.
     *
     * @dataProvider status_provider
     * @param array $response
     * @param int   $expected
     */
    public function test_response_status(array $response, int $expected): void {
        $this->assertSame($expected, self::call('response_status', [$response]));
    }

    /**
     * Response arrays as \curl::formatHeader() builds them, and the status they encode.
     *
     * @return array[]
     */
    public static function status_provider(): array {
        return [
            'ok' => [['HTTP/1.1' => '200 OK', 'Content-Type' => 'video/mp4'], 200],
            'partial content' => [['HTTP/1.1' => '206 Partial Content'], 206],
            'http/2' => [['HTTP/2' => '403 '], 403],
            'forbidden' => [['HTTP/1.1' => '403 Forbidden'], 403],
            'no status line' => [['Content-Type' => 'video/mp4'], 0],
            'blocked url leaves nothing' => [[], 0],
            'repeated status line keeps the last' => [['HTTP/1.1' => ['302 Found', '200 OK']], 200],
        ];
    }

    /**
     * Headers are lower-cased for send_headers(), and the status line is not one of them.
     */
    public function test_response_headers_lowercases_and_drops_the_status_line(): void {
        $headers = self::call('response_headers', [[
            'HTTP/1.1'       => '206 Partial Content',
            'Content-Type'   => 'video/mp4',
            'Accept-Ranges'  => 'bytes',
            'Content-Range'  => 'bytes 0-1023/2048',
            'Set-Cookie'     => ['a=1', 'b=2'],
        ]]);

        $this->assertSame([
            'content-type'  => 'video/mp4',
            'accept-ranges' => 'bytes',
            'content-range' => 'bytes 0-1023/2048',
            'set-cookie'    => 'b=2',
        ], $headers);
        $this->assertArrayNotHasKey('http/1.1', $headers);
    }

    /**
     * Only a well-formed byte range is forwarded upstream; the header is client-controlled.
     *
     * @dataProvider range_provider
     * @param string      $header
     * @param string|null $expected
     */
    public function test_client_range($header, $expected): void {
        $original = $_SERVER['HTTP_RANGE'] ?? null;
        $_SERVER['HTTP_RANGE'] = $header;
        try {
            $this->assertSame($expected, self::call('client_range', []));
        } finally {
            if ($original === null) {
                unset($_SERVER['HTTP_RANGE']);
            } else {
                $_SERVER['HTTP_RANGE'] = $original;
            }
        }
    }

    /**
     * Range header values and what the proxy forwards for each.
     *
     * @return array[]
     */
    public static function range_provider(): array {
        return [
            'open ended' => ['bytes=0-', 'bytes=0-'],
            'closed' => ['bytes=1024-2047', 'bytes=1024-2047'],
            'suffix' => ['bytes=-500', 'bytes=-500'],
            'multipart' => ['bytes=0-99, 200-299', 'bytes=0-99, 200-299'],
            'padded' => ['  bytes=0-  ', 'bytes=0-'],
            'absent' => ['', null],
            'other unit' => ['seconds=0-10', null],
            'garbage' => ['bytes=abc', null],
            'header injection attempt' => ["bytes=0-\r\nX-Evil: 1", null],
        ];
    }

    /**
     * Reach a protected static helper.
     *
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    private static function call(string $method, array $args) {
        $reflection = new \ReflectionMethod(media_proxy::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }
}
