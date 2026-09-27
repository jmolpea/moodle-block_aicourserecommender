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

namespace block_aicourserecommender\output;

use block_aicourserecommender\local\enrolment_helper;
use block_aicourserecommender\local\feedback_manager;
use block_aicourserecommender\local\path_manager;

/**
 * Exports ranked courses and paths as template data for the result cards.
 *
 * The structure is plain scalars so the same data can be returned by the external functions (and a future mobile app).
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class card_exporter {
    /**
     * Course cards.
     *
     * @param array[] $items Ranked items (id, score, reason) in order.
     * @param int $userid User id.
     * @param int $offset Position offset of the first item.
     * @return array[]
     */
    public static function export_courses(array $items, int $userid, int $offset): array {
        global $DB, $OUTPUT;
        if (!$items) {
            return [];
        }
        $ids = array_map(static fn($i) => (int) $i['id'], $items);
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_select('course', "id $insql", $params);
        $ratings = feedback_manager::get_user_ratings($userid);
        $helper = new enrolment_helper();
        $dateformat = get_string('strftimedate', 'core_langconfig');

        $cards = [];
        $position = $offset;
        foreach ($items as $item) {
            $course = $courses[(int) $item['id']] ?? null;
            if (!$course) {
                continue;
            }
            $position++;
            $context = \context_course::instance($course->id);
            $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
            $image = \core_course\external\course_summary_exporter::get_course_image($course);
            $rating = $ratings['course-' . $course->id] ?? 0;
            $cards[] = [
                'type' => 'course',
                'id' => (int) $course->id,
                'position' => $position,
                'name' => format_string($course->fullname, true, ['context' => $context]),
                'plainname' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                'category' => $category ? $category->get_formatted_name() : '',
                'image' => $image ?: $OUTPUT->get_generated_image_for_id($course->id),
                'startdate' => $course->startdate ? userdate($course->startdate, $dateformat) : '',
                'enddate' => $course->enddate ? userdate($course->enddate, $dateformat) : '',
                'hasdates' => !empty($course->startdate) || !empty($course->enddate),
                'reason' => (string) ($item['reason'] ?? ''),
                'score' => (int) ($item['score'] ?? 0),
                'viewurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'canenrol' => $helper->get_direct_instance((int) $course->id, $userid) !== null,
                'rating' => $rating,
                'ratedup' => $rating > 0,
                'rateddown' => $rating < 0,
            ];
        }
        return $cards;
    }

    /**
     * Path cards.
     *
     * @param array[] $items Ranked items (id, score, reason) in order.
     * @param array $candidatepaths Output of path_manager::get_candidate_paths().
     * @param int $userid User id.
     * @param int $offset Position offset of the first item.
     * @return array[]
     */
    public static function export_paths(array $items, array $candidatepaths, int $userid, int $offset): array {
        if (!$items) {
            return [];
        }
        $ratings = feedback_manager::get_user_ratings($userid);
        $cards = [];
        $position = $offset;
        foreach ($items as $item) {
            $pathid = (int) $item['id'];
            if (!isset($candidatepaths[$pathid])) {
                continue;
            }
            $path = $candidatepaths[$pathid]['path'];
            $position++;
            $rating = $ratings['path-' . $pathid] ?? 0;
            $count = count($candidatepaths[$pathid]['courses']);
            $cards[] = [
                'type' => 'path',
                'id' => $pathid,
                'position' => $position,
                'name' => path_manager::format_name($path),
                'image' => path_manager::get_image_url($pathid),
                'coursecount' => $count,
                'coursecounttext' => get_string(
                    $count === 1 ? 'pathcoursecountone' : 'pathcoursecount',
                    'block_aicourserecommender',
                    $count
                ),
                'reason' => (string) ($item['reason'] ?? ''),
                'score' => (int) ($item['score'] ?? 0),
                'viewurl' => (new \moodle_url('/blocks/aicourserecommender/path.php', ['id' => $pathid]))->out(false),
                'rating' => $rating,
                'ratedup' => $rating > 0,
                'rateddown' => $rating < 0,
            ];
        }
        return $cards;
    }
}
