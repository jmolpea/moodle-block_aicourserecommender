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

use block_aicourserecommender\local\config;

/**
 * Refreshes the summaries whose metadata changed, after a course custom field changed.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_summaries extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrefreshsummaries', 'block_aicourserecommender');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        generate_summaries::run(config::get_int('summariesperrun'));
    }
}
