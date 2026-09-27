<?php
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

namespace block_aicourserecommender\external;

use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\local\ranking_manager;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared checks and return structures of the external functions.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Validates the system context and checks that the current user may use the recommender.
     *
     * @param bool $requireconsent Also require the consent when the setting is on.
     * @return \context_system
     */
    public static function require_user(bool $requireconsent = true): \context_system {
        global $USER;
        $context = \context_system::instance();
        if (!isloggedin() || isguestuser()) {
            throw new \require_login_exception('Guests cannot use the course recommender');
        }
        require_capability('block/aicourserecommender:use', $context);
        if ($requireconsent && answers_manager::needs_consent((int) $USER->id)) {
            throw new \moodle_exception('errorconsentrequired', 'block_aicourserecommender');
        }
        return $context;
    }

    /**
     * Checks that an item was recommended to the user. Ratings, clicks and enrolments from recommendations are only
     * accepted for items of the stored ranking, so they cannot be forged for arbitrary courses or paths.
     *
     * @param int $userid User id.
     * @param string $itemtype "course" or "path".
     * @param int $itemid Item id.
     * @return void
     */
    public static function require_ranked_item(int $userid, string $itemtype, int $itemid): void {
        if (!in_array($itemtype, ['course', 'path'], true)) {
            throw new \invalid_parameter_exception('Invalid item type');
        }
        if (!ranking_manager::is_ranked($userid, $itemtype, $itemid)) {
            throw new \invalid_parameter_exception('The item was not recommended to this user');
        }
    }

    /**
     * Checks that the current user accepted the AI usage policy of the site.
     *
     * @param \block_aicourserecommender\local\ai_client $client AI client.
     * @return void
     */
    public static function require_ai_policy(\block_aicourserecommender\local\ai_client $client): void {
        global $USER;
        if (!$client->has_accepted_policy((int) $USER->id)) {
            throw new \moodle_exception('erroraipolicy', 'block_aicourserecommender');
        }
    }

    /**
     * Structure of a course card.
     *
     * @return external_single_structure
     */
    public static function course_card_structure(): external_single_structure {
        return new external_single_structure([
            'type' => new external_value(PARAM_ALPHA, 'Item type: course'),
            'id' => new external_value(PARAM_INT, 'Course id'),
            'position' => new external_value(PARAM_INT, 'Position in the ranking, starting at 1'),
            'name' => new external_value(PARAM_RAW, 'Formatted course name'),
            'plainname' => new external_value(PARAM_TEXT, 'Course name as plain text'),
            'category' => new external_value(PARAM_RAW, 'Formatted category name'),
            'image' => new external_value(PARAM_RAW, 'Course image URL'),
            'startdate' => new external_value(PARAM_RAW, 'Formatted start date, empty if none'),
            'enddate' => new external_value(PARAM_RAW, 'Formatted end date, empty if none'),
            'hasdates' => new external_value(PARAM_BOOL, 'Whether the course has a start or end date'),
            'reason' => new external_value(PARAM_TEXT, 'Why the course is recommended'),
            'score' => new external_value(PARAM_INT, 'Relevance score 0-100'),
            'viewurl' => new external_value(PARAM_URL, 'Course URL'),
            'canenrol' => new external_value(PARAM_BOOL, 'Whether direct enrolment is available'),
            'rating' => new external_value(PARAM_INT, 'Current rating of the user: 1, -1 or 0'),
            'ratedup' => new external_value(PARAM_BOOL, 'Rated as useful'),
            'rateddown' => new external_value(PARAM_BOOL, 'Rated as not useful'),
        ]);
    }

    /**
     * Structure of a path card.
     *
     * @return external_single_structure
     */
    public static function path_card_structure(): external_single_structure {
        return new external_single_structure([
            'type' => new external_value(PARAM_ALPHA, 'Item type: path'),
            'id' => new external_value(PARAM_INT, 'Path id'),
            'position' => new external_value(PARAM_INT, 'Position in the ranking, starting at 1'),
            'name' => new external_value(PARAM_RAW, 'Formatted path name'),
            'image' => new external_value(PARAM_RAW, 'Path image URL'),
            'coursecount' => new external_value(PARAM_INT, 'Number of courses in the path'),
            'coursecounttext' => new external_value(PARAM_TEXT, 'Number of courses as text'),
            'reason' => new external_value(PARAM_TEXT, 'Why the path is recommended'),
            'score' => new external_value(PARAM_INT, 'Relevance score 0-100'),
            'viewurl' => new external_value(PARAM_URL, 'Path landing page URL'),
            'rating' => new external_value(PARAM_INT, 'Current rating of the user: 1, -1 or 0'),
            'ratedup' => new external_value(PARAM_BOOL, 'Rated as useful'),
            'rateddown' => new external_value(PARAM_BOOL, 'Rated as not useful'),
        ]);
    }

    /**
     * List of course cards.
     *
     * @return external_multiple_structure
     */
    public static function course_cards(): external_multiple_structure {
        return new external_multiple_structure(self::course_card_structure(), 'Course cards');
    }

    /**
     * List of path cards.
     *
     * @return external_multiple_structure
     */
    public static function path_cards(): external_multiple_structure {
        return new external_multiple_structure(self::path_card_structure(), 'Path cards');
    }
}
