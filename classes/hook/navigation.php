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
 * Hook callbacks for adding bbbext_advgrd nodes to BBB activity secondary navigation.
 *
 * @package    bbbext_advgrd
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bbbext_advgrd\hook;

use core\hook\output\before_http_headers;
use moodle_url;
use navigation_node;

/**
 * Hook handlers.
 */
class navigation {
    /**
     * Inject our advanced-grading nodes into $PAGE->settingsnav under 'modulesettings'.
     *
     * Layout (for users with the relevant caps):
     *   - Advanced grading      → core /grade/grading/manage.php (define / edit rubric or guide)
     *   - Import template       → our pages/templates.php
     *   - Metric mappings       → our pages/configure.php
     *   - Participation grading → our pages/index.php
     *
     * The secondary navigation later reads these from settingsnav via load_remaining_nodes()
     * in load_module_navigation(), so we only have to put them there.
     */
    public static function extend_settingsnav(before_http_headers $hook): void {
        global $PAGE, $DB, $CFG;

        $cm = $PAGE->cm ?? null;
        if (!$cm || $cm->modname !== 'bigbluebuttonbn') {
            return;
        }
        $context = $PAGE->context ?? null;
        if (!$context || $context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $bbbid = (int) $cm->instance;
        $config = $DB->get_record('bbbext_advgrd_config', ['bigbluebuttonbnid' => $bbbid]);
        if (!$config || $config->gradingmethod === 'none') {
            return;
        }

        $modulesettings = $PAGE->settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
        if (!$modulesettings) {
            return;
        }

        $canmanage = has_capability('bbbext/advgrd:manage', $context);
        $cangrade = has_capability('bbbext/advgrd:grade', $context);

        if ($canmanage) {
            require_once($CFG->dirroot . '/grade/grading/lib.php');
            $manager = get_grading_manager($context, 'bbbext_advgrd', 'participation');
            $modulesettings->add(
                get_string('nav_advanced_grading', 'bbbext_advgrd'),
                $manager->get_management_url(),
                navigation_node::TYPE_SETTING,
                null,
                'bbbext_advgrd_definition'
            );
            $modulesettings->add(
                get_string('templates_title', 'bbbext_advgrd'),
                new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/templates.php', ['id' => $bbbid]),
                navigation_node::TYPE_SETTING,
                null,
                'bbbext_advgrd_templates'
            );
            $modulesettings->add(
                get_string('mappings_title', 'bbbext_advgrd'),
                new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/configure.php', ['id' => $bbbid]),
                navigation_node::TYPE_SETTING,
                null,
                'bbbext_advgrd_mappings'
            );
        }

        if ($cangrade) {
            $modulesettings->add(
                get_string('grading_list_title', 'bbbext_advgrd'),
                new moodle_url('/mod/bigbluebuttonbn/extension/advgrd/pages/index.php', ['id' => $bbbid]),
                navigation_node::TYPE_SETTING,
                null,
                'bbbext_advgrd_grading'
            );
        }
    }
}
