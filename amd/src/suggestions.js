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
 * Highlights the rubric level cells suggested by BigBlueButton engagement metrics.
 *
 * The grading form is rendered by Moodle's gradingform_rubric plugin, which lays out one
 * radio input per (criterion, level) inside a td. We locate the radios by their name+value
 * pattern, mark the surrounding td with the .bbbext-advgrd-suggested class, and append a
 * small badge so the teacher can see at a glance which cell the metrics suggest.
 *
 * @module     bbbext_advgrd/suggestions
 * @copyright  2026, South African Theological Seminary
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';

/**
 * Initialise the highlighter.
 *
 * @param {Array<{criterionid:number, levelid:number}>} suggestions
 */
export const init = async(suggestions) => {
    if (!Array.isArray(suggestions) || suggestions.length === 0) {
        return;
    }

    const badgetext = await getString('suggested_badge', 'bbbext_advgrd');

    suggestions.forEach((s) => {
        const radios = document.querySelectorAll(
            `input[type="radio"][name$="[criteria][${s.criterionid}][levelid]"][value="${s.levelid}"]`
        );
        radios.forEach((radio) => {
            const cell = radio.closest('td');
            if (!cell) {
                return;
            }
            cell.classList.add('bbbext-advgrd-suggested');
            if (cell.querySelector('.bbbext-advgrd-suggested-badge')) {
                return;
            }
            const badge = document.createElement('span');
            badge.className = 'bbbext-advgrd-suggested-badge';
            badge.textContent = badgetext;
            cell.prepend(badge);
        });
    });
};
