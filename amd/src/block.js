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
 * between the states of the block: wizard (first use), summary (change interests), loading, results, no results
 * and error.
 *
 * @module     block_aicourserecommender/block
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from './repository';
import * as Dictation from './dictation';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Modal from 'core/modal';
import Policy from 'core_ai/policy';
import {getString, getStrings} from 'core/str';

const STATES = ['wizard', 'summary', 'loading', 'results', 'noresults', 'error'];

/** @type {Number} Maximum time to wait for the click log before following a link. */
const CLICK_TIMEOUT = 700;

/**
 * Escapes text for use inside HTML (dialogue bodies are HTML).
 *
 * @param {String} text Text.
 * @returns {String}
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
};

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
        this.card = 1;
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
        this.refreshSummary();
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
        const form = this.region('questionnaire-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitWizard();
            });
        }
        const checkbox = this.region('accept-checkbox');
        if (checkbox) {
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) {
                    this.hideFormError();
                }
            });
        }
        this.root.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action]');
            if (!target || !this.root.contains(target)) {
                return;
            }
            const card = target.closest('[data-region="card"]');
            switch (target.dataset.action) {
                case 'next':
                case 'skip':
                    this.goToCard(this.card + 1);
                    break;
                case 'back':
                    this.goToCard(this.card - 1);
                    break;
                case 'show-conditions':
                    this.showConditions();
                    break;
                case 'change-interests':
                    this.changeInterests();
                    break;
                case 'edit-answer':
                    this.editAnswer(target.closest('[data-region="summary-item"]'));
                    break;
                case 'cancel-answer':
                    this.closeEditor(target.closest('[data-region="summary-item"]'));
                    break;
                case 'save-answer':
                    this.saveEditedAnswer(target, target.closest('[data-region="summary-item"]'));
                    break;
                case 'back-to-results':
                case 'retry':
                    this.load(false);
                    break;
                case 'delete-data':
                    this.deleteData(target);
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
     * Whether the learner must accept the conditions before continuing.
     *
     * @returns {Boolean}
     */
    needsAcceptance() {
        return this.root.dataset.needsconsent === '1' || this.root.dataset.policyaccepted !== '1';
    }

    /**
     * Shows a validation message under the questions.
     *
     * @param {String} message Message.
     */
    showFormError(message) {
        const error = this.region('form-error');
        error.textContent = message;
        error.hidden = false;
    }

    /**
     * Hides the validation message.
     */
    hideFormError() {
        this.region('form-error').hidden = true;
    }

    /**
     * Checks that the conditions are accepted; otherwise explains it and moves the focus to the checkbox.
     *
     * @returns {Promise<Boolean>}
     */
    async checkAcceptance() {
        const checkbox = this.region('accept-checkbox');
        if (!this.needsAcceptance() || checkbox.checked) {
            return true;
        }
        this.showFormError(await getString('acceptrequired', 'block_aicourserecommender'));
        checkbox.focus();
        return false;
    }

    /**
     * Shows one question card.
     *
     * @param {Number} number Card number, starting at 1.
     */
    async goToCard(number) {
        const cards = Array.from(this.root.querySelectorAll('[data-region="question-card"]'));
        if (number < 1 || number > cards.length) {
            return;
        }
        if (this.card >= 1 && number > this.card && !(await this.checkAcceptance())) {
            return;
        }
        this.hideFormError();
        this.card = number;
        cards.forEach((card) => {
            card.hidden = parseInt(card.dataset.number, 10) !== number;
        });
        const current = cards[number - 1];
        this.announce(current.querySelector('.aicr-progress-text').textContent);
        current.querySelector('textarea').focus();
    }

    /**
     * Opens the conditions (privacy notice and AI usage policy of the site) in a dialogue.
     */
    async showConditions() {
        const title = await getString('conditionstitle', 'block_aicourserecommender');
        const modal = await Modal.create({
            title,
            body: this.region('conditions').innerHTML,
            show: true,
            removeOnClose: true,
        });
        modal.getRoot().on('modal:hidden', () => {
            this.root.querySelector('[data-action="show-conditions"]').focus();
        });
    }

    /**
     * Opens the wizard at the first card.
     */
    openWizard() {
        this.region('acceptance').hidden = !this.needsAcceptance();
        this.show('wizard');
        this.card = 0;
        this.goToCard(1);
    }

    /**
     * "Change my interests": the summary of the answers, or the wizard when there are none.
     */
    changeInterests() {
        if (this.root.dataset.hasanswers !== '1' || this.needsAcceptance()) {
            this.openWizard();
            return;
        }
        this.root.querySelectorAll('[data-region="summary-item"]').forEach((item) => this.closeEditor(item));
        this.show('summary');
        const first = this.region('summary').querySelector('[data-action="edit-answer"]');
        if (first) {
            first.focus();
        }
    }

    /**
     * Wizard textarea of a question slot.
     *
     * @param {String} slot Question slot.
     * @returns {HTMLTextAreaElement}
     */
    answerField(slot) {
        return this.root.querySelector(`textarea[data-slot="${slot}"]`);
    }

    /**
     * Opens the inline editor of one answer in the summary.
     *
     * @param {HTMLElement} item Summary item.
     */
    editAnswer(item) {
        const textarea = item.querySelector('[data-region="summary-textarea"]');
        textarea.value = this.answerField(item.dataset.slot).value;
        item.querySelector('[data-region="summary-view"]').hidden = true;
        item.querySelector('[data-region="summary-edit"]').hidden = false;
        textarea.focus();
    }

    /**
     * Closes the inline editor of one answer.
     *
     * @param {HTMLElement} item Summary item.
     */
    closeEditor(item) {
        const wasopen = !item.querySelector('[data-region="summary-edit"]').hidden;
        item.querySelector('[data-region="summary-edit"]').hidden = true;
        item.querySelector('[data-region="summary-view"]').hidden = false;
        if (wasopen) {
            item.querySelector('[data-action="edit-answer"]').focus();
        }
    }

    /**
     * Updates the summary texts from the wizard answers.
     */
    refreshSummary() {
        this.root.querySelectorAll('[data-region="summary-item"]').forEach((item) => {
            const answer = this.answerField(item.dataset.slot).value.trim();
            const view = item.querySelector('[data-region="summary-answer"]');
            view.textContent = answer || view.dataset.empty;
            view.classList.toggle('aicr-empty', !answer);
        });
    }

    /**
     * Saves one edited answer and loads new recommendations.
     *
     * @param {HTMLButtonElement} button Save button.
     * @param {HTMLElement} item Summary item.
     */
    async saveEditedAnswer(button, item) {
        const field = this.answerField(item.dataset.slot);
        const previous = field.value;
        field.value = item.querySelector('[data-region="summary-textarea"]').value;
        button.disabled = true;
        try {
            await this.saveAnswers();
            this.refreshSummary();
            await this.load(false);
        } catch (error) {
            field.value = previous;
            Notification.exception(error);
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Collects the answers of the wizard.
     *
     * @returns {Array} List of {slot, answer}.
     */
    collectAnswers() {
        return Array.from(this.root.querySelectorAll('textarea[data-slot]')).map((textarea) => ({
            slot: parseInt(textarea.dataset.slot, 10),
            answer: textarea.value.trim(),
        }));
    }

    /**
     * Saves the answers of the wizard.
     *
     * @returns {Promise}
     */
    saveAnswers() {
        return Repository.saveAnswers(this.collectAnswers());
    }

    /**
     * Last card: records the acceptance, saves the answers and loads the recommendations.
     */
    async submitWizard() {
        if (!this.collectAnswers().some((a) => a.answer !== '')) {
            this.showFormError(await getString('erroremptyanswers', 'block_aicourserecommender'));
            this.goToCard(1);
            return;
        }
        if (!(await this.checkAcceptance())) {
            return;
        }
        const button = this.root.querySelector('[data-action="save-answers"]');
        button.disabled = true;
        try {
            if (this.root.dataset.needsconsent === '1') {
                await Repository.saveConsent();
                this.root.dataset.needsconsent = '0';
            }
            if (this.root.dataset.policyaccepted !== '1') {
                await Policy.acceptPolicy();
                this.root.dataset.policyaccepted = '1';
            }
            await this.saveAnswers();
            this.root.dataset.hasanswers = '1';
            this.refreshSummary();
            await this.load(false);
        } catch (error) {
            this.showFormError(error.message || await getString('erroraifailed', 'block_aicourserecommender'));
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
                this.openWizard();
                return;
            case 'aipolicy':
                this.root.dataset.policyaccepted = '0';
                this.openWizard();
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
     * Deletes the data of the learner after confirmation and starts again.
     *
     * @param {HTMLButtonElement} button Delete button.
     */
    async deleteData(button) {
        const [title, question, label, done] = await getStrings([
            {key: 'deletemydata', component: 'block_aicourserecommender'},
            {key: 'deletemydataconfirm', component: 'block_aicourserecommender'},
            {key: 'delete', component: 'core'},
            {key: 'deletemydatadone', component: 'block_aicourserecommender'},
        ]);
        try {
            await Notification.saveCancelPromise(title, question, label);
        } catch (cancelled) {
            button.focus();
            return;
        }
        try {
            await Repository.deleteMyData();
        } catch (error) {
            Notification.exception(error);
            return;
        }
        this.root.dataset.hasanswers = '0';
        if (this.root.dataset.requireconsent === '1') {
            this.root.dataset.needsconsent = '1';
        }
        this.root.querySelectorAll('textarea[data-slot]').forEach((textarea) => {
            textarea.value = '';
            this.updateCounter(textarea);
        });
        this.region('accept-checkbox').checked = false;
        this.region('results').innerHTML = '';
        this.refreshSummary();
        this.openWizard();
        this.announce(done);
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
            {key: 'enrolconfirm', component: 'block_aicourserecommender', param: escapeHtml(button.dataset.name)},
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
