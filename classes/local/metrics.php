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
 * Reads, normalises, and aggregates BigBlueButton engagement metrics.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local;

use mod_bigbluebuttonbn\instance;
use mod_bigbluebuttonbn\logger;

/**
 * Extracts engagement metrics from BBB summary logs and from frozen evidence snapshots.
 *
 * Canonical metric keys used throughout the plugin:
 *   duration   — total attendance time in seconds
 *   talks      — total mic-talk time in seconds
 *   chats      — count of chat messages
 *   raisehand  — count of raise-hand events
 *   polls      — count of poll votes cast
 *   emojis     — count of emoji reactions
 */
class metrics {

    /** Canonical metric keys. */
    public const METRIC_DURATION  = 'duration';
    public const METRIC_TALKS     = 'talks';
    public const METRIC_CHATS     = 'chats';
    public const METRIC_RAISEHAND = 'raisehand';
    public const METRIC_POLLS     = 'polls';
    public const METRIC_EMOJIS    = 'emojis';
    public const METRIC_COMPOSITE = 'composite';

    /**
     * The set of countable metrics (composite is derived).
     *
     * @return string[]
     */
    public static function metric_keys(): array {
        return [
            self::METRIC_DURATION,
            self::METRIC_TALKS,
            self::METRIC_CHATS,
            self::METRIC_RAISEHAND,
            self::METRIC_POLLS,
            self::METRIC_EMOJIS,
        ];
    }

    /**
     * Extract a single attendee's engagement metrics from a BBB callback attendee object.
     *
     * Handles both the flat shape (older BBB) and the nested data.engagement.* shape
     * (newer BBB) by checking each location and falling back to 0.
     *
     * @param object $attendee Decoded attendee object from the BBB end-of-meeting webhook.
     * @return array{duration:int, talks:int, chats:int, raisehand:int, polls:int, emojis:int}
     */
    public static function extract_attendee_session(object $attendee): array {
        $engagement = $attendee->engagement ?? ($attendee->data->engagement ?? null);
        $duration = $attendee->duration ?? ($attendee->data->duration ?? 0);

        return [
            self::METRIC_DURATION  => (int) $duration,
            self::METRIC_TALKS     => (int) ($engagement->talks ?? 0),
            self::METRIC_CHATS     => (int) ($engagement->chats ?? 0),
            self::METRIC_RAISEHAND => (int) ($engagement->raisehand ?? 0),
            self::METRIC_POLLS     => (int) (
                $engagement->poll_votes ?? ($engagement->polls ?? 0)
            ),
            self::METRIC_EMOJIS    => (int) ($engagement->emojis ?? 0),
        ];
    }

    /**
     * Sum two metric snapshots together. Used to accumulate evidence across sessions.
     *
     * @param array $existing
     * @param array $additional
     * @return array
     */
    public static function accumulate_session(array $existing, array $additional): array {
        $totals = [];
        foreach (self::metric_keys() as $key) {
            $totals[$key] = (int) ($existing[$key] ?? 0) + (int) ($additional[$key] ?? 0);
        }
        return $totals;
    }

    /**
     * Aggregate engagement metrics for a user across every SUMMARY log row in this BBB instance.
     *
     * Use this when no frozen evidence snapshot exists yet (e.g., grading a session retroactively
     * before the broker fires, or after evidence rows were cleared).
     *
     * @param instance $instance
     * @param int $userid
     * @return array Aggregated metrics keyed by canonical metric name.
     */
    public static function aggregate_from_logs(instance $instance, int $userid): array {
        $logs = logger::get_user_completion_logs($instance, $userid, [logger::EVENT_SUMMARY]);
        $totals = array_fill_keys(self::metric_keys(), 0);

        foreach ($logs as $log) {
            $meta = json_decode($log->meta);
            if (!is_object($meta) || empty($meta->data) || !is_object($meta->data)) {
                continue;
            }
            $session = self::extract_attendee_session($meta->data);
            $totals = self::accumulate_session($totals, $session);
        }

        return $totals;
    }

    /**
     * Read the frozen evidence snapshot for a user, falling back to live log aggregation if none exists.
     *
     * @param instance $instance
     * @param int $userid
     * @return array Aggregated metrics keyed by canonical metric name.
     */
    public static function for_user(instance $instance, int $userid): array {
        global $DB;

        $sql = "SELECT g.evidence
                  FROM {bbbext_advgrd_grade} g
                  JOIN {bbbext_advgrd_config} c ON c.id = g.configid
                 WHERE c.bigbluebuttonbnid = :instanceid
                   AND g.userid = :userid";
        $evidence = $DB->get_field_sql($sql, [
            'instanceid' => $instance->get_instance_id(),
            'userid'     => $userid,
        ]);

        if ($evidence) {
            $decoded = json_decode($evidence, true);
            if (is_array($decoded)) {
                return array_intersect_key(
                    array_merge(array_fill_keys(self::metric_keys(), 0), $decoded),
                    array_fill_keys(self::metric_keys(), 0)
                );
            }
        }

        return self::aggregate_from_logs($instance, $userid);
    }

    /**
     * Compute a normalised composite score from a metrics snapshot using configured weights.
     *
     * Each metric is normalised against a per-metric reference value (so seconds and counts
     * combine sensibly), multiplied by its weight, and summed. Returns a 0..1 figure.
     *
     * @param array $metrics  Metric snapshot.
     * @param array $weights  Optional metric => weight overrides; defaults from site settings.
     * @param array $references Optional metric => reference value (the value treated as "100%").
     * @return float 0..1 composite score (clamped).
     */
    public static function composite_score(array $metrics, array $weights = [], array $references = []): float {
        $defaultweights = [
            self::METRIC_DURATION  => (float) (get_config('bbbext_advgrd', 'defaultweight_duration') ?: 1.0),
            self::METRIC_TALKS     => (float) (get_config('bbbext_advgrd', 'defaultweight_talks') ?: 1.0),
            self::METRIC_CHATS     => (float) (get_config('bbbext_advgrd', 'defaultweight_chats') ?: 1.0),
            self::METRIC_RAISEHAND => 0.5,
            self::METRIC_POLLS     => 0.5,
            self::METRIC_EMOJIS    => 0.25,
        ];
        $weights = $weights + $defaultweights;

        // Reference values picked so each metric reaches "1.0" at a typical strong-engagement
        // level for a 60-minute class. Teachers can override per-criterion in metric_map.
        $defaultrefs = [
            self::METRIC_DURATION  => 3600,
            self::METRIC_TALKS     => 600,
            self::METRIC_CHATS     => 10,
            self::METRIC_RAISEHAND => 3,
            self::METRIC_POLLS     => 3,
            self::METRIC_EMOJIS    => 5,
        ];
        $references = $references + $defaultrefs;

        $weightsum = 0.0;
        $score = 0.0;
        foreach (self::metric_keys() as $key) {
            $w = $weights[$key] ?? 0.0;
            if ($w <= 0) {
                continue;
            }
            $ref = max(1, (int) $references[$key]);
            $normalised = min(1.0, ((int) ($metrics[$key] ?? 0)) / $ref);
            $score += $w * $normalised;
            $weightsum += $w;
        }
        return $weightsum > 0 ? max(0.0, min(1.0, $score / $weightsum)) : 0.0;
    }

    /**
     * Suggest a rubric/guide level given a metric value and a threshold map.
     *
     * Thresholds are an ordered list of [levelkey => minvalue] pairs, evaluated from highest
     * minvalue downwards; the first match wins. Returns null if no level matches.
     *
     * @param int|float $value The student's metric value.
     * @param array<string|int, int|float> $thresholds Map of level key => minimum metric value.
     * @return string|int|null The matching level key, or null.
     */
    public static function suggest_level($value, array $thresholds) {
        if (empty($thresholds)) {
            return null;
        }
        arsort($thresholds);
        foreach ($thresholds as $levelkey => $minvalue) {
            if ($value >= $minvalue) {
                return $levelkey;
            }
        }
        return null;
    }
}
