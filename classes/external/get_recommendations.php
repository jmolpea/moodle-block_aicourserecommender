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

use block_aicourserecommender\local\ranking_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the first page of recommendations of the current user.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_recommendations extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'forcerefresh' => new external_value(
                PARAM_BOOL,
                'Ignore the stored ranking (subject to the daily limit)',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Returns the recommendations.
     *
     * @param bool $forcerefresh Ignore the stored ranking.
     * @return array
     */
    public static function execute(bool $forcerefresh = false): array {
        global $USER;
        ['forcerefresh' => $forcerefresh] = self::validate_parameters(
            self::execute_parameters(),
            ['forcerefresh' => $forcerefresh]
        );
        $context = helper::require_user();
        self::validate_context($context);

        $manager = new ranking_manager();
        return $manager->get_recommendations((int) $USER->id, $forcerefresh);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, noresults, error or questionnaire'),
            'notice' => new external_value(PARAM_TEXT, 'Notice to show above the results'),
            'error' => new external_value(PARAM_TEXT, 'Error message when status is error'),
            'courses' => helper::course_cards(),
            'paths' => helper::path_cards(),
            'hasmorecourses' => new external_value(PARAM_BOOL, 'More courses in the stored ranking'),
            'hasmorepaths' => new external_value(PARAM_BOOL, 'More paths in the stored ranking'),
        ]);
    }
}
