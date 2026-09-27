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
 * Controller of the AI course recommender block.
 *
 * The page is rendered without calling the AI; this module loads the recommendations through AJAX and switches
 * between the states of the block: consent, AI policy, questionnaire, loading, results, no results and error.
 *
 * @module     block_aicourserecommender/block
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from './repository';
import * as Dictation from './dictation';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Policy from 'core_ai/policy';
import {getString, getStrings} from 'core/str';

const STATES = ['consent', 'aipolicy', 'questionnaire', 'loading', 'results', 'noresults', 'error'];

/** @type {Number} Maximum time to wait for the click log before following a link. */
const CLICK_TIMEOUT = 700;

/**
 * Block controller.
 */
class RecommenderBlock {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root Block container.
     */
    constructor(root) {
        this.root = root;
        this.uniqid = root.id;
        this.offsets = {course: 0, path: 0};
        this.loading = false;
    }

    /**
     * Returns a region of the block.
     *
     * @param {String} name Region name.
     * @returns {HTMLElement|null}
     */
    region(name) {
        return this.root.querySelector(`[data-region="${name}"]`);
    }

    /**
     * Shows one state and hides the others.
     *
     * @param {String} state State name.
     */
    show(state) {
        STATES.forEach((name) => {
            const region = this.region(name);
            if (region) {
                region.hidden = name !== state;
            }
        });
        this.root.dataset.state = state;
    }

    /**
     * Announces a message to screen readers.
     *
     * @param {String} message Message.
     */
    announce(message) {
        const live = this.region('live');
        if (live) {
            live.textContent = '';
            window.setTimeout(() => {
                live.textContent = message;
            }, 50);
        }
    }

    /**
     * Starts the block.
     */
    start() {
        this.observeWidth();
        this.registerEvents();
        this.setupCounters();
        Dictation.init(this.root, this.root.dataset.speechlang || document.documentElement.lang, (textarea) => {
            this.updateCounter(textarea);
        });
        if (this.root.dataset.state === 'loading') {
            this.load(false);
        }
    }

    /**
     * Adapts the layout to the width of the block container (side column or main region), not of the window.
     */
    observeWidth() {
        const apply = (width) => {
            this.root.classList.toggle('aicr-w-md', width >= 520);
            this.root.classList.toggle('aicr-w-lg', width >= 900);
        };
        apply(this.root.clientWidth);
        if (typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver((entries) => apply(entries[0].contentRect.width)).observe(this.root);
        }
    }

    /**
     * Character counters of the answers.
     */
    setupCounters() {
        this.root.querySelectorAll('textarea[data-slot]').forEach((textarea) => {
            this.updateCounter(textarea);
            textarea.addEventListener('input', () => this.updateCounter(textarea));
        });
    }

    /**
     * Updates the counter of one textarea.
     *
     * @param {HTMLTextAreaElement} textarea Textarea.
     */
    async updateCounter(textarea) {
        const counter = document.getElementById(textarea.id + '-count');
        if (!counter) {
            return;
        }
        counter.textContent = await getString('charcount', 'block_aicourserecommender', {
            count: textarea.value.length,
            max: counter.dataset.max,
        });
    }

    /**
     * Registers the event listeners (delegated).
     */
    registerEvents() {
        const checkbox = this.region('consent-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', () => {
                this.root.querySelector('[data-action="start"]').disabled = !checkbox.checked;
            });
        }
        const form = this.region('questionnaire-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveAnswers();
            });
        }
        this.root.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action]');
            if (!target || !this.root.contains(target)) {
                return;
            }
            const card = target.closest('[data-region="card"]');
            switch (target.dataset.action) {
                case 'start':
                    this.acceptConsent(target);
                    break;
                case 'accept-policy':
                    this.acceptPolicy(target);
                    break;
                case 'decline-policy':
                    this.region('policy-declined').hidden = false;
                    break;
                case 'cancel-edit':
                    this.load(false);
                    break;
                case 'change-interests':
                    this.openQuestionnaire();
                    break;
                case 'retry':
                    this.load(false);
                    break;
                case 'more':
                    this.showMore(target);
                    break;
                case 'view':
                    this.followLink(e, target, card);
                    break;
                case 'enrol':
                    this.enrol(target, card);
                    break;
                case 'rate':
                    this.rate(target, card);
                    break;
                case 'send-reason':
                    this.sendReason(target, card);
                    break;
            }
        });
    }

    /**
     * Next state after consent or policy acceptance.
     */
    next() {
        if (this.root.dataset.policyaccepted !== '1') {
            this.show('aipolicy');
            this.focusFirst('aipolicy');
        } else if (this.root.dataset.hasanswers === '1') {
            this.load(false);
        } else {
            this.openQuestionnaire();
        }
    }

    /**
     * Moves the focus to the first focusable element of a region.
     *
     * @param {String} name Region name.
     */
    focusFirst(name) {
        const element = this.region(name)?.querySelector('textarea, button:not([hidden]), a[href], input');
        if (element) {
            element.focus();
        }
    }

    /**
     * Saves the consent.
     *
     * @param {HTMLButtonElement} button Start button.
     */
    async acceptConsent(button) {
        button.disabled = true;
        try {
            await Repository.saveConsent();
            this.next();
        } catch (error) {
            button.disabled = false;
            Notification.exception(error);
        }
    }

    /**
     * Accepts the AI usage policy of the site with the core flow.
     *
     * @param {HTMLButtonElement} button Accept button.
     */
    async acceptPolicy(button) {
        button.disabled = true;
        try {
            await Policy.acceptPolicy();
            this.root.dataset.policyaccepted = '1';
            this.next();
        } catch (error) {
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Shows the questionnaire with the current answers.
     */
    openQuestionnaire() {
        this.region('form-error').hidden = true;
        this.root.querySelector('[data-action="cancel-edit"]').hidden = this.root.dataset.hasanswers !== '1';
        this.show('questionnaire');
        this.focusFirst('questionnaire');
    }

    /**
     * Saves the answers and loads new recommendations.
     */
    async saveAnswers() {
        const errorregion = this.region('form-error');
        const answers = [];
        let filled = 0;
        this.root.querySelectorAll('textarea[data-slot]').forEach((textarea) => {
            const answer = textarea.value.trim();
            if (answer) {
                filled++;
            }
            answers.push({slot: parseInt(textarea.dataset.slot, 10), answer});
        });
        if (!filled) {
            errorregion.textContent = await getString('erroremptyanswers', 'block_aicourserecommender');
            errorregion.hidden = false;
            errorregion.focus();
            return;
        }
        errorregion.hidden = true;
        const button = this.root.querySelector('[data-action="save-answers"]');
        button.disabled = true;
        try {
            await Repository.saveAnswers(answers);
            this.root.dataset.hasanswers = '1';
            await this.load(false);
        } catch (error) {
            errorregion.textContent = error.message || String(error);
            errorregion.hidden = false;
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Loads the first page of recommendations.
     *
     * @param {Boolean} force Ignore the stored ranking.
     */
    async load(force) {
        if (this.loading) {
            return;
        }
        this.loading = true;
        this.show('loading');
        this.announce(await getString('loading', 'block_aicourserecommender'));
        try {
            const result = await Repository.getRecommendations(force);
            await this.handleResult(result);
        } catch (error) {
            await this.showError(error.message || '');
        } finally {
            this.loading = false;
        }
    }

    /**
     * Handles the answer of get_recommendations.
     *
     * @param {Object} result Result.
     */
    async handleResult(result) {
        switch (result.status) {
            case 'questionnaire':
                this.root.dataset.hasanswers = '0';
                this.openQuestionnaire();
                return;
            case 'noresults':
                this.show('noresults');
                this.announce(await getString('noresults', 'block_aicourserecommender'));
                return;
            case 'error':
                await this.showError(result.error);
                return;
        }
        const context = {
            ...result,
            uniqid: this.uniqid,
            hascourses: result.courses.length > 0,
            haspaths: result.paths.length > 0,
        };
        const {html, js} = await Templates.renderForPromise('block_aicourserecommender/results', context);
        Templates.replaceNodeContents(this.region('results'), html, js);
        this.offsets = {course: result.courses.length, path: result.paths.length};
        this.show('results');
        this.announce(await getString('resultsloaded', 'block_aicourserecommender', {
            courses: result.courses.length,
            paths: result.paths.length,
        }));
    }

    /**
     * Shows the error state.
     *
     * @param {String} message Error message.
     */
    async showError(message) {
        this.region('error-message').textContent = message || await getString('erroraifailed', 'block_aicourserecommender');
        this.show('error');
        this.announce(this.region('error-message').textContent);
    }

    /**
     * Shows the next page of the stored ranking. The AI is not called.
     *
     * @param {HTMLButtonElement} button See more button.
     */
    async showMore(button) {
        const itemtype = button.dataset.itemtype;
        button.disabled = true;
        try {
            const result = await Repository.getMore(itemtype, this.offsets[itemtype]);
            const items = itemtype === 'course' ? result.courses : result.paths;
            const list = this.region('list-' + itemtype);
            let first = null;
            for (const item of items) {
                const {html, js} = await Templates.renderForPromise('block_aicourserecommender/' + itemtype + '_card',
                    {...item, uniqid: this.uniqid});
                const li = document.createElement('li');
                list.appendChild(li);
                Templates.appendNodeContents(li, html, js);
                first = first || li;
            }
            this.offsets[itemtype] += items.length;
            button.hidden = !result.hasmore;
            this.announce(await getString('moreloaded', 'block_aicourserecommender', items.length));
            if (first) {
                const link = first.querySelector('[data-action="view"]');
                if (link) {
                    link.focus();
                }
            }
        } catch (error) {
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Records the click and follows the link.
     *
     * @param {Event} e Click event.
     * @param {HTMLAnchorElement} link Link.
     * @param {HTMLElement} card Card.
     */
    followLink(e, link, card) {
        const logged = Repository.logClick(card.dataset.itemtype, parseInt(card.dataset.itemid, 10),
            parseInt(card.dataset.position, 10)).catch(() => null);
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) {
            return;
        }
        e.preventDefault();
        const timeout = new Promise((resolve) => window.setTimeout(resolve, CLICK_TIMEOUT));
        Promise.race([logged, timeout]).then(() => {
            window.location.href = link.href;
            return null;
        }).catch(() => {
            window.location.href = link.href;
        });
    }

    /**
     * Enrols the user in a course after confirmation.
     *
     * @param {HTMLButtonElement} button Enrol button.
     * @param {HTMLElement} card Card.
     */
    async enrol(button, card) {
        const [title, question, label] = await getStrings([
            {key: 'enrolconfirmtitle', component: 'block_aicourserecommender'},
            {key: 'enrolconfirm', component: 'block_aicourserecommender', param: button.dataset.name},
            {key: 'enrolme', component: 'block_aicourserecommender'},
        ]);
        try {
            await Notification.saveCancelPromise(title, question, label);
        } catch (cancelled) {
            button.focus();
            return;
        }
        button.disabled = true;
        try {
            const result = await Repository.enrolCourse(parseInt(card.dataset.itemid, 10));
            if (result.success) {
                window.location.href = result.url;
                return;
            }
            await Notification.alert(title, result.message);
            button.disabled = false;
        } catch (error) {
            button.disabled = false;
            Notification.exception(error);
        }
    }

    /**
     * Saves a rating. A thumbs down opens the optional reason field.
     *
     * @param {HTMLButtonElement} button Rating button.
     * @param {HTMLElement} card Card.
     */
    async rate(button, card) {
        const rating = parseInt(button.dataset.rating, 10);
        const ratingregion = button.closest('[data-region="rating"]');
        try {
            await Repository.submitFeedback(card.dataset.itemtype, parseInt(card.dataset.itemid, 10), rating, '');
            ratingregion.querySelectorAll('[data-action="rate"]').forEach((b) => {
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });
            const reasonform = ratingregion.querySelector('[data-region="reason-form"]');
            reasonform.hidden = rating > 0;
            ratingregion.querySelector('[data-region="rating-status"]').textContent =
                await getString('ratingsaved', 'block_aicourserecommender');
            if (rating < 0) {
                reasonform.querySelector('input').focus();
            }
        } catch (error) {
            Notification.exception(error);
        }
    }

    /**
     * Sends the reason of a thumbs down.
     *
     * @param {HTMLButtonElement} button Send button.
     * @param {HTMLElement} card Card.
     */
    async sendReason(button, card) {
        const ratingregion = button.closest('[data-region="rating"]');
        const input = ratingregion.querySelector('[data-region="reason-input"]');
        const reason = input.value.trim().slice(0, 200);
        button.disabled = true;
        try {
            await Repository.submitFeedback(card.dataset.itemtype, parseInt(card.dataset.itemid, 10), -1, reason);
            ratingregion.querySelector('[data-region="reason-form"]').hidden = true;
            ratingregion.querySelector('[data-region="rating-status"]').textContent =
                await getString('reasonsaved', 'block_aicourserecommender');
            ratingregion.querySelector('[data-rating="-1"]').focus();
        } catch (error) {
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    }
}

/**
 * Initialises the block.
 *
 * @param {String} uniqid Id of the block container.
 */
export const init = (uniqid) => {
    const root = document.getElementById(uniqid);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';
    new RecommenderBlock(root).start();
};
