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

use block_aicourserecommender\local\enrolment_helper;
use block_aicourserecommender\local\path_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Enrols the current user in every course of a learning path with direct enrolment available.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_path extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pathid' => new external_value(PARAM_INT, 'Path id'),
        ]);
    }

    /**
     * Enrols the user in the path.
     *
     * @param int $pathid Path id.
     * @return array
     */
    public static function execute(int $pathid): array {
        ['pathid' => $pathid] = self::validate_parameters(self::execute_parameters(), ['pathid' => $pathid]);
        $context = helper::require_user(false);
        self::validate_context($context);
        $path = path_manager::get_path($pathid);
        if (!$path || (!$path->visible && !has_capability('block/aicourserecommender:managepaths', $context))) {
            throw new \moodle_exception('errorpathnotfound', 'block_aicourserecommender');
        }

        $results = (new enrolment_helper())->enrol_path($pathid);
        $count = 0;
        foreach ($results as $i => $result) {
            if ($result['status'] === 'enrolled') {
                $count++;
            }
            $results[$i]['statuslabel'] = get_string('enrolresult_' . $result['status'], 'block_aicourserecommender');
        }
        return ['success' => $count > 0, 'count' => $count, 'results' => $results];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'At least one enrolment'),
            'count' => new external_value(PARAM_INT, 'Number of new enrolments'),
            'results' => new external_multiple_structure(new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'name' => new external_value(PARAM_RAW, 'Formatted course name'),
                'status' => new external_value(PARAM_ALPHA, 'enrolled, already or unavailable'),
                'statuslabel' => new external_value(PARAM_TEXT, 'Status as text'),
                'reason' => new external_value(PARAM_TEXT, 'Why the user could not be enrolled, empty otherwise'),
            ])),
        ]);
    }
}
