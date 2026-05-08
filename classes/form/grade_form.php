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
 * Grading form wrapping a rubric or marking-guide instance.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

use moodleform;

/**
 * Single-user grading form. Embeds Moodle's 'grading' element which delegates rendering and
 * validation to the underlying gradingform_*_instance.
 */
class grade_form extends moodleform {

    /**
     * Form definition.
     *
     * Custom data passed in via $customdata:
     *   - gradinginstance (gradingform_instance) — REQUIRED
     *   - bbbid (int)
     *   - userid (int)
     *   - returnurl (moodle_url|null)
     */
    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $instance = $custom['gradinginstance'];
        $bbbid = (int) $custom['bbbid'];
        $userid = (int) $custom['userid'];

        $mform->addElement('hidden', 'id', $bbbid);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'userid', $userid);
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'advancedgradinginstanceid', $instance->get_id());
        $mform->setType('advancedgradinginstanceid', PARAM_INT);

        $mform->addElement(
            'grading',
            'advancedgrading',
            get_string('grade_label', 'bbbext_advgrd'),
            ['gradinginstance' => $instance]
        );

        $this->add_action_buttons(true, get_string('savegrade', 'bbbext_advgrd'));
    }
}
