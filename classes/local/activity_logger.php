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

/**
 * Records impressions, clicks and enrolments of recommended items for the report.
 *
 * Standard log events are triggered too; this table keeps the report independent of the enabled log stores.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_logger {
    /** @var string Activity table. */
    public const TABLE = 'block_aicourserecommender_activity';

    /** @var string Item shown in the block. */
    public const ACTION_SHOWN = 'shown';
    /** @var string Item clicked. */
    public const ACTION_CLICK = 'click';
    /** @var string Enrolment from a recommendation or path. */
    public const ACTION_ENROL = 'enrol';

    /**
     * Records one action.
     *
     * @param int $userid User id.
     * @param string $action One of the ACTION_* constants.
     * @param string $itemtype "course" or "path".
     * @param int $itemid Item id.
     * @param int $position Position in the ranking, starting at 1, 0 when not applicable.
     * @return void
     */
    public static function log(int $userid, string $action, string $itemtype, int $itemid, int $position = 0): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'action' => $action,
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'position' => $position,
            'timecreated' => \core\di::get(\core\clock::class)->time(),
        ]);
    }

    /**
     * Records the impressions of a list of items.
     *
     * @param int $userid User id.
     * @param string $itemtype "course" or "path".
     * @param array[] $items Items with id and position.
     * @return void
     */
    public static function log_shown(int $userid, string $itemtype, array $items): void {
        global $DB;
        if (!$items) {
            return;
        }
        $now = \core\di::get(\core\clock::class)->time();
        $records = [];
        foreach ($items as $item) {
            $records[] = (object) [
                'userid' => $userid,
                'action' => self::ACTION_SHOWN,
                'itemtype' => $itemtype,
                'itemid' => (int) $item['id'],
                'position' => (int) $item['position'],
                'timecreated' => $now,
            ];
        }
        $DB->insert_records(self::TABLE, $records);
    }
}
