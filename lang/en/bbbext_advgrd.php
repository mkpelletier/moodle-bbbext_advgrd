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
 * English language strings for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['advgrd:grade'] = 'Grade BigBlueButton participation using a rubric or marking guide';
$string['advgrd:manage'] = 'Configure advanced grading for a BigBlueButton activity';
$string['advgrd:viewownreport'] = 'View own BigBlueButton participation grade and feedback';
$string['analytic_grade_itemname'] = '{$a->activity}: {$a->presence}';
$string['annotate_add'] = 'Add comment';
$string['annotate_body'] = 'Comment';
$string['annotate_category'] = 'Category';
$string['annotate_category_correction'] = 'Correction';
$string['annotate_category_general'] = 'General';
$string['annotate_category_praise'] = 'Praise';
$string['annotate_category_question'] = 'Question';
$string['annotate_category_suggestion'] = 'Suggestion';
$string['annotate_comment_for'] = 'Comments for {$a}';
$string['annotate_currenttime'] = 'Current time';
$string['annotate_delete_confirm'] = 'Delete this comment?';
$string['annotate_existing'] = 'Existing comments';
$string['annotate_heading'] = 'Recording annotations';
$string['annotate_iframe_notice'] = 'The recording is shown in the BBB hosted player. Timestamp markers below the player are display-only; click "Use current time" after the player position is right.';
$string['annotate_no_comments'] = 'No comments yet.';
$string['annotate_no_playback'] = 'No playback URL is available yet for this recording. BBB may still be processing it.';
$string['annotate_no_recordings'] = 'This BigBlueButton activity has no recordings yet.';
$string['annotate_recording_picker'] = 'Recording';
$string['annotate_save_failed'] = 'Could not save the comment. Try again.';
$string['annotate_seek_unsupported'] = 'Click-to-seek is only available when the recording is played in the embedded player. In BBB iframe mode, scrub the recording manually.';
$string['annotate_timestamp'] = 'Timestamp';
$string['annotate_use_current_time'] = 'Use current video time';
$string['annotate_video_unavailable'] = 'The recording player is not available.';
$string['annotation_badcategory'] = 'Unknown annotation category: {$a}.';
$string['annotation_emptybody'] = 'A comment cannot be empty.';
$string['coi_crit_cohesion'] = 'Group cohesion';
$string['coi_crit_connecting'] = 'Connecting ideas';
$string['coi_crit_opencomm'] = 'Open communication';
$string['coi_crit_triggering'] = 'Triggering inquiry';
$string['coi_lvl_cohesion_0'] = 'Absent or attended only briefly; no signals of belonging.';
$string['coi_lvl_cohesion_1'] = 'Attended a substantial portion of the session; minimal addressing of peers by name.';
$string['coi_lvl_cohesion_2'] = 'Attended fully; addresses peers by name, uses inclusive language, contributes to a shared sense of group.';
$string['coi_lvl_connecting_0'] = 'Treats contributions as isolated; does not connect ideas.';
$string['coi_lvl_connecting_1'] = 'Occasionally relates a comment to a previous one or to course material.';
$string['coi_lvl_connecting_2'] = 'Frequently links concepts, peers\' contributions, and course material into coherent positions.';
$string['coi_lvl_connecting_3'] = 'Synthesises multiple sources into a novel argument or resolution within the session.';
$string['coi_lvl_opencomm_0'] = 'Microphone never used; does not respond to peers.';
$string['coi_lvl_opencomm_1'] = 'Uses microphone briefly; responds when directly addressed.';
$string['coi_lvl_opencomm_2'] = 'Speaks up several times; responds to and quotes peers.';
$string['coi_lvl_opencomm_3'] = 'Sustains open dialogue: speaks at length, replies to multiple peers, expresses agreement and disagreement constructively.';
$string['coi_lvl_triggering_0'] = 'No questions raised; no problems identified.';
$string['coi_lvl_triggering_1'] = 'Asks a single clarifying question or signals confusion once.';
$string['coi_lvl_triggering_2'] = 'Identifies issues or surfaces problems on more than one occasion.';
$string['coi_lvl_triggering_3'] = 'Repeatedly initiates inquiry: poses substantive questions that drive the class\'s thinking forward.';
$string['coi_presence_cognitive'] = 'Cognitive presence';
$string['coi_presence_social'] = 'Social presence';
$string['coi_presence_teaching'] = 'Teaching presence';
$string['column_criterion'] = 'Criterion';
$string['column_grade'] = 'Final grade';
$string['column_metric'] = 'BigBlueButton metric';
$string['column_thresholds'] = 'Thresholds (JSON)';
$string['column_user'] = 'Student';
$string['column_value'] = 'Value';
$string['column_weight'] = 'Weight';
$string['configure_back_to_activity'] = 'Back to activity';
$string['configure_edit_definition'] = 'Open advanced grading editor';
$string['configure_link'] = 'Configure rubric and metric mappings';
$string['configure_mappings_help'] = 'Thresholds are JSON: keys are level scores, values are the minimum metric value to suggest that score (e.g., {"3":600,"2":240,"1":60,"0":0} for talk-time in seconds).';
$string['configure_mappings_saved'] = 'Metric mappings saved.';
$string['configure_method_label'] = 'Current grading method: <strong>{$a}</strong>';
$string['configure_no_method'] = 'No advanced grading method has been chosen for this activity yet. Open the activity edit form, expand "Advanced grading", and select Rubric or Marking guide.';
$string['configure_open_grading'] = 'Go to grading list →';
$string['configure_open_modedit'] = 'Open activity edit form';
$string['configure_title'] = 'Configure advanced grading';
$string['error_definition_exists'] = 'A rubric or marking guide is already defined for this activity. Edit it in the standard Moodle editor, or delete it first if you want to import a different template.';
$string['error_noconfig'] = 'No advanced grading configuration row exists for this activity.';
$string['error_nogradingmethod'] = 'No advanced grading method is configured for this activity.';
$string['evidence_heading'] = 'Engagement evidence';
$string['form_err_gradezero'] = 'Set a numeric maximum grade for this activity (in the Grade section above) before enabling advanced grading — otherwise the rubric score cannot reach the gradebook.';
$string['form_err_invalidmethod'] = 'Invalid grading method.';
$string['form_err_invalidmode'] = 'Invalid score mode.';
$string['form_gradingmethod'] = 'Grading method';
$string['form_gradingmethod_guide'] = 'Marking guide';
$string['form_gradingmethod_help'] = 'Choose a rubric or marking guide to grade student participation. Selecting "None" leaves the activity using its default numeric grade.';
$string['form_gradingmethod_none'] = 'None (use default grade)';
$string['form_gradingmethod_rubric'] = 'Rubric';
$string['form_passthrough'] = 'Send rubric score to the gradebook';
$string['form_scoremode'] = 'Score mode';
$string['form_scoremode_analytic'] = 'Analytic — one grade per template group';
$string['form_scoremode_composite'] = 'Composite — one grade';
$string['form_scoremode_help'] = 'Composite mode pushes one overall grade to the gradebook. Analytic mode creates one grade item per template group (e.g., per Community of Inquiry presence) — useful for tracking development of each dimension separately.';
$string['form_secondary_nav_hint'] = 'After saving, define your rubric or marking guide via the activity\'s "Advanced grading" entry in the secondary navigation. The "Import template" entry there offers research-anchored starter rubrics.';
$string['formheader'] = 'Advanced grading';
$string['grade_back_to_list'] = '← Back to grading list';
$string['grade_label'] = 'Rubric / marking guide';
$string['grade_saved'] = 'Saved grade for {$a}.';
$string['grade_user_action'] = 'Grade';
$string['grade_user_heading'] = 'Grading: {$a}';
$string['grading_list_no_definition'] = 'A grading method is selected, but no rubric or marking guide has been defined yet.';
$string['grading_list_no_method'] = 'Advanced grading is not enabled for this activity. Open the activity edit form to set a grading method.';
$string['grading_list_no_users'] = 'No enrolled students to display.';
$string['grading_list_title'] = 'Participation grading';
$string['gradingarea_participation'] = 'BigBlueButton participation';
$string['inclusive_crit_groupwork'] = 'Group work';
$string['inclusive_crit_oral'] = 'Oral contribution';
$string['inclusive_crit_responsive'] = 'Responsive engagement';
$string['inclusive_crit_written'] = 'Written contribution';
$string['inclusive_group_modality'] = 'Modality';
$string['inclusive_lvl_groupwork_0'] = 'Did not engage in breakout / group activities (or not present for them).';
$string['inclusive_lvl_groupwork_1'] = 'Present in breakout but contributed minimally.';
$string['inclusive_lvl_groupwork_2'] = 'Contributed materially to breakout discussion or task.';
$string['inclusive_lvl_groupwork_3'] = 'Took initiative in breakout: organised the group, advanced the task, or reported back substantively.';
$string['inclusive_lvl_oral_0'] = 'No microphone use during the session.';
$string['inclusive_lvl_oral_1'] = 'Brief microphone use; responds when directly addressed.';
$string['inclusive_lvl_oral_2'] = 'Speaks up several times unprompted; substantive spoken contributions.';
$string['inclusive_lvl_oral_3'] = 'Sustains spoken dialogue throughout; responds to and builds on peers verbally.';
$string['inclusive_lvl_responsive_0'] = 'No reactions, hand-raises, or poll responses.';
$string['inclusive_lvl_responsive_1'] = 'Occasional reactions or a single hand-raise / poll vote.';
$string['inclusive_lvl_responsive_2'] = 'Multiple reactions, hand-raises, or poll responses across the session.';
$string['inclusive_lvl_responsive_3'] = 'Consistently responsive throughout: reactions, hand-raises, poll participation, and follow-through behaviours.';
$string['inclusive_lvl_written_0'] = 'No use of chat, Q&A, or collaborative documents.';
$string['inclusive_lvl_written_1'] = 'A small number of short written contributions.';
$string['inclusive_lvl_written_2'] = 'Several written contributions, including responses to peers in chat.';
$string['inclusive_lvl_written_3'] = 'Sustained, substantive written contribution; uses chat or shared notes to deepen the discussion.';
$string['mappings_intro'] = 'Active definition: <em>{$a->definition}</em>. For each criterion below you can optionally link a BigBlueButton engagement metric so the grading page suggests a level when you grade a student.';
$string['mappings_no_definition'] = 'No rubric or marking guide has been defined yet. Import a starter template, or define one from scratch via "Advanced grading".';
$string['mappings_title'] = 'Metric mappings';
$string['metric_chats'] = 'Chat messages';
$string['metric_composite'] = 'Composite engagement score';
$string['metric_duration'] = 'Attendance time';
$string['metric_emojis'] = 'Emoji reactions';
$string['metric_none'] = '— None —';
$string['metric_polls'] = 'Poll votes';
$string['metric_raisehand'] = 'Raise hand';
$string['metric_talks'] = 'Mic talk time';
$string['nav_advanced_grading'] = 'Advanced grading';
$string['nav_advgrd'] = 'BBB advanced grading';
$string['pluginname'] = 'BBB Advanced Grading';
$string['privacy:metadata:bbbext_advgrd_annotation'] = 'Per-(recording, student) timestamped feedback comments authored by teachers. Bodies are rich text and may contain audio recordings made by the teacher via the editor.';
$string['privacy:metadata:bbbext_advgrd_annotation:body'] = 'The HTML body of the comment, including embedded audio/image attachments.';
$string['privacy:metadata:bbbext_advgrd_annotation:commenttype'] = 'Category of the comment (general, praise, correction, suggestion, question).';
$string['privacy:metadata:bbbext_advgrd_annotation:graderid'] = 'The teacher who authored the comment.';
$string['privacy:metadata:bbbext_advgrd_annotation:recordingid'] = 'The BBB recording the comment is anchored to.';
$string['privacy:metadata:bbbext_advgrd_annotation:targetuserid'] = 'The student the comment is addressed to.';
$string['privacy:metadata:bbbext_advgrd_annotation:timecreated'] = 'When the comment was recorded.';
$string['privacy:metadata:bbbext_advgrd_annotation:timestampms'] = 'Position in the recording, in milliseconds, that the comment anchors to.';
$string['privacy:metadata:bbbext_advgrd_comment'] = 'Files (audio recordings, images) attached to annotation comment bodies. Stored in the standard Moodle file area; access governed by the activity context.';
$string['privacy:metadata:bbbext_advgrd_grade'] = 'Per-user grade and engagement evidence captured for BigBlueButton activities graded with this extension.';
$string['privacy:metadata:bbbext_advgrd_grade:evidence'] = 'A JSON snapshot of BigBlueButton engagement metrics at the moment of grading.';
$string['privacy:metadata:bbbext_advgrd_grade:finalscore'] = 'The final score pushed to the gradebook.';
$string['privacy:metadata:bbbext_advgrd_grade:graderid'] = 'The user who recorded the grade.';
$string['privacy:metadata:bbbext_advgrd_grade:rawscore'] = 'The raw score produced by the rubric or marking guide.';
$string['privacy:metadata:bbbext_advgrd_grade:timegraded'] = 'When the grade was recorded.';
$string['privacy:metadata:bbbext_advgrd_grade:userid'] = 'The user being graded.';
$string['qq_crit_depth'] = 'Depth of contribution';
$string['qq_crit_frequency'] = 'Frequency of contribution';
$string['qq_crit_listening'] = 'Active listening';
$string['qq_crit_presence'] = 'Sustained presence';
$string['qq_group_quality'] = 'Quality';
$string['qq_group_quantity'] = 'Quantity';
$string['qq_lvl_depth_0'] = 'Contributions are absent, off-topic, or superficial.';
$string['qq_lvl_depth_1'] = 'Contributions are on-topic but brief or restate course material without elaboration.';
$string['qq_lvl_depth_2'] = 'Contributions advance the discussion: cite evidence, raise nuance, or articulate a position.';
$string['qq_lvl_depth_3'] = 'Contributions deepen the inquiry: synthesise sources, challenge assumptions, or open new angles.';
$string['qq_lvl_frequency_0'] = 'No discernible contributions during the session.';
$string['qq_lvl_frequency_1'] = 'A small handful of brief contributions across one channel.';
$string['qq_lvl_frequency_2'] = 'Multiple contributions, distributed across more than one channel.';
$string['qq_lvl_frequency_3'] = 'Sustained, frequent contribution across multiple channels throughout the session.';
$string['qq_lvl_listening_0'] = 'No evidence of attending to peers\' contributions.';
$string['qq_lvl_listening_1'] = 'Acknowledges peers occasionally (e.g., reactions, agreement).';
$string['qq_lvl_listening_2'] = 'Builds on, references, or summarises peers\' contributions in their own.';
$string['qq_lvl_listening_3'] = 'Visibly mediates the conversation: invites quieter peers in, integrates multiple voices, surfaces shared ground.';
$string['qq_lvl_presence_0'] = 'Absent or attended only briefly.';
$string['qq_lvl_presence_1'] = 'Attended a substantial portion of the session.';
$string['qq_lvl_presence_2'] = 'Attended the full session.';
$string['savegrade'] = 'Save grade';
$string['savemappings'] = 'Save mappings';
$string['setting_defaultweight_chats'] = 'Default weight: chat messages';
$string['setting_defaultweight_chats_desc'] = 'Default contribution weight applied to public chat messages.';
$string['setting_defaultweight_duration'] = 'Default weight: attendance duration';
$string['setting_defaultweight_duration_desc'] = 'Default contribution weight applied to attendance time when teachers map this metric to a rubric criterion.';
$string['setting_defaultweight_talks'] = 'Default weight: mic talk time';
$string['setting_defaultweight_talks_desc'] = 'Default contribution weight applied to mic usage time.';
$string['setting_enabled_desc'] = 'Enable the advanced grading extension for BigBlueButton activities.';
$string['setting_shipcoitemplates'] = 'Ship starter templates';
$string['setting_shipcoitemplates_desc'] = 'When enabled, teachers can import any of the bundled starter templates (Community of Inquiry; Quantity + Quality; Inclusive multi-modal) from the activity\'s secondary navigation.';
$string['suggested_level_score'] = 'suggested score: {$a}';
$string['suggested_levels_heading'] = 'Suggested levels';
$string['suggested_levels_help'] = 'Suggestions are derived from the metric thresholds you configured. They are advisory only — your judgement on the rubric overrides them.';
$string['templates_already_defined'] = 'A rubric or marking guide is already defined for this activity. To start over from a template, delete the current definition first.';
$string['templates_imported'] = 'Template imported. Edit it in the standard advanced grading editor or adjust the metric mappings below.';
$string['templates_intro'] = 'Each starter template is grounded in a different research perspective on class participation. Importing a template populates your rubric or marking guide and seeds metric mappings; you can then edit everything in the standard grading editor.';
$string['templates_title'] = 'Import template';
$string['templates_use_button'] = 'Use this template';
$string['tpl_coi_citation'] = 'Garrison, Anderson & Archer (2000); revised for single-session use after Akyol & Garrison (2008).';
$string['tpl_coi_definition_name'] = 'Community of Inquiry — class participation';
$string['tpl_coi_description'] = 'Four criteria a teacher can observe in a single live session — two anchored in cognitive presence (triggering inquiry, connecting ideas) and two in social presence (open communication, group cohesion). Teaching presence is omitted by default; add it manually if students peer-lead the session.';
$string['tpl_coi_name'] = 'Community of Inquiry';
$string['tpl_inclusive_citation'] = 'Shi & Tan (2020); Crosthwaite, Bailey & Meeker (2015); Tani (2008); O\'Connor et al. (2017).';
$string['tpl_inclusive_definition_name'] = 'Inclusive participation';
$string['tpl_inclusive_description'] = 'One criterion per modality — oral, written, responsive, group work — so a student strong on one modality is not penalised for being silent on another. Designed to acknowledge that vocal participation alone is a poor proxy for engagement.';
$string['tpl_inclusive_name'] = 'Inclusive (multi-modal)';
$string['tpl_qq_citation'] = 'Bean & Peterson (1998); Armstrong & Boud (1983).';
$string['tpl_qq_definition_name'] = 'Quantity + Quality — class participation';
$string['tpl_qq_description'] = 'Splits participation into a "quantity" axis (how often the student contributes) and a "quality" axis (how substantive each contribution is). Quantity criteria are wired to BigBlueButton signals; quality criteria are deliberately judgement-only because volume cannot tell you whether a comment was substantive.';
$string['tpl_qq_name'] = 'Quantity + Quality';
