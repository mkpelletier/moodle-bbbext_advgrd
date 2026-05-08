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
 * Static registry of available rubric/guide templates.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\local\templates;

use coding_exception;

/**
 * Lookup helpers around the bundled templates.
 */
class registry {
    /**
     * All registered template classes, in display order. Adding a new template means appending
     * its FQCN here.
     *
     * @return string[] Array of fully-qualified class names extending {@see template}.
     */
    public static function all(): array {
        return [
            coi::class,
            quantity_quality::class,
            inclusive::class,
        ];
    }

    /**
     * Fetch a template class name by its stable id.
     *
     * @param string $id
     * @return class-string<template>
     * @throws coding_exception When the id doesn't match any registered template.
     */
    public static function get(string $id): string {
        foreach (self::all() as $class) {
            if ($class::id() === $id) {
                return $class;
            }
        }
        throw new coding_exception("Unknown bbbext_advgrd template id: {$id}");
    }
}
