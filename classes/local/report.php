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
 * Totals of the usage report.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /**
     * Raw totals for a period.
     *
     * @param int $from Start timestamp.
     * @param int $to End timestamp.
     * @return array<string, int|float>
     */
    public static function get_raw_totals(int $from, int $to): array {
        global $DB;
        $range = ['from' => $from, 'to' => $to];
        $activity = function (string $action, ?string $itemtype = null) use ($DB, $range): int {
            $where = 'action = :action AND timecreated >= :from AND timecreated <= :to';
            $params = $range + ['action' => $action];
            if ($itemtype !== null) {
                $where .= ' AND itemtype = :itemtype';
                $params['itemtype'] = $itemtype;
            }
            return $DB->count_records_select(activity_logger::TABLE, $where, $params);
        };

        $users = (int) $DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {block_aicourserecommender_answerhist}
                                                 WHERE timecreated >= :from AND timecreated <= :to', $range);
        $calls = $DB->count_records_select(ai_client::LOG_TABLE, 'timecreated >= :from AND timecreated <= :to', $range);
        $errors = $DB->count_records_select(
            ai_client::LOG_TABLE,
            'success = 0 AND timecreated >= :from AND timecreated <= :to',
            $range
        );
        $shown = $activity(activity_logger::ACTION_SHOWN);
        $clicks = $activity(activity_logger::ACTION_CLICK);
        $enrolments = $activity(activity_logger::ACTION_ENROL, 'course');
        $positive = $DB->count_records_select(
            feedback_manager::TABLE,
            'rating > 0 AND timemodified >= :from AND timemodified <= :to',
            $range
        );
        $negative = $DB->count_records_select(
            feedback_manager::TABLE,
            'rating < 0 AND timemodified >= :from AND timemodified <= :to',
            $range
        );

        return [
            'users' => $users,
            'aicalls' => $calls,
            'aierrors' => $errors,
            'shown' => $shown,
            'clicks' => $clicks,
            'ctr' => $shown ? round($clicks * 100 / $shown, 1) : 0.0,
            'enrolments' => $enrolments,
            'positive' => $positive,
            'negative' => $negative,
        ];
    }

    /**
     * Totals ready for the template.
     *
     * @param int $from Start timestamp.
     * @param int $to End timestamp.
     * @return array[] Each with label and value.
     */
    public static function get_totals(int $from, int $to): array {
        $result = [];
        foreach (self::get_raw_totals($from, $to) as $key => $value) {
            $result[] = [
                'key' => $key,
                'label' => get_string('report_' . $key, 'block_aicourserecommender'),
                'value' => $key === 'ctr' ? format_float($value, 1) . ' %' : (string) $value,
            ];
        }
        return $result;
    }
}
