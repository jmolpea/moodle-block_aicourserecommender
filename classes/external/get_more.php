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
 * Returns the next page of the stored ranking without calling the AI.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_more extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemtype' => new external_value(PARAM_ALPHA, 'course or path'),
            'offset' => new external_value(PARAM_INT, 'Number of items already shown'),
        ]);
    }

    /**
     * Returns the next page.
     *
     * @param string $itemtype course or path.
     * @param int $offset Items already shown.
     * @return array
     */
    public static function execute(string $itemtype, int $offset): array {
        global $USER;
        ['itemtype' => $itemtype, 'offset' => $offset] = self::validate_parameters(
            self::execute_parameters(),
            ['itemtype' => $itemtype, 'offset' => $offset]
        );
        $context = helper::require_user();
        self::validate_context($context);
        if (!in_array($itemtype, ['course', 'path'], true)) {
            throw new \invalid_parameter_exception('Invalid item type');
        }

        $page = (new ranking_manager())->get_more((int) $USER->id, $itemtype, max(0, $offset));
        return [
            'courses' => $itemtype === 'course' ? $page['items'] : [],
            'paths' => $itemtype === 'path' ? $page['items'] : [],
            'hasmore' => $page['hasmore'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => helper::course_cards(),
            'paths' => helper::path_cards(),
            'hasmore' => new external_value(PARAM_BOOL, 'More items in the stored ranking'),
        ]);
    }
}
