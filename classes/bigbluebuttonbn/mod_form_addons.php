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
 * Activity form fields for the BBB Advanced Grading extension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\bigbluebuttonbn;

use moodle_url;
use stdClass;

/**
 * Adds the "Advanced grading" fieldset to the BBB activity edit form.
 */
class mod_form_addons extends \mod_bigbluebuttonbn\local\extension\mod_form_addons {
    /**
     * Add the advanced-grading configuration fieldset.
     */
    public function add_fields(): void {
        if (!get_config('bbbext_advgrd', 'enabled')) {
            return;
        }

        $this->mform->addElement('header', 'advgrd_header', get_string('formheader', 'bbbext_advgrd'));
        $this->mform->setExpanded('advgrd_header', false);

        $this->mform->addElement(
            'select',
            'advgrd_gradingmethod',
            get_string('form_gradingmethod', 'bbbext_advgrd'),
            [
                'none'   => get_string('form_gradingmethod_none', 'bbbext_advgrd'),
                'rubric' => get_string('form_gradingmethod_rubric', 'bbbext_advgrd'),
                'guide'  => get_string('form_gradingmethod_guide', 'bbbext_advgrd'),
            ]
        );
        $this->mform->setType('advgrd_gradingmethod', PARAM_ALPHA);
        $this->mform->setDefault('advgrd_gradingmethod', 'none');
        $this->mform->addHelpButton('advgrd_gradingmethod', 'form_gradingmethod', 'bbbext_advgrd');

        $this->mform->addElement(
            'select',
            'advgrd_scoremode',
            get_string('form_scoremode', 'bbbext_advgrd'),
            [
                'composite' => get_string('form_scoremode_composite', 'bbbext_advgrd'),
                'analytic'  => get_string('form_scoremode_analytic', 'bbbext_advgrd'),
            ]
        );
        $this->mform->setType('advgrd_scoremode', PARAM_ALPHA);
        $this->mform->setDefault('advgrd_scoremode', 'composite');
        $this->mform->addHelpButton('advgrd_scoremode', 'form_scoremode', 'bbbext_advgrd');
        $this->mform->hideIf('advgrd_scoremode', 'advgrd_gradingmethod', 'eq', 'none');

        $this->mform->addElement(
            'advcheckbox',
            'advgrd_passthroughtogradebook',
            get_string('form_passthrough', 'bbbext_advgrd')
        );
        $this->mform->setDefault('advgrd_passthroughtogradebook', 1);
        $this->mform->hideIf('advgrd_passthroughtogradebook', 'advgrd_gradingmethod', 'eq', 'none');

        // Hint pointing teachers at the secondary nav for rubric definition + template import.
        // We deliberately don't deep-link to the rubric editor from the activity form: that
        // matches mod_assign's pattern (the activity form holds the *settings*; the rubric is
        // edited from the activity's own secondary navigation after the activity is saved).
        $this->mform->addElement(
            'static',
            'advgrd_secondary_nav_hint',
            '',
            get_string('form_secondary_nav_hint', 'bbbext_advgrd')
        );
        $this->mform->hideIf('advgrd_secondary_nav_hint', 'advgrd_gradingmethod', 'eq', 'none');
    }

    /**
     * Populate form defaults from any saved config row.
     *
     * Called by mod_bigbluebuttonbn\mod_form during set_data(). Note that BBB invokes this on
     * every extension without checking the method exists, so every bbbext sub-plugin must define
     * it (the abstract base does not declare it).
     *
     * @param array $defaultvalues Defaults the form will set into elements; modify by reference.
     */
    public function data_preprocessing(array &$defaultvalues): void {
        global $DB;

        if (empty($defaultvalues['id'])) {
            return;
        }
        $record = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $defaultvalues['id']]);
        if (!$record) {
            return;
        }
        $defaultvalues['advgrd_gradingmethod'] = $record->gradingmethod;
        $defaultvalues['advgrd_scoremode'] = $record->scoremode;
        $defaultvalues['advgrd_passthroughtogradebook'] = (int) $record->passthroughtogradebook;
    }

    /**
     * Validate form data.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation(array $data, array $files): array {
        $errors = [];
        $method = $data['advgrd_gradingmethod'] ?? 'none';
        if (!in_array($method, ['none', 'rubric', 'guide'], true)) {
            $errors['advgrd_gradingmethod'] = get_string('form_err_invalidmethod', 'bbbext_advgrd');
        }
        $mode = $data['advgrd_scoremode'] ?? 'composite';
        if (!in_array($mode, ['composite', 'analytic'], true)) {
            $errors['advgrd_scoremode'] = get_string('form_err_invalidmode', 'bbbext_advgrd');
        }
        // Without a numeric max grade on the activity, grade_update() falls back to GRADE_TYPE_NONE
        // and silently swallows scores. Force the teacher to set a max before they can enable a rubric.
        if ($method !== 'none' && (int) ($data['grade'] ?? 0) <= 0) {
            $errors['advgrd_gradingmethod'] = get_string('form_err_gradezero', 'bbbext_advgrd');
        }
        return $errors;
    }

    /**
     * No custom completion rules.
     */
    public function add_completion_rules(): array {
        return [];
    }

    /**
     * No-op: storage is handled by mod_instance_helper to keep concerns separated.
     *
     * @param stdClass $data
     */
    public function data_postprocessing(stdClass &$data): void {
    }
}
