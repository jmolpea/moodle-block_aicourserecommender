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

use block_aicourserecommender\event\recommendation_rated;

/**
 * Thumbs up / thumbs down ratings of recommendations.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_manager {
    /** @var string Feedback table. */
    public const TABLE = 'block_aicourserecommender_feedback';

    /** @var int Maximum length of the reason. */
    public const MAX_REASON_LENGTH = 200;

    /** @var int Number of recent negative ratings sent to the AI. */
    public const NEGATIVE_IN_PROMPT = 10;

    /**
     * Saves a rating. There is one rating per user and item; a new one replaces the old one.
     *
     * @param int $userid User id.
     * @param string $itemtype "course" or "path".
     * @param int $itemid Item id.
     * @param int $rating 1 or -1.
     * @param string $reason Optional reason, only kept for negative ratings.
     * @return int Feedback record id.
     */
    public static function save(int $userid, string $itemtype, int $itemid, int $rating, string $reason = ''): int {
        global $DB;
        if (!in_array($itemtype, ['course', 'path'], true)) {
            throw new \coding_exception('Invalid item type');
        }
        $rating = $rating >= 0 ? 1 : -1;
        $reason = $rating < 0 ? trim(\core_text::substr(clean_param($reason, PARAM_TEXT), 0, self::MAX_REASON_LENGTH)) : '';
        $now = \core\di::get(\core\clock::class)->time();

        $record = $DB->get_record(self::TABLE, ['userid' => $userid, 'itemtype' => $itemtype, 'itemid' => $itemid]);
        if ($record) {
            $record->rating = $rating;
            $record->reason = $reason;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record = (object) [
                'userid' => $userid,
                'itemtype' => $itemtype,
                'itemid' => $itemid,
                'rating' => $rating,
                'reason' => $reason,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
        }

        recommendation_rated::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => ['itemtype' => $itemtype, 'itemid' => $itemid, 'rating' => $rating],
        ])->trigger();

        return (int) $record->id;
    }

    /**
     * Ratings of a user keyed by "type-id".
     *
     * @param int $userid User id.
     * @return array<string, int>
     */
    public static function get_user_ratings(int $userid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(self::TABLE, ['userid' => $userid], '', 'id, itemtype, itemid, rating') as $record) {
            $result[$record->itemtype . '-' . $record->itemid] = (int) $record->rating;
        }
        return $result;
    }

    /**
     * Ids of the items rated negatively within the exclusion period.
     *
     * @param int $userid User id.
     * @param string $itemtype "course" or "path".
     * @return int[]
     */
    public static function get_excluded_ids(int $userid, string $itemtype): array {
        global $DB;
        $days = config::get_int('negativedays');
        if ($days <= 0) {
            return [];
        }
        $since = \core\di::get(\core\clock::class)->time() - $days * DAYSECS;
        return array_map('intval', $DB->get_fieldset_select(
            self::TABLE,
            'itemid',
            'userid = :userid AND itemtype = :itemtype AND rating < 0 AND timemodified >= :since',
            ['userid' => $userid, 'itemtype' => $itemtype, 'since' => $since]
        ));
    }

    /**
     * Last negative ratings of a user as "name: reason" lines for the prompt.
     *
     * @param int $userid User id.
     * @return string[]
     */
    public static function get_negative_for_prompt(int $userid): array {
        global $DB;
        $records = $DB->get_records_select(
            self::TABLE,
            'userid = :userid AND rating < 0',
            ['userid' => $userid],
            'timemodified DESC',
            '*',
            0,
            self::NEGATIVE_IN_PROMPT
        );
        $lines = [];
        $system = \context_system::instance();
        foreach ($records as $record) {
            if ($record->itemtype === 'course') {
                $name = $DB->get_field('course', 'fullname', ['id' => $record->itemid]);
            } else {
                $name = $DB->get_field(path_manager::TABLE, 'name', ['id' => $record->itemid]);
            }
            if ($name === false) {
                continue;
            }
            $line = $record->itemtype . ' "' . profile_collector::clean($name, 255, $system) . '"';
            if (trim((string) $record->reason) !== '') {
                $line .= ': ' . $record->reason;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
