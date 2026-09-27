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
 * AJAX calls of the AI course recommender.
 *
 * @module     block_aicourserecommender/repository
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Calls one external function.
 *
 * @param {String} name Function name without the component prefix.
 * @param {Object} args Arguments.
 * @returns {Promise}
 */
const call = (name, args = {}) => Ajax.call([{
    methodname: 'block_aicourserecommender_' + name,
    args,
}])[0];

/**
 * First page of recommendations.
 *
 * @param {Boolean} forcerefresh Ignore the stored ranking.
 * @returns {Promise}
 */
export const getRecommendations = (forcerefresh = false) => call('get_recommendations', {forcerefresh});

/**
 * Next page of the stored ranking.
 *
 * @param {String} itemtype course or path.
 * @param {Number} offset Items already shown.
 * @returns {Promise}
 */
export const getMore = (itemtype, offset) => call('get_more', {itemtype, offset});

/**
 * Saves the questionnaire answers.
 *
 * @param {Array} answers List of {slot, answer}.
 * @returns {Promise}
 */
export const saveAnswers = (answers) => call('save_answers', {answers});

/**
 * Saves the consent.
 *
 * @returns {Promise}
 */
export const saveConsent = () => call('save_consent', {accept: true});

/**
 * Deletes the answers, consent, ranking, ratings and activity of the user.
 *
 * @returns {Promise}
 */
export const deleteMyData = () => call('delete_my_data', {});

/**
 * Saves a rating.
 *
 * @param {String} itemtype course or path.
 * @param {Number} itemid Item id.
 * @param {Number} rating 1 or -1.
 * @param {String} reason Optional reason.
 * @returns {Promise}
 */
export const submitFeedback = (itemtype, itemid, rating, reason = '') =>
    call('submit_feedback', {itemtype, itemid, rating, reason});

/**
 * Records a click.
 *
 * @param {String} itemtype course or path.
 * @param {Number} itemid Item id.
 * @param {Number} position Position in the ranking.
 * @returns {Promise}
 */
export const logClick = (itemtype, itemid, position) => call('log_click', {itemtype, itemid, position});

/**
 * Enrols the user in a course.
 *
 * @param {Number} courseid Course id.
 * @returns {Promise}
 */
export const enrolCourse = (courseid) => call('enrol_course', {courseid});

/**
 * Enrols the user in a learning path.
 *
 * @param {Number} pathid Path id.
 * @returns {Promise}
 */
export const enrolPath = (pathid) => call('enrol_path', {pathid});

/**
 * Generates a path description.
 *
 * @param {String} title Path title.
 * @param {Array} courseids Ordered course ids.
 * @returns {Promise}
 */
export const generatePathDescription = (title, courseids) => call('generate_path_description', {title, courseids});

/**
 * Generates a path image.
 *
 * @param {String} title Path title.
 * @param {String} description Path description.
 * @returns {Promise}
 */
export const generatePathImage = (title, description) => call('generate_path_image', {title, description});
