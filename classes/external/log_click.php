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

use block_aicourserecommender\event\recommendation_clicked;
use block_aicourserecommender\local\activity_logger;
use block_aicourserecommender\local\config;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Records a click on a recommended course or path.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_click extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemtype' => new external_value(PARAM_ALPHA, 'course or path'),
            'itemid' => new external_value(PARAM_INT, 'Item id'),
            'position' => new external_value(PARAM_INT, 'Position in the ranking, starting at 1'),
        ]);
    }

    /**
     * Records the click.
     *
     * @param string $itemtype course or path.
     * @param int $itemid Item id.
     * @param int $position Position.
     * @return array
     */
    public static function execute(string $itemtype, int $itemid, int $position): array {
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['itemtype' => $itemtype, 'itemid' => $itemid, 'position' => $position]
        );
        $context = helper::require_user();
        self::validate_context($context);
        helper::require_ranked_item((int) $USER->id, $params['itemtype'], $params['itemid']);
        $maxposition = config::get_int($params['itemtype'] === 'path' ? 'maxpaths' : 'maxranked');
        $position = max(0, min($maxposition, $params['position']));

        activity_logger::log((int) $USER->id, activity_logger::ACTION_CLICK, $params['itemtype'], $params['itemid'], $position);
        recommendation_clicked::create([
            'context' => $context,
            'relateduserid' => (int) $USER->id,
            'other' => [
                'itemtype' => $params['itemtype'],
                'itemid' => $params['itemid'],
                'position' => $position,
            ],
        ])->trigger();
        return ['success' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Recorded'),
        ]);
    }
}
