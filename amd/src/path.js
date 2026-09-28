// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Learning path landing page: "Enrol me in the whole path".
 *
 * @module     block_aicourserecommender/path
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from './repository';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getStrings} from 'core/str';

/**
 * Initialises the page.
 *
 * @param {Number} pathid Path id.
 */
export const init = (pathid) => {
    const root = document.querySelector(`[data-region="aicr-path"][data-pathid="${pathid}"]`);
    if (!root) {
        return;
    }
    const button = root.querySelector('[data-action="enrol-path"]');
    if (!button) {
        return;
    }
    button.addEventListener('click', async() => {
        const [title, label] = await getStrings([
            {key: 'enrolpath', component: 'block_aicourserecommender'},
            {key: 'enrolpathconfirm', component: 'block_aicourserecommender'},
        ]);
        try {
            await Notification.saveCancelPromise(title, root.querySelector('[data-region="enrol-summary"]').innerHTML, label);
        } catch (cancelled) {
            button.focus();
            return;
        }
        button.disabled = true;
        try {
            const result = await Repository.enrolPath(pathid);
            const {html, js} = await Templates.renderForPromise('block_aicourserecommender/enrol_results',
                {
                    ...result,
                    pageurl: window.location.href,
                    hasunavailable: result.results.some((item) => item.status === 'unavailable'),
                });
            const region = root.querySelector('[data-region="enrol-results"]');
            Templates.replaceNodeContents(region, html, js);
            button.hidden = true;
            region.setAttribute('tabindex', '-1');
            region.focus();
        } catch (error) {
            button.disabled = false;
            Notification.exception(error);
        }
    });
};
