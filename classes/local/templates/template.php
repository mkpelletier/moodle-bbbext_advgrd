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
 * Abstract base class for participation-grading rubric / marking-guide templates.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local\templates;

/**
 * Each concrete subclass packages a single research-anchored starter rubric (and matching marking
 * guide) that a teacher can import into their BigBlueButton activity. Subclasses declare:
 *   - a stable id (used in URLs, never localised),
 *   - a localised display name + short description + bibliographic citation,
 *   - the structured criteria definition consumed by gradingform_rubric_controller and
 *     gradingform_guide_controller,
 *   - optional metric→criterion mapping suggestions (so the imported rubric is wired up to BBB
 *     engagement signals out of the box),
 *   - optional analytic groupings (e.g., one grade item per CoI presence) — return an empty array
 *     to opt out and stay composite-only.
 *
 * Subclasses share two helper methods to assemble rubric/guide payloads from a single blueprint
 * so they're easy to maintain.
 */
abstract class template {
    /**
     * Stable string id (alphanumeric + underscore). Used in URLs and DB keys, never localised.
     */
    abstract public static function id(): string;

    /**
     * Localised display name shown to teachers in the picker.
     */
    abstract public static function name(): string;

    /**
     * One-paragraph localised description shown alongside the picker option.
     */
    abstract public static function description(): string;

    /**
     * Bibliographic citation(s) — short string, may be empty for templates without a primary source.
     */
    abstract public static function citation(): string;

    /**
     * Localised label for the rubric/guide definition itself (the title teachers will see in the
     * standard Moodle rubric editor after importing).
     */
    abstract public static function definition_name(): string;

    /**
     * The shared blueprint each concrete template returns. Each entry produces one rubric criterion
     * (and one marking-guide criterion). Format:
     *   [
     *     'group'      => string|null  // e.g., 'cognitive', 'quantity', 'oral'. Used for analytic grouping.
     *     'grouplabel' => string|null  // localised display label for the group.
     *     'criterion'  => string       // localised short title; rendered as "{Grouplabel} — {criterion}".
     *     'levels'     => array<int, ['score' => int, 'definition' => string]>  // ordered by ascending score.
     *     'metric'     => string|null  // canonical metric key from metrics::*; null = no auto-suggestion.
     *     'thresholds' => array        // [score => min metric value] — see metrics::suggest_level().
     *   ]
     *
     * @return array
     */
    abstract protected static function blueprint(): array;

    /**
     * Build a rubric definition payload + metric mapping suggestions.
     *
     * @return array{
     *   definition: array,
     *   mappings: array<int, array{shortname:string, metric:string, thresholds:array}>
     * }
     */
    public static function rubric_definition(): array {
        $criteria = [];
        $mappings = [];
        $sortorder = 0;

        foreach (static::blueprint() as $entry) {
            $sortorder++;
            $label = static::label($entry);
            $criteria['NEWID' . $sortorder] = [
                'sortorder'   => $sortorder,
                'description' => $label,
                'levels'      => static::rubric_levels($entry['levels']),
            ];
            if (!empty($entry['metric'])) {
                $mappings[] = [
                    'shortname'  => $label,
                    'metric'     => $entry['metric'],
                    'thresholds' => $entry['thresholds'] ?? [],
                ];
            }
        }

        return [
            'definition' => [
                'name'              => static::definition_name(),
                'description_editor' => [
                    'text'   => static::description(),
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ],
                'status'      => 20, // Equivalent to gradingform_controller::DEFINITION_STATUS_READY.
                'rubric'      => [
                    'criteria' => $criteria,
                    'options'  => [
                        'sortlevelsasc'                 => 1,
                        'lockzeropoints'                => 1,
                        'showdescriptionteacher'        => 1,
                        'showdescriptionstudent'        => 1,
                        'showscoreteacher'              => 1,
                        'showscorestudent'              => 1,
                        'enableremarks'                 => 1,
                        'showremarksstudent'            => 1,
                        'allowgradedecimals'            => 1,
                    ],
                ],
            ],
            'mappings' => $mappings,
        ];
    }

    /**
     * Build a marking-guide definition payload + metric mapping suggestions.
     */
    public static function guide_definition(): array {
        $criteria = [];
        $mappings = [];
        $sortorder = 0;

        foreach (static::blueprint() as $entry) {
            $sortorder++;
            $label = static::label($entry);
            $maxscore = max(array_column($entry['levels'], 'score'));
            $criteria['NEWID' . $sortorder] = [
                'sortorder'           => $sortorder,
                'shortname'           => $label,
                'description'         => static::level_summary($entry['levels']),
                'descriptionmarkers'  => static::level_summary($entry['levels']),
                'maxscore'            => $maxscore,
            ];
            if (!empty($entry['metric'])) {
                $mappings[] = [
                    'shortname'  => $label,
                    'metric'     => $entry['metric'],
                    'thresholds' => $entry['thresholds'] ?? [],
                ];
            }
        }

        return [
            'definition' => [
                'name'              => static::definition_name(),
                'description_editor' => [
                    'text'   => static::description(),
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ],
                'status'      => 20,
                'guide'       => [
                    'criteria' => $criteria,
                    'options'  => [
                        'alwaysshowdefinition' => 1,
                        'showmarkspercriterionstudents' => 1,
                    ],
                    'comments' => [],
                ],
            ],
            'mappings' => $mappings,
        ];
    }

    /**
     * The set of analytic groups this template defines, in canonical order. Each entry is
     * ['key' => 'localised label']. Return an empty array if the template doesn't expose a
     * sensible grouping (analytic-mode grading will gracefully fall back to composite).
     *
     * @return array<string, string>
     */
    public static function analytic_groups(): array {
        $groups = [];
        foreach (static::blueprint() as $entry) {
            if (!empty($entry['group']) && !empty($entry['grouplabel'])) {
                $groups[$entry['group']] = $entry['grouplabel'];
            }
        }
        return $groups;
    }

    /**
     * Infer the group key of a criterion by matching its label against this template's
     * registered groups. Returns null when no group prefix matches (e.g., teacher renamed the
     * criterion in the standard rubric editor).
     *
     * @param string $label
     * @return string|null
     */
    public static function infer_group_from_label(string $label): ?string {
        foreach (static::analytic_groups() as $key => $glabel) {
            if (str_starts_with($label, $glabel . ' — ')) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Render a "{Group} — {Criterion}" label, or just "{Criterion}" if the entry has no group.
     *
     * @param array $entry
     * @return string
     */
    protected static function label(array $entry): string {
        if (!empty($entry['grouplabel'])) {
            return $entry['grouplabel'] . ' — ' . $entry['criterion'];
        }
        return $entry['criterion'];
    }

    /**
     * Build rubric levels keyed by NEWIDn placeholders.
     *
     * @param array $levels
     * @return array
     */
    protected static function rubric_levels(array $levels): array {
        $out = [];
        foreach ($levels as $idx => $level) {
            $out['NEWID' . ($idx + 1)] = [
                'score'      => (float) $level['score'],
                'definition' => $level['definition'],
            ];
        }
        return $out;
    }

    /**
     * Render a level summary used for the marking-guide description fields.
     *
     * @param array $levels
     * @return string
     */
    protected static function level_summary(array $levels): string {
        $lines = [];
        foreach ($levels as $level) {
            $lines[] = '(' . $level['score'] . ') ' . $level['definition'];
        }
        return implode("\n", $lines);
    }
}
