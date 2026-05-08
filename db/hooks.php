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
 * Hook callback registration for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // Moodle's secondary_extend hook only fires for course-level pages, not module-level
        // (see lib/classes/navigation/views/secondary.php — dispatch is inside
        // load_course_navigation() only). For module-level secondary nav, nodes come from the
        // settingsnav tree under 'modulesettings', which is normally populated by the parent
        // module's *_extend_settings_navigation() callback (sub-plugins don't get a callback
        // there). We hook before_http_headers — which fires from core_renderer::header() before
        // the secondary nav initialises — and inject our nodes into settingsnav directly.
        'hook'     => \core\hook\output\before_http_headers::class,
        'callback' => \bbbext_advgrd\hook\navigation::class . '::extend_settingsnav',
    ],
];
