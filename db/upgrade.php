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
 * Database upgrade steps for bbbext_advgrd.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run database upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_bbbext_advgrd_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050801) {
        // Add a denormalised bigbluebuttonbnid column to metric_map and grade so that scoped
        // queries (e.g., privacy export, backup) can resolve rows to a BBB instance directly
        // without joining through the config table. Backfill from the existing config FK chain
        // and add an FK index. Only `bbbext_advgrd_config` is listed in
        // mod_instance_helper::get_join_tables() because it is the only 1:1 table.
        $maptable = new xmldb_table('bbbext_advgrd_metric_map');
        $field = new xmldb_field(
            'bigbluebuttonbnid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'id'
        );
        if (!$dbman->field_exists($maptable, $field)) {
            $dbman->add_field($maptable, $field);
            $DB->execute("
                UPDATE {bbbext_advgrd_metric_map} m
                   SET bigbluebuttonbnid = (
                       SELECT c.bigbluebuttonbnid FROM {bbbext_advgrd_config} c WHERE c.id = m.configid
                   )
            ");
            $dbman->change_field_notnull(
                $maptable,
                new xmldb_field(
                    'bigbluebuttonbnid',
                    XMLDB_TYPE_INTEGER,
                    '10',
                    null,
                    XMLDB_NOTNULL,
                    null,
                    null,
                    'id'
                )
            );
            $key = new xmldb_key(
                'fk_bigbluebuttonbnid',
                XMLDB_KEY_FOREIGN,
                ['bigbluebuttonbnid'],
                'bigbluebuttonbn',
                ['id']
            );
            $dbman->add_key($maptable, $key);
        }

        $gradetable = new xmldb_table('bbbext_advgrd_grade');
        $field = new xmldb_field(
            'bigbluebuttonbnid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'id'
        );
        if (!$dbman->field_exists($gradetable, $field)) {
            $dbman->add_field($gradetable, $field);
            $DB->execute("
                UPDATE {bbbext_advgrd_grade} g
                   SET bigbluebuttonbnid = (
                       SELECT c.bigbluebuttonbnid FROM {bbbext_advgrd_config} c WHERE c.id = g.configid
                   )
            ");
            $dbman->change_field_notnull(
                $gradetable,
                new xmldb_field(
                    'bigbluebuttonbnid',
                    XMLDB_TYPE_INTEGER,
                    '10',
                    null,
                    XMLDB_NOTNULL,
                    null,
                    null,
                    'id'
                )
            );
            $key = new xmldb_key(
                'fk_bigbluebuttonbnid',
                XMLDB_KEY_FOREIGN,
                ['bigbluebuttonbnid'],
                'bigbluebuttonbn',
                ['id']
            );
            $dbman->add_key($gradetable, $key);
        }

        upgrade_plugin_savepoint(true, 2026050801, 'bbbext', 'advgrd');
    }

    return true;
}
