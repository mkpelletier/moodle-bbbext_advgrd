# Advanced Grading for BigBlueButton (`bbbext_advgrd`)

A sub-plugin of `mod_bigbluebuttonbn` that lets teachers grade live BigBlueButton
sessions with rubrics or marking guides — wired to the BBB engagement signals
(attendance, mic time, chat, raise-hand, polls, emoji) — and anchored in
participation-assessment research rather than ad-hoc criteria.

## What it does

- Brings Moodle's standard rubric and marking-guide editors to BBB activities
  (the editor lives in the BBB activity's secondary navigation, mirroring the
  pattern of `mod_assign`).
- Surfaces each student's BBB engagement metrics next to the rubric while
  grading, and *suggests* a level for any criterion the teacher has mapped to
  a metric — never auto-grades.
- Ships three research-anchored starter templates:
  - **Community of Inquiry** (Garrison, Anderson & Archer, 2000) — revised for
    a single live session: two cognitive criteria, two social.
  - **Quantity + Quality** (Bean & Peterson, 1998; Armstrong & Boud, 1983) —
    splits participation into a measurable "quantity" axis and a
    judgement-only "quality" axis.
  - **Inclusive (multi-modal)** (Shi & Tan, 2020; Crosthwaite, Bailey &
    Meeker, 2015; Tani, 2008) — one criterion per modality (oral, written,
    responsive, group work) so a student strong in one isn't penalised for
    being silent in another.
- Optionally pushes per-template-group sub-scores to the gradebook as
  separate grade items (analytic mode), so longitudinal reports can track
  development of each dimension.

## Requirements

- Moodle 5.0 or later.
- `mod_bigbluebuttonbn` (now part of Moodle core).
- `gradingform_rubric` and `gradingform_guide` (both bundled with Moodle).

## Install

1. Copy the plugin to `mod/bigbluebuttonbn/extension/advgrd/` in your Moodle
   tree, or download a release archive and extract there.
2. Visit *Site administration → Notifications* (or run
   `php admin/cli/upgrade.php`) so Moodle picks up the new sub-plugin.

## Use

1. Edit a BigBlueButton activity. Set a numeric **maximum grade** (the rubric
   score won't reach the gradebook otherwise — the plugin enforces this).
2. In the new **Advanced grading** section, choose **Rubric** or
   **Marking guide**, set composite or analytic score mode, and save.
3. From the activity's secondary navigation:
   - **Advanced grading** — the standard Moodle rubric/guide editor.
   - **Import template** — pick one of the three starter templates.
   - **Metric mappings** — link rubric criteria to BBB engagement signals and
     set thresholds for the suggested levels.
   - **Participation grading** — the per-student grading list with metrics
     visible next to each row.

## Privacy

The plugin stores per-user grades and a JSON snapshot of the BigBlueButton
engagement metrics that were active at the moment of grading
(`bbbext_advgrd_grade`). The privacy provider exports these on data-subject
requests and supports deletion in line with the GDPR. See
[`classes/privacy/provider.php`](classes/privacy/provider.php).

## License

GPL v3 or later — see [`LICENSE`](LICENSE).

## Citation

The participation-assessment framing draws on Simon, Jiang & Fryer's *Higher
Education Research & Development* article on assessing class participation
in online and offline learning environments (2025), as well as the older
literature cited in each template's `citation()` string.
