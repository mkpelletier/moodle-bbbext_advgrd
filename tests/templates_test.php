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
 * Tests for the rubric/guide template registry and the three bundled templates.
 *
 * @package    bbbext_advgrd
 * @category   test
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd;

use advanced_testcase;
use bbbext_advgrd\local\metrics;
use bbbext_advgrd\local\templates\coi;
use bbbext_advgrd\local\templates\inclusive;
use bbbext_advgrd\local\templates\quantity_quality;
use bbbext_advgrd\local\templates\registry;
use bbbext_advgrd\local\templates\template;
use coding_exception;

/**
 * @covers \bbbext_advgrd\local\templates\template
 * @covers \bbbext_advgrd\local\templates\registry
 * @covers \bbbext_advgrd\local\templates\coi
 * @covers \bbbext_advgrd\local\templates\quantity_quality
 * @covers \bbbext_advgrd\local\templates\inclusive
 */
final class templates_test extends advanced_testcase {

    public function test_registry_exposes_all_three_templates(): void {
        $ids = array_map(fn($c) => $c::id(), registry::all());
        $this->assertContains('coi', $ids);
        $this->assertContains('quantity_quality', $ids);
        $this->assertContains('inclusive', $ids);
        $this->assertCount(3, $ids);
    }

    public function test_registry_get_resolves_known_id(): void {
        $this->assertSame(coi::class, registry::get('coi'));
        $this->assertSame(quantity_quality::class, registry::get('quantity_quality'));
        $this->assertSame(inclusive::class, registry::get('inclusive'));
    }

    public function test_registry_get_throws_for_unknown_id(): void {
        $this->expectException(coding_exception::class);
        registry::get('nonexistent');
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_template_metadata_is_localised(string $tplclass): void {
        // Each template must produce non-empty metadata strings, all distinct from raw lang keys.
        $this->assertNotEmpty($tplclass::id());
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $tplclass::id());
        $this->assertNotEmpty($tplclass::name());
        $this->assertStringNotContainsString('[[', $tplclass::name());
        $this->assertNotEmpty($tplclass::description());
        $this->assertNotEmpty($tplclass::definition_name());
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_rubric_definition_shape(string $tplclass): void {
        $payload = $tplclass::rubric_definition();
        $this->assertArrayHasKey('definition', $payload);
        $this->assertArrayHasKey('mappings', $payload);
        $criteria = $payload['definition']['rubric']['criteria'];
        $this->assertNotEmpty($criteria);

        foreach ($criteria as $crit) {
            $this->assertArrayHasKey('description', $crit);
            $this->assertNotEmpty($crit['description']);
            $this->assertNotEmpty($crit['levels']);
            $maxscore = max(array_column($crit['levels'], 'score'));
            $this->assertGreaterThan(0, $maxscore);
            foreach ($crit['levels'] as $level) {
                $this->assertNotEmpty($level['definition']);
            }
        }
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_guide_definition_max_scores_match_rubric(string $tplclass): void {
        $rubric = $tplclass::rubric_definition();
        $guide = $tplclass::guide_definition();

        $rubricbylabel = [];
        foreach ($rubric['definition']['rubric']['criteria'] as $c) {
            $rubricbylabel[$c['description']] = max(array_column($c['levels'], 'score'));
        }
        foreach ($guide['definition']['guide']['criteria'] as $c) {
            $this->assertEquals(
                $rubricbylabel[$c['shortname']],
                $c['maxscore'],
                "Guide max score for '{$c['shortname']}' should mirror rubric top"
            );
        }
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_mappings_use_canonical_metric_keys(string $tplclass): void {
        $valid = array_merge(metrics::metric_keys(), [metrics::METRIC_COMPOSITE]);
        foreach ($tplclass::rubric_definition()['mappings'] as $mapping) {
            $this->assertContains($mapping['metric'], $valid,
                "{$tplclass} uses unknown metric '{$mapping['metric']}'");
        }
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_every_mapping_links_to_an_existing_criterion(string $tplclass): void {
        $payload = $tplclass::rubric_definition();
        $labels = array_column($payload['definition']['rubric']['criteria'], 'description');
        foreach ($payload['mappings'] as $mapping) {
            $this->assertContains($mapping['shortname'], $labels);
        }
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_analytic_groups_are_referenced_by_criteria(string $tplclass): void {
        $groups = $tplclass::analytic_groups();
        if (empty($groups)) {
            $this->markTestSkipped("{$tplclass} doesn't define analytic groups.");
        }
        $criteria = $tplclass::rubric_definition()['definition']['rubric']['criteria'];
        foreach ($criteria as $crit) {
            $matched = false;
            foreach ($groups as $glabel) {
                if (str_starts_with($crit['description'], $glabel . ' — ')) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched,
                "{$tplclass} criterion '{$crit['description']}' has no matching group prefix");
        }
    }

    /**
     * @dataProvider all_templates_provider
     */
    public function test_infer_group_round_trip(string $tplclass): void {
        $criteria = $tplclass::rubric_definition()['definition']['rubric']['criteria'];
        foreach ($criteria as $crit) {
            $key = $tplclass::infer_group_from_label($crit['description']);
            $this->assertNotNull($key,
                "{$tplclass} cannot infer group for its own criterion '{$crit['description']}'");
            $this->assertContains($key, array_keys($tplclass::analytic_groups()));
        }
    }

    public function test_infer_group_returns_null_for_custom_label(): void {
        $this->assertNull(coi::infer_group_from_label('Just a custom criterion'));
        $this->assertNull(quantity_quality::infer_group_from_label('Random text'));
        $this->assertNull(inclusive::infer_group_from_label('Some other criterion'));
    }

    public function test_coi_has_four_criteria_two_per_presence(): void {
        $payload = coi::rubric_definition();
        $criteria = $payload['definition']['rubric']['criteria'];
        $this->assertCount(4, $criteria);

        $bygroup = ['cognitive' => 0, 'social' => 0];
        foreach ($criteria as $crit) {
            $key = coi::infer_group_from_label($crit['description']);
            $bygroup[$key] = ($bygroup[$key] ?? 0) + 1;
        }
        $this->assertSame(2, $bygroup['cognitive']);
        $this->assertSame(2, $bygroup['social']);
    }

    public function test_inclusive_has_one_criterion_per_modality(): void {
        $criteria = inclusive::rubric_definition()['definition']['rubric']['criteria'];
        $this->assertCount(4, $criteria);
        $groups = inclusive::analytic_groups();
        $this->assertCount(4, $groups);
        $this->assertEqualsCanonicalizing(
            ['oral', 'written', 'responsive', 'group'],
            array_keys($groups)
        );
    }

    /**
     * @return array<string, array<int, class-string<template>>>
     */
    public static function all_templates_provider(): array {
        return [
            'coi'              => [coi::class],
            'quantity_quality' => [quantity_quality::class],
            'inclusive'        => [inclusive::class],
        ];
    }
}
