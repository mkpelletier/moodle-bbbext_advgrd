# Changelog

All notable changes to `bbbext_advgrd` are documented here.

## [0.1.1] — 2026-05-26

### Fixed

- `mod_bigbluebuttonbn\extension::get_join_tables()` builds a single SQL that
  LEFT-JOINs the BBB instance row against every additional table declared by
  sub-plugins. Listing our 1:N tables (`bbbext_advgrd_metric_map`,
  `bbbext_advgrd_grade`) there made BBB's `get_instance_info_retriever()`
  return duplicate course-module rows ("Did you remember to make the first
  column something unique … Duplicate value found in column 'cid'"). Only
  the 1:1 `bbbext_advgrd_config` table now stays in `get_join_tables()`.
- Earlier the same code path emitted
  "get_instance_additional_tables: bbbext_advgrd_metric_map should have a
  column named bigbluebuttonid" because the additional-tables filter requires
  a `bigbluebuttonbnid` column. We add that column as a denormalised FK on
  both `metric_map` and `grade` so that scoped queries (privacy export,
  future backup steps) can resolve rows to a BBB instance directly without
  joining through `config`. An `upgrade.php` step backfills the column on
  existing installs.
- Every insert site that writes to `metric_map` or `grade` now populates the
  new column (`grader::import_template`, `grader::save_metric_mappings`,
  `grader::record_grade`, `broker_meeting_events_addons::process_action`,
  generator's `seed_evidence`).

## [0.1.0] — 2026-05-08

Initial public beta.

### Added

- Sub-plugin scaffolding: capabilities, language strings, `db/install.xml`
  with three plugin tables (`bbbext_advgrd_config`,
  `bbbext_advgrd_metric_map`, `bbbext_advgrd_grade`), site-level settings.
- BigBlueButton extension hook implementations:
  - `mod_form_addons` — adds an "Advanced grading" section to the activity
    edit form (grading method, score mode, gradebook passthrough). Validates
    that a numeric maximum grade is set.
  - `mod_instance_helper` — persists per-instance config and cleans up rows
    + analytic gradebook items on activity delete.
  - `broker_meeting_events_addons` — snapshots per-attendee engagement
    metrics into `bbbext_advgrd_grade.evidence` on the BBB end-of-meeting
    webhook, accumulating across multiple sessions.
- Self-registered grading area (`component=bbbext_advgrd`,
  `area=participation`) so the standard `gradingform_rubric` and
  `gradingform_guide` editors work without patching `mod_bigbluebuttonbn`.
  Item-name → item-number mapping declared via
  `bbbext_advgrd\grades\gradeitems`.
- Three research-anchored starter templates with shared blueprint
  (`classes/local/templates/`): Community of Inquiry; Quantity + Quality;
  Inclusive multi-modal. Each emits both a rubric and a marking-guide
  payload from a single criteria definition.
- Engagement-metric reader (`classes/local/metrics.php`): canonical metric
  keys, attendee-payload extraction (handling new and old BBB shapes),
  log-aggregation fallback, composite scoring with site-default weights,
  threshold→level suggestion.
- Grader orchestrator (`classes/local/grader.php`): bootstrap, grading
  manager, template import (refuses overwrite), metric-mapping persistence,
  level suggestion, grade recording, gradebook passthrough, analytic-mode
  per-group grade items.
- Teacher-facing pages (`pages/`): templates picker, metric mappings,
  per-user grading list, single-user grading form with evidence panel and
  suggested-level badge highlighting.
- Secondary-navigation integration via the `before_http_headers` hook
  (Moodle 5's `secondary_extend` only fires for course-level pages, so we
  inject nodes into `$PAGE->settingsnav`'s `modulesettings` instead).
- Privacy provider declaring `bbbext_advgrd_grade` and supporting
  contextlist / userlist / export / delete operations.
- AMD module and CSS that highlight the suggested rubric level cell during
  grading.
- PHPUnit coverage: 65 tests across grader, metrics, and the three
  templates (registry, structural validation, group-prefix round-trip).
- Behat features (configure flow + grade-with-evidence flow) + custom
  step definitions for seeding fixtures.

### Notes

- Requires Moodle 5.0+ (4.5 dropped — reaching end of life).
- Maturity: BETA. Schema and APIs may change before 1.0.
