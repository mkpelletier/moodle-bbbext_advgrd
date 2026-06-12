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
 * External function registrations for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'bbbext_advgrd_add_annotation' => [
        'classname'    => 'bbbext_advgrd\\external\\add_annotation',
        'methodname'   => 'execute',
        'description'  => 'Persist a recording-annotation comment authored in the editor.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'bbbext/advgrd:grade',
    ],
    'bbbext_advgrd_delete_annotation' => [
        'classname'    => 'bbbext_advgrd\\external\\delete_annotation',
        'methodname'   => 'execute',
        'description'  => 'Delete a recording-annotation comment.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'bbbext/advgrd:grade',
    ],
    'bbbext_advgrd_list_annotations' => [
        'classname'    => 'bbbext_advgrd\\external\\list_annotations',
        'methodname'   => 'execute',
        'description'  => 'List annotations for a (recording, student) pair.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'bbbext/advgrd:grade',
    ],
    'bbbext_advgrd_probe_recording' => [
        'classname'    => 'bbbext_advgrd\\external\\probe_recording',
        'methodname'   => 'execute',
        'description'  => 'Probe a BBB recording for a directly playable media URL.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'bbbext/advgrd:grade',
    ],
];
