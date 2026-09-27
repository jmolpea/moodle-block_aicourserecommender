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
 * Learning path form: sequential course order and AI generation of description and image with preview.
 *
 * @module     block_aicourserecommender/pathform
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import * as Repository from './repository';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import Policy from 'core_ai/policy';
import {getString, getStrings} from 'core/str';

/**
 * Current order of the selected courses.
 *
 * @type {Array<{id: String, name: String}>}
 */
let order = [];

/**
 * Returns the selected courses of the autocomplete, in their current order.
 *
 * @param {HTMLSelectElement} select Original select of the autocomplete.
 * @returns {Array<{id: String, name: String}>}
 */
const getSelected = (select) => Array.from(select.options)
    .filter((option) => option.selected && option.value !== '' && option.value !== '_qf__force_multiselect_submission')
    .map((option) => ({id: option.value, name: option.textContent.trim()}));

/**
 * Renders the ordered list and updates the hidden field.
 *
 * @param {HTMLElement} container Order container.
 * @param {HTMLInputElement} hidden Hidden courseorder field.
 * @param {Object} strings Strings.
 */
const renderOrder = (container, hidden, strings) => {
    const list = container.querySelector('[data-region="course-order-list"]');
    container.querySelector('[data-region="course-order-empty"]').hidden = order.length > 0;
    list.innerHTML = '';
    order.forEach((course, index) => {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex align-items-center aicr-order-item';
        li.dataset.courseid = course.id;

        const label = document.createElement('span');
        label.className = 'aicr-order-name';
        label.textContent = (index + 1) + '. ' + course.name;
        li.appendChild(label);

        const makeButton = (action, text, symbol, disabled) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary aicr-order-btn';
            button.dataset.orderAction = action;
            button.setAttribute('aria-label', text.replace('{$a}', course.name));
            button.title = button.getAttribute('aria-label');
            button.textContent = symbol;
            button.disabled = disabled;
            return button;
        };
        li.appendChild(makeButton('up', strings.up, '↑', index === 0));
        li.appendChild(makeButton('down', strings.down, '↓', index === order.length - 1));
        list.appendChild(li);
    });
    hidden.value = order.map((course) => course.id).join(',');
};

/**
 * Sets the content of the description editor (TinyMCE or plain textarea).
 *
 * @param {String} html Content.
 */
const setEditorContent = (html) => {
    const textarea = document.getElementById('id_description_editor');
    if (!textarea) {
        return;
    }
    textarea.value = html;
    window.require(['editor_tiny/editor'], (TinyEditor) => {
        const editor = TinyEditor.getInstanceForElementId('id_description_editor');
        if (editor) {
            editor.setContent(html);
            editor.save();
        }
    }, () => null);
};

/**
 * Returns the content of the description editor.
 *
 * @returns {String}
 */
const getEditorContent = () => {
    const textarea = document.getElementById('id_description_editor');
    return textarea ? textarea.value : '';
};

/**
 * Shows a preview in a modal and resolves when the user applies it.
 *
 * @param {String} title Modal title.
 * @param {String} body Modal body (HTML).
 * @param {String} applylabel Apply button label.
 * @returns {Promise<Boolean>}
 */
const preview = async(title, body, applylabel) => {
    const modal = await ModalSaveCancel.create({
        title,
        body,
        buttons: {save: applylabel},
        removeOnClose: true,
        show: true,
    });
    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.save, () => resolve(true));
        modal.getRoot().on(ModalEvents.hidden, () => resolve(false));
    });
};

/**
 * Shows the AI usage policy of the site and records the acceptance with the core flow.
 *
 * @returns {Promise<Boolean>} Whether the policy was accepted.
 */
const acceptPolicy = async() => {
    const [title, body, label] = await getStrings([
        {key: 'aiusagepolicy', component: 'core_ai'},
        {key: 'userpolicy', component: 'core_ai'},
        {key: 'acceptai', component: 'core_ai'},
    ]);
    try {
        await Notification.saveCancelPromise(title, body, label);
    } catch (cancelled) {
        return false;
    }
    await Policy.acceptPolicy();
    return true;
};

/**
 * Initialises the form.
 */
export const init = async() => {
    const form = document.querySelector('form.mform');
    const select = document.getElementById('id_courses');
    const hidden = form ? form.querySelector('input[name="courseorder"]') : null;
    const container = form ? form.querySelector('[data-region="course-order"]') : null;
    if (!form || !select || !hidden || !container) {
        return;
    }
    const [up, down, previewtitle, apply, generating, nocourses, imagetitle] = await getStrings([
        {key: 'moveup_a', component: 'block_aicourserecommender'},
        {key: 'movedown_a', component: 'block_aicourserecommender'},
        {key: 'aipreview', component: 'block_aicourserecommender'},
        {key: 'apply', component: 'block_aicourserecommender'},
        {key: 'generating', component: 'block_aicourserecommender'},
        {key: 'errornocourses', component: 'block_aicourserecommender'},
        {key: 'aiimagepreview', component: 'block_aicourserecommender'},
    ]);
    const strings = {up, down};

    // Initial order: the saved order, then any other selected course.
    const selected = getSelected(select);
    const saved = hidden.value ? hidden.value.split(',') : [];
    order = saved.map((id) => selected.find((c) => c.id === id)).filter(Boolean);
    selected.forEach((course) => {
        if (!order.find((c) => c.id === course.id)) {
            order.push(course);
        }
    });
    renderOrder(container, hidden, strings);

    // The autocomplete updates the original select and triggers a jQuery change event.
    $(select).on('change', () => {
        const current = getSelected(select);
        order = order.filter((c) => current.find((s) => s.id === c.id));
        current.forEach((course) => {
            if (!order.find((c) => c.id === course.id)) {
                order.push(course);
            }
        });
        renderOrder(container, hidden, strings);
    });

    container.addEventListener('click', async(e) => {
        const button = e.target.closest('[data-order-action]');
        if (!button) {
            return;
        }
        const id = button.closest('[data-courseid]').dataset.courseid;
        const index = order.findIndex((c) => c.id === id);
        const target = button.dataset.orderAction === 'up' ? index - 1 : index + 1;
        if (index < 0 || target < 0 || target >= order.length) {
            return;
        }
        [order[index], order[target]] = [order[target], order[index]];
        renderOrder(container, hidden, strings);
        container.querySelector('[data-region="course-order-live"]').textContent =
            await getString('coursemoved', 'block_aicourserecommender', {name: order[target].name, position: target + 1});
        const moved = container.querySelector(`[data-courseid="${id}"] [data-order-action="${button.dataset.orderAction}"]`);
        (moved && !moved.disabled ? moved : container.querySelector(`[data-courseid="${id}"] button:not([disabled])`))?.focus();
    });

    form.addEventListener('click', async(e) => {
        const button = e.target.closest('[data-action="generate-description"], [data-action="generate-image"]');
        if (!button) {
            return;
        }
        const status = button.parentElement.querySelector('[data-region="ai-status"]');
        const title = (document.getElementById('id_name')?.value || '').trim();
        const courseids = order.map((c) => parseInt(c.id, 10));
        if (!courseids.length) {
            status.textContent = nocourses;
            return;
        }
        button.disabled = true;
        status.textContent = generating;
        try {
            if (button.dataset.action === 'generate-description') {
                const result = await Repository.generatePathDescription(title, courseids);
                status.textContent = result.success ? '' : result.error;
                if (result.success && await preview(previewtitle, result.description, apply)) {
                    setEditorContent(result.description);
                }
            } else {
                const result = await Repository.generatePathImage(title, getEditorContent());
                status.textContent = result.success ? '' : result.error;
                if (!result.success) {
                    return;
                }
                const img = document.createElement('img');
                img.src = result.drafturl;
                img.alt = '';
                img.className = 'img-fluid';
                if (await preview(imagetitle, img.outerHTML, apply)) {
                    form.querySelector('input[name="aiimage"]').value = result.draftitemid + '/' + result.filename;
                    const applied = button.parentElement.querySelector('[data-region="ai-image-applied"]');
                    applied.querySelector('img').src = result.drafturl;
                    applied.hidden = false;
                }
            }
        } catch (error) {
            status.textContent = '';
            if (error.errorcode === 'erroraipolicy') {
                button.disabled = false;
                if (await acceptPolicy()) {
                    button.click();
                }
                return;
            }
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    });
};
