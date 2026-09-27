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
 * Voice dictation for the questionnaire answers, using the Web Speech API of the browser.
 *
 * The microphone button is only shown when the browser supports speech recognition.
 * Recognised text is appended to the textarea; the learner can edit it before saving.
 *
 * @module     block_aicourserecommender/dictation
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

/**
 * Whether the browser supports dictation.
 *
 * @returns {Boolean}
 */
export const isSupported = () => typeof Recognition === 'function';

/**
 * Enables the microphone buttons inside a container.
 *
 * @param {HTMLElement} root Container.
 * @param {String} lang BCP 47 language tag.
 * @param {Function} onchange Called after text is added to a textarea.
 */
export const init = async(root, lang, onchange) => {
    if (!isSupported()) {
        return;
    }
    const [dictate, stop, listening] = await getStrings([
        {key: 'dictate', component: 'block_aicourserecommender'},
        {key: 'dictatestop', component: 'block_aicourserecommender'},
        {key: 'dictatelistening', component: 'block_aicourserecommender'},
    ]);
    let active = null;

    const setState = (button, on) => {
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.classList.toggle('aicr-recording', on);
        button.querySelector('[data-region="mic-label"]').textContent = on ? stop : dictate;
    };

    const announce = (text) => {
        const live = root.querySelector('[data-region="live"]');
        if (live) {
            live.textContent = text;
        }
    };

    const stopActive = () => {
        if (active) {
            active.recognition.stop();
        }
    };

    root.querySelectorAll('[data-action="dictate"]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => {
            if (active && active.button === button) {
                stopActive();
                return;
            }
            stopActive();
            const textarea = document.getElementById(button.dataset.target);
            const recognition = new Recognition();
            recognition.lang = lang;
            recognition.continuous = true;
            recognition.interimResults = false;
            recognition.onresult = (event) => {
                let text = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        text += event.results[i][0].transcript;
                    }
                }
                text = text.trim();
                if (!text) {
                    return;
                }
                const separator = textarea.value && !/\s$/.test(textarea.value) ? ' ' : '';
                const max = parseInt(textarea.getAttribute('maxlength'), 10) || 1000;
                textarea.value = (textarea.value + separator + text).slice(0, max);
                if (onchange) {
                    onchange(textarea);
                }
            };
            recognition.onend = () => {
                setState(button, false);
                if (active && active.button === button) {
                    active = null;
                }
            };
            recognition.onerror = () => {
                setState(button, false);
            };
            active = {button, recognition};
            setState(button, true);
            announce(listening);
            recognition.start();
        });
    });
};
