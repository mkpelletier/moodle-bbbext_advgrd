# Changelog

All notable changes to `bbbext_advgrd` are documented here.

## [0.4.3] — 2026-09-07

### Security

- **Annotation bodies returned by the AJAX endpoints were not cleaned.**
  `shaper::shape_row()` handed back the stored editor HTML with only the `@@PLUGINFILE@@` rewrite applied, and the overlay assigns that string to `innerHTML` — so script in a comment body executed in the reader's browser on the add-and-list path, even though the server-rendered path had always run `format_text()`. The shaper now applies the same `format_text()` with cleaning enabled (`'noclean' => false`),
  which also fixes a cosmetic mismatch: a just-posted comment now renders exactly as it does after a reload.
- **Comment-library snippets are cleaned on the way in and on the way out.**
  A course-scoped snippet is read by graders other than its author, so `comment_library::save()` now runs `clean_text()` before storing, and `comment_library::fetch()` cleans on read as well so rows written before this release cannot carry script into another grader's editor.
- **`recordingid` is validated as `PARAM_ALPHANUMEXT` everywhere.** A BBB `recordID` is `<internal-meeting-sha1>-<epoch-millis>`, so the constrained type accepts every legitimate id.
- **`bbbext_advgrd_probe_recording` declares `mediaurl` as `PARAM_URL`.** The client assigns it straight to `<video>.src`, so the returned type is now one that rejects a `javascript:` payload.
- The `PARAM_RAW` declarations that remain are the editor-HTML fields, which no narrower type could carry. Each now documents where its cleaning happens.

### Fixed

- **The grading-area picker showed the literal `[[gradeitem:participation]]`.**
  Once `classes/grades/gradeitems.php` declared the area through
  `component_gradeitems`, core stopped calling the legacy
  `bbbext_advgrd_grading_areas_list()` callback and started labelling the area
  with `get_string('gradeitem:participation')` instead — a string the lang file
  never defined. Added, along with `grade_participation_name`, which
  `course/moodleform_mod.php` and the completion form use to name the grade item.
  New `gradeitems_test.php` derives both key names from the mappings themselves,
  so adding or renaming an item now fails the build rather than the UI.
  Resolves #6.
- Author lookups for annotation bylines selected only `firstname`/`lastname` and
  passed that partial record to `fullname()`, which emitted a `debugging()`
  warning on every call under developer debugging. Both call sites now select the
  full name-field set via `\core_user\fields::get_name_fields()`.
- **`media_proxy` could have fatalled with "Class \"curl\" not found".** The curl wrapper
  lives in `lib/filelib.php`, which `lib/setup.php` loads only under some configurations, so
  an autoloaded class cannot assume the requesting page pulled it in — and `pages/play.php`
  bootstraps Moodle with a bare `require` of `config.php`. `make_curl()` now requires filelib
  itself before constructing the client.
- **The two correlated backfills in `db/upgrade.php` aliased the table they were
  updating** (`UPDATE {table} m SET ... WHERE ... m.configid`). SQL Server rejects
  that form — it spells the same statement `UPDATE <alias> ... FROM` — so the
  0.3.x → 0.4.x upgrade step would have failed there. Both statements now name the
  updated table in full instead of aliasing it.

### Changed

- **No plugin code is left in PHP's global namespace.** `pages/play.php` declared six
  `advgrd_play_*` functions and an `ADVGRD_COOKIE_TTL` constant at global scope. The
  `advgrd_` prefix is not frankenstyle — the component is `bbbext_advgrd` — so any other
  plugin (or core) defining a function of the same name would have caused a fatal
  redeclaration. Rather than only re-prefixing them, the whole media-proxy implementation
  moved into the new `bbbext_advgrd\local\media_proxy` class, which puts it behind the
  component's own namespace where a collision is impossible, and leaves `pages/play.php` as
  a bare entry point. Behaviour is unchanged: the same handshake, the same per-user cookie
  jar, the same byte-range forwarding. Resolves #2.
- The `$advgrdpathparts` scratch variable each page used to locate `config.php` is gone;
  the path is now computed inline with `array_slice()`. It existed for three lines but lived
  in the global scope `config.php` is about to populate, which is the same collision risk in
  a smaller form.
- Two guards that `pages/play.php` had inline — the "is there a usable probe row" check and
  the same-host/scheme pin that stops the endpoint becoming an open proxy — are now
  `media_proxy::probe_is_proxyable()`, and the cookie-jar age check is
  `media_proxy::jar_is_stale()`. Both are covered by the new `media_proxy_test.php`, so the
  open-proxy pin is asserted rather than merely commented.
- **The media proxy goes through Moodle's `\curl` wrapper instead of calling `curl_init()`
  directly.** Both legs — the `/capture/` cookie handshake and the byte-range media stream —
  were driving the cURL extension by hand, so a site's `$CFG->proxyhost` settings and its
  `curlsecurityblockedhosts` / `curlsecurityallowedport` blocklist did not apply to them, unlike
  every other outbound request Moodle makes (the recording probe already used the wrapper). Both
  now build their client through `media_proxy::make_curl()`. Most of the old hand-rolled option
  set is simply gone: the wrapper already pins the request *and every redirect hop* to
  HTTP/HTTPS, sends the moodlebot user agent, supplies the CA bundle, and re-checks each
  redirect target against the blocklist rather than letting cURL follow the chain on its own.
  Resolves #4.

  Two behaviours came along with the move. The client's `Range` header is now validated against
  a byte-range grammar before being forwarded upstream, where previously `$_SERVER['HTTP_RANGE']`
  was passed through verbatim. And because the wrapper owns `CURLOPT_HEADERFUNCTION`, the
  streamer reads each hop's status and headers from the wrapper's response state; that parsing
  is covered by new cases in `media_proxy_test.php`, alongside the `Range` grammar.

- `classes/privacy/provider.php` anonymises rater references with
  `$DB->set_field_select()` instead of two hand-written `UPDATE` statements. The
  `execute()` calls that remain in `db/upgrade.php` are correlated backfills with no
  specialised DML equivalent, and now carry a comment saying so. New
  `privacy_provider_test.php` pins the behaviour the rewritten statements have to keep:
  a listed user's own rows go, rows they merely rated stay with the rater reference
  cleared, and users outside the list are untouched. Resolves #5.
- **The privacy provider now documents what the plugin sends to BigBlueButton, which is
  nothing personal.** The plugin makes outbound HTTP requests to the BBB server, and the
  privacy API requires that either the user data sent there is declared with
  `add_external_location_link()` or the decision not to declare it is recorded. Every
  request — `probe_recording::execute()` scraping the playback page, and
  `media_proxy::handshake()`/`::stream()` fetching the media — is server-to-server and
  carries no Moodle user identifier: no user id, no name, no email, and not the viewer's IP
  address. All three go through Moodle's `\curl` wrapper, which builds each request from its
  own defaults rather than from the viewer's inbound one, so no client cookie, referer, or
  address is inherited; the only value that crosses is the `Range` header, forwarded so
  seeking works and already constrained to a byte-range pattern by `client_range()`.
  `get_metadata()` now sets all of this out, along with
  the one path that does put a browser in touch with BBB — the iframe fallback, whose target
  is a `bbb_view.php` URL belonging to `mod_bigbluebuttonbn` and already covered by that
  plugin's own declaration. No `add_external_location_link()` is added, because declaring
  fields the plugin does not transmit would misinform the site's privacy registry. Resolves #1.
- `privacy_provider_test.php` pins that decision two ways: one test fails if an external
  location is ever declared without the reasoning being revisited, and another asserts every
  declared metadata field resolves to a real lang string, since a missing one renders as
  `[[key]]` in the registry a DPO actually reads.
- Worth noting for the same audit: proxying the media through `pages/play.php` narrowed what
  reaches BBB. Before 0.4.2 the overlay pointed the browser's `<video>` straight at the BBB
  host, disclosing every viewer's IP address to it.

## [0.4.2] — 2026-08-25

### Fixed

- **Recordings rendered as a black box in the annotation player until the marker
  had first opened the recording from the BigBlueButton activity.** BBB gates its
  raw recording files behind an authorisation cookie that only its own playback
  page sets. The probe performed that handshake server-side with `curl`, so the
  cookie landed in a throwaway jar on the Moodle server while the browser —
  which was being pointed straight at the BBB host by `mountVideo()` — had none,
  and its request for the media was refused. Opening the recording from the
  activity is a top-level navigation, which set the cookie first-party and made
  every later request succeed, hence the "works once I launch it manually"
  symptom. Recording media is now proxied through `pages/play.php`, which replays
  the handshake server-side, caches the cookie per user and recording, and streams
  the bytes back from Moodle's own origin. HTTP byte ranges are forwarded in both
  directions so timeline click-to-seek keeps working.
- **A player that failed to load stayed a black box with no explanation.** The
  `<video>` element had no `error` handler, so a probe that succeeded followed by
  a stream that did not left the marker with nothing to look at and no fallback —
  even though the iframe path to BBB's hosted player was right there. It now
  degrades to that player, or to the "not available" notice when there is no
  playback URL at all.

### Changed

- `bbbext_advgrd_probe_recording` no longer returns the BBB media URL to the
  browser; it returns the `pages/play.php` proxy URL instead. The scraped URL is
  server-side only.
- New `captureurl` column on `bbbext_advgrd_rec_probe`, recording which BBB
  playback page each media URL was scraped from so the proxy can repeat the
  handshake. The upgrade clears the probe cache, since no existing row carries
  one; the next visit to each recording re-probes.

## [0.4.1] — 2026-08-18

### Fixed

- **The annotation player showed "The recording was not found." on grouped
  activities.** Where the probe cannot reach a directly playable media file, the
  player falls back to an iframe on BigBlueButton's hosted playback, via
  `bbb_view.php`. That URL carries no group, so `bbb_view.php` takes the viewer's
  sticky active group from `$SESSION` — whatever group they last chose from a
  group menu in that course — and filters the instance's recordings to it. A
  teacher whose sticky group was not the recording's got the "not found" error and
  a redirect to the activity page, rendered inside the player region. The fallback
  URL now pins `group` to the active recording's own group.

## [0.4.0] — 2026-07-16

### Fixed

- **Backup & restore support**, which was missing entirely — all extension data
  was silently dropped whenever a BigBlueButton activity was backed up, restored,
  imported into another course, or duplicated. The parent module invokes
  `add_subplugin_structure('bbbext', …)`, so each `bbbext_` subplugin must ship
  its own `backup/moodle2/` classes; ours did not exist. New
  `backup_bbbext_advgrd_subplugin` / `restore_bbbext_advgrd_subplugin` now carry:
  - `bbbext_advgrd_config` and `bbbext_advgrd_metric_map` (always — teacher setup);
  - `bbbext_advgrd_grade` (score + frozen evidence) and `bbbext_advgrd_annotation`
    (recording feedback, including embedded audio/image files) when user data is
    included;
  - the **advanced-grading definition itself** — the rubric/guide, its criteria,
    levels and comments, plus grading instances and per-criterion fillings. The
    plugin registers this under its own `bbbext_advgrd` grading component, which
    core's activity-grading backup (scoped to `mod_<modname>`) never captured, so
    the subplugin backs it up itself. On restore, criterion links on
    `metric_map` and the `gradinginstanceid` on each grade are remapped once the
    definition has been recreated.
  - Not included by design: `bbbext_advgrd_rec_probe` (a transient server-bound
    cache) and `bbbext_advgrd_comlib` (the comment library is scoped to a user or
    a course, not to a single activity).

## [0.3.1] — 2026-06-15

### Added

- **In-product documentation page** at `pages/help.php`, reached from a new
  *Help & documentation* entry in the BBB activity's secondary navigation
  (visible even when advanced grading hasn't been configured yet, so the
  setup walkthrough is accessible to first-time users). Covers eight
  standard topics: the grading interface, advanced grading configuration,
  included templates and their pedagogical foundations, metric mapping,
  participation grading, group handling, what constitutes a submission,
  and how multiple sessions accumulate. Content lives in lang strings so
  it's translatable.

## [0.3.0] — 2026-06-14

### Added

- **Reusable overlay renderer** (`bbbext_advgrd\local\overlay`) extracts the
  player + timeline + comment form + comment list + callout into one class
  so any host page can embed the overlay. Public entry point
  `bbbext_advgrd_render_overlay($cmid, $userid)` in `lib.php` lets other
  plugins integrate without a hard dependency (loose `function_exists`
  detection on the caller side).
- **Reusable comment library** (`bbbext_advgrd_comlib` table, three external
  endpoints) modelled on `assignsubmission_ytsubmission`. Personal + course-
  shared scopes, search, filter pills, scope picker on save. UI: Insert /
  Save buttons under the editor, slide-down panel with both libraries.
- **Media-comment callouts** over the recording instead of scrolling to the
  card:
  - **Video** comments → 230×230 circular bubble with custom playback
    controls (centred play/pause, slim progress strip, current-time
    readout) and an **expand toggle** that pops it out to a 480×300 rounded
    rectangle for screencast feedback that needs fine detail.
  - **Audio** comments → 280×180 squircle with the same control set.
  - **Text** comments → 280-wide squircle with the rendered HTML body and
    scroll for longer comments.
- **External chrome** for every callout: floating category chip (rgba so
  the video shows through) and a circular close button, both rendered as
  siblings of the bubble so the `overflow: hidden` clip can't touch them.
- **Timeline markers** carry FontAwesome glyphs (`fa-volume-up` for audio,
  `fa-video-camera` for video, coloured dot for text) and a hover tooltip
  with the category + timestamp + preview text. Markers near the bar's
  left / right edges pin the tooltip's anchor to keep it on-screen.
- **Student / read-only mode.** `overlay::render()` accepts a `$mode` arg
  (`MODE_GRADER` or `MODE_STUDENT`); the lib.php wrapper auto-detects mode
  from `$USER->id === $userid`. Student mode skips the editor, library,
  and delete buttons; `pluginfile` allows students to stream feedback
  files attached to their own annotations only.
- **`local_unifiedgrader` integration** — the BBB adapter calls our
  overlay renderer, `preview_bbb.mustache` falls back to the iframe path
  when the function isn't available. Fullscreen target falls through to
  `.advgrd-player-wrapper` so view_feedback.php works too.

### Fixed

- **Probe path 1**: regex now matches `<source src="…">` (the actual shape
  of BBB's `/capture/` HTML), not only `<video src="…">`.
- **Probe path 2**: try playback type `video` before `capture` — modern
  BBB builds expose the camera-only player under `video`.
- **Iframe URL double-encoding** — `(string) $url` invoked `out(true)`
  which HTML-escapes `&`, which `html_writer` then escaped again,
  producing `bn=0` server-side and a redirect to the site frontpage.
  Now uses `$url->out(false)` explicitly.
- **TinyMCE comment save** — `getContent()` follows `editor.save()`,
  matching ytsubmission. The `.catch()` surfaces real exceptions via
  `Notification.exception` rather than a generic "Could not save".
- `editor_options()` hard-codes `-1` for `maxfiles` because
  `EDITOR_UNLIMITED_FILES` (defined in `lib/formslib.php`) isn't loaded by
  Moodle's AJAX service router; the page-render path loaded it
  transitively via the rubric mform so only the AJAX add path tripped.
- Variant-class leak between callouts: clicking text → video opened the
  video as a squircle until the page was reloaded; the callout's class
  list is now reset between shows.

### Changed

- **Add Comment auto-grabs the playhead position** when the own-player is
  mounted - the teacher doesn't need to click ⏱ first. The mm:ss input
  still wins when the player isn't available (iframe fallback).
- **Comment cards mirror ytsubmission**: clock-icon timestamp pill (clickable
  to seek), human-readable category label (Praise / Correction / …) instead
  of the raw key, trash-icon delete button.
- New comments **append optimistically** with a fade-in and auto-scroll
  into view; deletions fade out and remove their timeline marker. Replaces
  the post-action full list re-fetch.

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
