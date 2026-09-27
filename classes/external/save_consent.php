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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Records that the current user accepted the privacy notice of the recommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_consent extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'accept' => new external_value(PARAM_BOOL, 'The user accepts the notice'),
        ]);
    }

    /**
     * Saves the consent.
     *
     * @param bool $accept Must be true.
     * @return array
     */
    public static function execute(bool $accept): array {
        global $USER;
        ['accept' => $accept] = self::validate_parameters(self::execute_parameters(), ['accept' => $accept]);
        $context = helper::require_user(false);
        self::validate_context($context);
        if (!$accept) {
            return ['success' => false];
        }
        answers_manager::save_consent((int) $USER->id);
        return ['success' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Consent saved'),
        ]);
    }
}
