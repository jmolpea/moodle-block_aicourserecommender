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

use block_aicourserecommender\local\feedback_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves a thumbs up / thumbs down rating of a recommendation.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_feedback extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemtype' => new external_value(PARAM_ALPHA, 'course or path'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'rating' => new external_value(PARAM_INT, '1 (useful) or -1 (not useful)'),
            'reason' => new external_value(PARAM_TEXT, 'Why it does not fit, max 200 characters', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Saves the rating.
     *
     * @param string $itemtype course or path.
     * @param int $itemid Item id.
     * @param int $rating 1 or -1.
     * @param string $reason Optional reason.
     * @return array
     */
    public static function execute(string $itemtype, int $itemid, int $rating, string $reason = ''): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['itemtype' => $itemtype, 'itemid' => $itemid, 'rating' => $rating, 'reason' => $reason]
        );
        $context = helper::require_user();
        self::validate_context($context);
        helper::require_ranked_item((int) $USER->id, $params['itemtype'], $params['itemid']);
        $rating = $params['rating'] >= 0 ? 1 : -1;
        feedback_manager::save((int) $USER->id, $params['itemtype'], $params['itemid'], $rating, $params['reason']);
        return ['success' => true, 'rating' => $rating];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Saved'),
            'rating' => new external_value(PARAM_INT, 'Saved rating'),
        ]);
    }
}
