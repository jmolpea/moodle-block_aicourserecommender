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
 * Pre-computed English summaries of courses, used to keep the ranking prompt small.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_manager {
    /** @var string Summaries table. */
    public const TABLE = 'block_aicourserecommender_coursesum';

    /** @var int Maximum stored summary length in characters (about 80 words). */
    public const MAX_SUMMARY_LENGTH = 700;

    /**
     * Stored summaries of some courses.
     *
     * @param int[] $courseids Course ids.
     * @return \stdClass[] Records keyed by course id.
     */
    public static function get_summaries(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $courseids), SQL_PARAMS_NAMED);
        $result = [];
        foreach ($DB->get_records_select(self::TABLE, "courseid $insql", $params) as $record) {
            $result[(int) $record->courseid] = $record;
        }
        return $result;
    }

    /**
     * Generates or refreshes the summary of a course. Nothing is done if the metadata did not change.
     *
     * @param int $courseid Course id.
     * @param bool $force Regenerate even if the hash did not change.
     * @return bool True when a new summary was stored.
     */
    public static function update_course(int $courseid, bool $force = false): bool {
        global $DB;
        $metadata = course_data::get_metadata([$courseid])[$courseid] ?? null;
        if ($metadata === null) {
            return false;
        }
        $hash = course_data::hash($metadata);
        $existing = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        if (!$force && $existing && $existing->datahash === $hash && trim((string) $existing->summary) !== '') {
            return false;
        }

        $client = \core\di::get(ai_client::class);
        if (!$client->is_text_available()) {
            return false;
        }
        $result = $client->generate_text(
            prompt_builder::build_summary_prompt($metadata),
            (int) get_admin()->id,
            ai_client::CALL_SUMMARY
        );
        if (!$result['success'] || trim($result['text']) === '') {
            return false;
        }
        $summary = profile_collector::clean($result['text'], self::MAX_SUMMARY_LENGTH, \context_course::instance($courseid));

        $record = (object) [
            'courseid' => $courseid,
            'summary' => $summary,
            'datahash' => $hash,
            'timemodified' => \core\di::get(\core\clock::class)->time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
        return true;
    }

    /**
     * Courses that may be recommended and whose summary is missing or stale, oldest first.
     *
     * Only visible, not finished courses and courses that belong to a learning path are considered.
     *
     * @param int $limit Maximum number of course ids.
     * @return int[]
     */
    public static function get_courses_needing_summary(int $limit): array {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        $sql = "SELECT c.id, s.datahash
                  FROM {course} c
             LEFT JOIN {" . self::TABLE . "} s ON s.courseid = c.id
                 WHERE c.id <> :siteid
                   AND ((c.visible = 1 AND (c.enddate = 0 OR c.enddate > :now))
                        OR c.id IN (SELECT pc.courseid FROM {block_aicourserecommender_pathcourses} pc))
              ORDER BY CASE WHEN s.id IS NULL THEN 0 ELSE 1 END, s.timemodified ASC, c.id ASC";
        $rs = $DB->get_recordset_sql($sql, ['siteid' => SITEID, 'now' => $now]);
        $pending = [];
        $batch = [];
        foreach ($rs as $row) {
            $batch[(int) $row->id] = $row->datahash;
            if (count($batch) >= 200) {
                $pending = array_merge($pending, self::filter_stale($batch));
                $batch = [];
                if (count($pending) >= $limit) {
                    break;
                }
            }
        }
        $rs->close();
        if ($batch && count($pending) < $limit) {
            $pending = array_merge($pending, self::filter_stale($batch));
        }
        return array_slice($pending, 0, $limit);
    }

    /**
     * Returns the ids whose stored hash does not match the current metadata.
     *
     * @param array $hashes Stored hash (or null) keyed by course id.
     * @return int[]
     */
    protected static function filter_stale(array $hashes): array {
        $stale = [];
        foreach (course_data::get_metadata(array_keys($hashes)) as $courseid => $metadata) {
            if ($hashes[$courseid] === null || $hashes[$courseid] !== course_data::hash($metadata)) {
                $stale[] = (int) $courseid;
            }
        }
        return $stale;
    }

    /**
     * Deletes the summary of a course.
     *
     * @param int $courseid Course id.
     * @return void
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
