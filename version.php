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
 * Version metadata for the BigBlueButton Advanced Grading extension.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version      = 2026071501;
$plugin->requires     = 2025041400;
$plugin->component    = 'bbbext_advgrd';
$plugin->maturity     = MATURITY_BETA;
$plugin->release      = '0.3.3';
$plugin->supports     = [500, 502];
$plugin->dependencies = [
    'mod_bigbluebuttonbn' => 2025041400,
    'gradingform_rubric'  => 2025041400,
    'gradingform_guide'   => 2025041400,
];
