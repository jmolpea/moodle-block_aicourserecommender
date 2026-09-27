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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves the questionnaire answers of the current user.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_answers extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'answers' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Question slot'),
                'answer' => new external_value(PARAM_TEXT, 'Answer, max 1000 characters'),
            ])),
        ]);
    }

    /**
     * Saves the answers.
     *
     * @param array $answers Answers.
     * @return array
     */
    public static function execute(array $answers): array {
        global $USER;
        ['answers' => $answers] = self::validate_parameters(self::execute_parameters(), ['answers' => $answers]);
        $context = helper::require_user();
        self::validate_context($context);

        $byslot = [];
        foreach ($answers as $answer) {
            $byslot[(int) $answer['slot']] = $answer['answer'];
        }
        $saved = answers_manager::save_answers((int) $USER->id, $byslot);

        $result = [];
        foreach ($saved as $slot => $answer) {
            $result[] = ['slot' => $slot, 'answer' => $answer];
        }
        return ['success' => true, 'answers' => $result];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Saved'),
            'answers' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Question slot'),
                'answer' => new external_value(PARAM_TEXT, 'Saved answer'),
            ])),
        ]);
    }
}
