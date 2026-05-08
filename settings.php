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
 * Site-wide settings for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'bbbext_advgrd/enabled',
        new lang_string('enable'),
        new lang_string('setting_enabled_desc', 'bbbext_advgrd'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'bbbext_advgrd/shipcoitemplates',
        new lang_string('setting_shipcoitemplates', 'bbbext_advgrd'),
        new lang_string('setting_shipcoitemplates_desc', 'bbbext_advgrd'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'bbbext_advgrd/defaultweight_duration',
        new lang_string('setting_defaultweight_duration', 'bbbext_advgrd'),
        new lang_string('setting_defaultweight_duration_desc', 'bbbext_advgrd'),
        '1',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'bbbext_advgrd/defaultweight_talks',
        new lang_string('setting_defaultweight_talks', 'bbbext_advgrd'),
        new lang_string('setting_defaultweight_talks_desc', 'bbbext_advgrd'),
        '1',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'bbbext_advgrd/defaultweight_chats',
        new lang_string('setting_defaultweight_chats', 'bbbext_advgrd'),
        new lang_string('setting_defaultweight_chats_desc', 'bbbext_advgrd'),
        '1',
        PARAM_FLOAT
    ));
}
