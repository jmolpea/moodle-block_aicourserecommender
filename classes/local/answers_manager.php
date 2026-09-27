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

namespace block_aicourserecommender\local;

use block_aicourserecommender\event\interests_updated;

/**
 * Stores the questionnaire answers, their history and the consent of each learner.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answers_manager {
    /** @var string Answers table. */
    public const TABLE = 'block_aicourserecommender_answers';

    /** @var string History table. */
    public const HISTORY_TABLE = 'block_aicourserecommender_answerhist';

    /** @var int Versions of the answers kept per learner. */
    public const MAX_HISTORY = 50;

    /**
     * Deletes the answers, history, ranking, ratings and activity of a user (right to erasure from the block).
     *
     * The AI call log is kept: it is needed for the daily limit and the cost report, and it is removed by the
     * retention task and the Privacy API.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function delete_my_data(int $userid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        foreach (
            [self::TABLE, self::HISTORY_TABLE, ranking_manager::TABLE, feedback_manager::TABLE,
                activity_logger::TABLE] as $table
        ) {
            $DB->delete_records($table, ['userid' => $userid]);
        }
        $transaction->allow_commit();
    }

    /**
     * Returns the stored record of a user, if any.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function get_record(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * Returns the latest answers of a user keyed by slot, or an empty array.
     *
     * @param int $userid User id.
     * @return array<int, string>
     */
    public static function get_answers(int $userid): array {
        $record = self::get_record($userid);
        if (!$record || $record->answers === null || $record->answers === '') {
            return [];
        }
        $answers = json_decode($record->answers, true);
        if (!is_array($answers)) {
            return [];
        }
        $result = [];
        foreach ($answers as $slot => $answer) {
            $result[(int) $slot] = (string) $answer;
        }
        return $result;
    }

    /**
     * Whether the user has saved at least one non-empty answer.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function has_answers(int $userid): bool {
        return count(array_filter(self::get_answers($userid), static fn($a) => trim($a) !== '')) > 0;
    }

    /**
     * Whether the user must still give consent before using the block.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function needs_consent(int $userid): bool {
        if (!config::get_int('requireconsent')) {
            return false;
        }
        $record = self::get_record($userid);
        return !$record || empty($record->consent);
    }

    /**
     * Records the consent of a user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function save_consent(int $userid): void {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        $record = self::get_record($userid);
        if ($record) {
            $record->consent = 1;
            $record->timeconsent = $now;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'answers' => null,
                'consent' => 1,
                'timeconsent' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Saves new answers, adds a history entry and invalidates the stored ranking.
     *
     * @param int $userid User id.
     * @param array $answers Answers keyed by slot (not yet cleaned).
     * @return array<int, string> The cleaned answers that were saved.
     */
    public static function save_answers(int $userid, array $answers): array {
        global $DB;
        $clean = questions::clean_answers($answers);
        if (!array_filter($clean, static fn($a) => $a !== '')) {
            throw new \moodle_exception('erroremptyanswers', 'block_aicourserecommender');
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $now = \core\di::get(\core\clock::class)->time();

        $transaction = $DB->start_delegated_transaction();
        $record = self::get_record($userid);
        if ($record) {
            $record->answers = $json;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record = (object) [
                'userid' => $userid,
                'answers' => $json,
                'consent' => 0,
                'timeconsent' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
        }
        $DB->insert_record(self::HISTORY_TABLE, (object) [
            'userid' => $userid,
            'answers' => $json,
            'timecreated' => $now,
        ]);
        // Keep only the last versions: the history is for the report, not an archive.
        $old = $DB->get_records_select(
            self::HISTORY_TABLE,
            'userid = :userid',
            ['userid' => $userid],
            'timecreated DESC, id DESC',
            'id',
            self::MAX_HISTORY
        );
        if ($old) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($old), SQL_PARAMS_NAMED);
            $DB->delete_records_select(self::HISTORY_TABLE, "id $insql", $params);
        }
        ranking_manager::invalidate($userid);
        $transaction->allow_commit();

        interests_updated::create([
            'objectid' => $record->id,
            'relateduserid' => $userid,
            'context' => \context_system::instance(),
        ])->trigger();

        return $clean;
    }
}
