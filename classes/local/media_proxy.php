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
 * Server-side machinery behind the same-origin media proxy.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

/**
 * Replays BigBlueButton's playback-page cookie handshake and streams recording media back.
 *
 * BBB gates the raw recording files (video-0.m4v and friends) behind an authorisation cookie
 * that only its own playback page sets. The annotation overlay mounts a plain HTML5 <video>,
 * which carries no such cookie, so pointing that element at the BBB host yields a 403 and a
 * silent black box. This class performs the handshake server-side, caches the resulting
 * cookie per user and recording, and relays the bytes from Moodle's own origin.
 *
 * Everything here is static and side-effecting on the HTTP response; pages/play.php is the
 * only caller, and it exists solely to be that caller's implementation. It lives in a class
 * rather than as functions in the page so that nothing lands in the global namespace.
 */
class media_proxy {
    /** @var int How long a cached BBB authorisation cookie jar is trusted before re-handshaking. */
    const COOKIE_TTL = 20 * MINSECS;

    /**
     * Path of this user's cookie jar for one recording.
     *
     * Jars are per user as well as per recording: the cookie BBB hands back is an authorisation
     * artefact, and sharing one jar between users would let the first viewer's grant serve
     * everybody else's requests.
     *
     * @param int    $userid
     * @param string $recordingid
     * @return string
     */
    public static function cookie_jar(int $userid, string $recordingid): string {
        global $CFG;
        $dir = $CFG->tempdir . '/bbbext_advgrd/cookies';
        if (!is_dir($dir)) {
            make_temp_directory('bbbext_advgrd/cookies');
        }
        return $dir . '/' . sha1($userid . '|' . $recordingid) . '.txt';
    }

    /**
     * True when the jar on disk is missing or older than the cookie's trusted lifetime.
     *
     * @param string $jar
     * @return bool
     */
    public static function jar_is_stale(string $jar): bool {
        return !file_exists($jar) || (time() - filemtime($jar)) > self::COOKIE_TTL;
    }

    /**
     * Fetch the BBB /capture/ playback page so its Set-Cookie lands in our jar.
     *
     * This is exactly what the browser does when someone opens the recording from the BBB
     * activity - the step whose absence made the player render black.
     *
     * @param string $captureurl
     * @param string $jar
     * @param bool   $force Discard any existing jar first.
     * @return void
     */
    public static function handshake(string $captureurl, string $jar, bool $force = false): void {
        if ($force && file_exists($jar)) {
            @unlink($jar);
        }
        $ch = curl_init($captureurl);
        curl_setopt_array($ch, self::curl_defaults($jar) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_exec($ch);
        curl_close($ch);
        // A failed handshake is not fatal on its own - unprotected recordings need no cookie at
        // all. Let the media request be the judge.
    }

    /**
     * Stream the media URL back to the client, forwarding byte ranges both ways.
     *
     * Output is withheld until the upstream status line has arrived, so a failed fetch leaves
     * the response untouched and the caller can still retry or send an error of its own.
     *
     * @param string $mediaurl
     * @param string $jar
     * @return int The upstream HTTP status, or 0 when the request could not be made.
     */
    public static function stream(string $mediaurl, string $jar): int {
        $state = (object) [
            'status'  => 0,
            'headers' => [],
            'sent'    => false,
        ];

        $ch = curl_init($mediaurl);
        $options = self::curl_defaults($jar) + [
            // No overall timeout: a full recording legitimately takes as long as it takes.
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use ($state) {
                $trimmed = trim($line);
                if (stripos($trimmed, 'HTTP/') === 0) {
                    // A new status line means a redirect hop; the headers we collected belong to
                    // the hop we are leaving, so start the set again.
                    $state->status = (int) (explode(' ', $trimmed)[1] ?? 0);
                    $state->headers = [];
                    return strlen($line);
                }
                $split = strpos($trimmed, ':');
                if ($split !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $split)));
                    $state->headers[$name] = trim(substr($trimmed, $split + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($state) {
                if ($state->status < 200 || $state->status >= 300) {
                    // Swallow the error body: the caller may still retry, and half an upstream
                    // error page in front of the real media would corrupt the retry.
                    return strlen($chunk);
                }
                if (!$state->sent) {
                    self::send_headers($state->status, $state->headers);
                    $state->sent = true;
                }
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            // Seeking in the overlay's timeline is a Range request. Pass it straight through so
            // BBB serves the 206 rather than us buffering a whole lecture to reach one offset.
            $options[CURLOPT_HTTPHEADER] = ['Range: ' . $_SERVER['HTTP_RANGE']];
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno && !$state->sent) {
            return 0;
        }
        if ($state->status >= 200 && $state->status < 300 && !$state->sent) {
            // A legitimately empty body (a zero-length range, say) still needs its headers.
            self::send_headers($state->status, $state->headers);
            $state->sent = true;
        }
        return $state->status;
    }

    /**
     * Whether the probe row is complete enough to proxy, and points where it claims to.
     *
     * The media URL was resolved relative to the capture page, so the two must share a host.
     * Both come from BBB rather than from the request, but the page fetches a URL out of the
     * database and streams the response back, so pin the target rather than trusting the row:
     * a bad write anywhere upstream must not turn this into an open proxy.
     *
     * @param \stdClass|false|null $probe Row from bbbext_advgrd_rec_probe.
     * @return bool
     */
    public static function probe_is_proxyable($probe): bool {
        if (!$probe || $probe->probestatus !== 'ok' || empty($probe->mediaurl) || empty($probe->captureurl)) {
            // Nothing probed (or a pre-0.4.2 row with no capture URL recorded).
            return false;
        }
        $mediahost = strtolower((string) parse_url($probe->mediaurl, PHP_URL_HOST));
        $capturehost = strtolower((string) parse_url($probe->captureurl, PHP_URL_HOST));
        $mediascheme = strtolower((string) parse_url($probe->mediaurl, PHP_URL_SCHEME));
        return $mediahost !== '' && $mediahost === $capturehost && in_array($mediascheme, ['http', 'https'], true);
    }

    /**
     * Send a bare status and stop. No Moodle error page - the caller is a <video> element.
     *
     * @param int $status
     * @return void
     */
    public static function abort(int $status): void {
        header('HTTP/1.1 ' . $status);
        header('Cache-Control: private, max-age=0, no-cache');
        exit;
    }

    /**
     * Shared curl options for both legs, so the handshake and the stream share a cookie jar
     * and the same protocol restrictions.
     *
     * @param string $jar
     * @return array
     */
    protected static function curl_defaults(string $jar): array {
        return [
            CURLOPT_COOKIEFILE      => $jar,
            CURLOPT_COOKIEJAR       => $jar,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_USERAGENT       => \core_useragent::get_moodlebot_useragent(),
        ];
    }

    /**
     * Relay the upstream response headers the media element actually needs.
     *
     * Accept-Ranges and Content-Range are the load-bearing ones: drop them and the browser
     * treats the stream as unseekable, which silently disables timeline seeking.
     *
     * @param int   $status
     * @param array $headers Lower-cased upstream header name => value.
     * @return void
     */
    protected static function send_headers(int $status, array $headers): void {
        header('HTTP/1.1 ' . $status . ($status === 206 ? ' Partial Content' : ' OK'));
        $relay = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified'];
        foreach ($relay as $name) {
            if (isset($headers[$name])) {
                header(ucwords($name, '-') . ': ' . $headers[$name]);
            }
        }
        if (!isset($headers['content-type'])) {
            header('Content-Type: video/mp4');
        }
        if (!isset($headers['accept-ranges'])) {
            header('Accept-Ranges: bytes');
        }
        header('X-Content-Type-Options: nosniff');
        // Private: the response is authorised per user, so no shared cache may keep it.
        header('Cache-Control: private, max-age=0, no-cache');
    }
}
