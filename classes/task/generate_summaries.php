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

namespace block_aicourserecommender\task;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\config;
use block_aicourserecommender\local\summary_manager;

/**
 * Generates missing or stale course summaries, a limited number per run to control the cost.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_summaries extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskgeneratesummaries', 'block_aicourserecommender');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        self::run(config::get_int('summariesperrun'));
    }

    /**
     * Generates up to $limit summaries.
     *
     * @param int $limit Maximum number of AI calls.
     * @return int Number of summaries generated.
     */
    public static function run(int $limit): int {
        if ($limit <= 0 || !\core\di::get(ai_client::class)->is_text_available()) {
            mtrace('AI text generation is not available, or the limit is 0. Nothing to do.');
            return 0;
        }
        $done = 0;
        foreach (summary_manager::get_courses_needing_summary($limit) as $courseid) {
            if (summary_manager::update_course($courseid)) {
                $done++;
                mtrace("Summary generated for course {$courseid}.");
            } else {
                mtrace("Summary not generated for course {$courseid}.");
            }
        }
        return $done;
    }
}
