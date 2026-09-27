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
 * Deletes old personal data: activity, AI call log, answer history and expired rankings of inactive learners.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var int Minimum retention, so the daily limit and the report keep working. */
    public const MIN_DAYS = 30;

    /** @var string[] Tables cleaned, with the time field used. */
    public const TABLES = [
        'block_aicourserecommender_activity' => 'timecreated',
        'block_aicourserecommender_ailog' => 'timecreated',
        'block_aicourserecommender_answerhist' => 'timecreated',
        'block_aicourserecommender_feedback' => 'timemodified',
        'block_aicourserecommender_ranking' => 'timemodified',
    ];

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'block_aicourserecommender');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        $deleted = self::run(config::get_int('retentiondays'));
        mtrace("Old records deleted: {$deleted}.");
    }

    /**
     * Deletes records older than the retention period.
     *
     * @param int $days Retention in days; 0 keeps everything.
     * @return int Number of deleted records (approximate for databases that do not report it).
     */
    public static function run(int $days): int {
        global $DB;
        if ($days <= 0) {
            return 0;
        }
        $before = \core\di::get(\core\clock::class)->time() - max(self::MIN_DAYS, $days) * DAYSECS;
        $deleted = 0;
        foreach (self::TABLES as $table => $field) {
            $count = $DB->count_records_select($table, "$field < :before", ['before' => $before]);
            if ($count) {
                $DB->delete_records_select($table, "$field < :before", ['before' => $before]);
                $deleted += $count;
            }
        }
        return $deleted;
    }
}
