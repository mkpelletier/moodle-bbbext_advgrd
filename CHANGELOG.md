# Changelog

All notable changes to `bbbext_advgrd` are documented here.

## [0.2.0] — 2026-06-12

### Added

- **Recording annotation overlay** on the per-user grading page. Below the
  rubric form, when the BBB activity has recordings, a new pane shows the
  recording in an HTML5 `<video>` (with click-to-seek) or an iframe to BBB's
  hosted player (read-only) plus a timeline strip with colored markers per
  comment, a moving playhead, a current-time readout, and a rich-text
  comment editor.
- **Audio recording is native to the editor.** The Atto/TinyMCE editor's
  file picker enables built-in audio recording (and image / video embedding)
  via the standard Moodle `recordrtc` integration. The recording is
  embedded directly in the comment body as a Moodle file - no custom
  recorder code, no separate audio mode, no dedicated upload endpoint.
- **Server-side probe of BBB's `/capture/` playback page** caches a
  directly-playable media URL per recording in `bbbext_advgrd_rec_probe`,
  so the own-player path skips the network round-trip on subsequent grading
  visits. Falls back to status=iframe when the capture format is absent.
- **Privacy provider** declares the new annotation table + comment filearea
  and walks both target-student and grader-author paths during export, with
  audit-policy delete semantics (target rows + files purged, grader
  references anonymised).
- **External AJAX endpoints** for add / delete / list annotations and probe
  recording, all gated by `bbbext/advgrd:grade`.
- **PHPUnit coverage** for the CRUD service: create + reject paths,
  media-only body, scoped list, delete-cascades-files, update,
  context_for_annotation.

### Notes

- Schema: `bbbext_advgrd_annotation` (rich-text body + bodyformat +
  commenttype) and `bbbext_advgrd_rec_probe` (media-URL cache), both
  created at savepoint 2026061201.
- JS is inline via `$PAGE->requires->js_amd_inline()` to skip the
  grunt-amd staleness wall that retired the original on-disk AMD in 0.1.1
  (commit 1ff8e36).
- Markers on the timeline only auto-update + seek in **own-player** mode
  (HTML5 `<video>`). In iframe mode the markers display but click-to-seek
  surfaces a polite "can't seek into iframe" notice. BBB's iframe player
  has no JS API to drive from outside.
- `local_unifiedgrader` is **not** touched in this release. The annotation
  overlay is only on `bbbext_advgrd`'s own per-user grading page. A later
  release will add a generic feedback-panel hook in `local_unifiedgrader`
  that `bbbext_advgrd` subscribes to.
- The previous v0.2.0 (tabs + custom MediaRecorder) was reverted in commit
  `12d9548` before this release. See that commit for the rationale.

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
