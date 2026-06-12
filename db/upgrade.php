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

    if ($oldversion < 2026061201) {
        // Annotation table: per-(recording, student) timestamped rich-text comments.
        $annotation = new xmldb_table('bbbext_advgrd_annotation');
        if (!$dbman->table_exists($annotation)) {
            $annotation->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
            $annotation->addField(new xmldb_field('bigbluebuttonbnid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $annotation->addField(new xmldb_field('recordingid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
            $annotation->addField(new xmldb_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $annotation->addField(new xmldb_field('graderid', XMLDB_TYPE_INTEGER, '10'));
            $annotation->addField(new xmldb_field('timestampms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'));
            $annotation->addField(new xmldb_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL));
            $annotation->addField(new xmldb_field('bodyformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1'));
            $annotation->addField(new xmldb_field('commenttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'general'));
            $annotation->addField(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $annotation->addField(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $annotation->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
            $annotation->addKey(new xmldb_key(
                'fk_bigbluebuttonbnid',
                XMLDB_KEY_FOREIGN,
                ['bigbluebuttonbnid'],
                'bigbluebuttonbn',
                ['id']
            ));
            $annotation->addKey(new xmldb_key(
                'fk_targetuserid',
                XMLDB_KEY_FOREIGN,
                ['targetuserid'],
                'user',
                ['id']
            ));
            $annotation->addIndex(new xmldb_index(
                'idx_recording_target',
                XMLDB_INDEX_NOTUNIQUE,
                ['recordingid', 'targetuserid']
            ));
            $dbman->create_table($annotation);
        }

        // Recording-probe cache: stores the resolved playable media URL per recording so
        // the own-player path can skip a network round-trip on subsequent grading sessions.
        $probe = new xmldb_table('bbbext_advgrd_rec_probe');
        if (!$dbman->table_exists($probe)) {
            $probe->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
            $probe->addField(new xmldb_field('bigbluebuttonbnid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $probe->addField(new xmldb_field('recordingid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
            $probe->addField(new xmldb_field('mediaurl', XMLDB_TYPE_TEXT));
            $probe->addField(new xmldb_field('probestatus', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'failed'));
            $probe->addField(new xmldb_field('durationms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'));
            $probe->addField(new xmldb_field('iframeurl', XMLDB_TYPE_TEXT));
            $probe->addField(new xmldb_field('timeprobed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
            $probe->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
            $probe->addKey(new xmldb_key(
                'fk_bigbluebuttonbnid',
                XMLDB_KEY_FOREIGN,
                ['bigbluebuttonbnid'],
                'bigbluebuttonbn',
                ['id']
            ));
            $probe->addIndex(new xmldb_index('idx_recordingid', XMLDB_INDEX_UNIQUE, ['recordingid']));
            $dbman->create_table($probe);
        }

        upgrade_plugin_savepoint(true, 2026061201, 'bbbext', 'advgrd');
    }

    return true;
}
