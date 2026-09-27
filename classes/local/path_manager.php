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
 * Learning paths: storage, ordering, images, progress and candidate paths.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_manager {
    /** @var string Paths table. */
    public const TABLE = 'block_aicourserecommender_paths';

    /** @var string Path courses table. */
    public const COURSES_TABLE = 'block_aicourserecommender_pathcourses';

    /** @var string File area of the path images. */
    public const FILEAREA = 'pathimage';

    /** @var int Maximum description length sent in the ranking prompt. */
    public const PROMPT_DESCRIPTION_LENGTH = 500;

    /**
     * Returns a path.
     *
     * @param int $pathid Path id.
     * @param int $strictness IGNORE_MISSING or MUST_EXIST.
     * @return \stdClass|false
     */
    public static function get_path(int $pathid, int $strictness = IGNORE_MISSING) {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $pathid], '*', $strictness);
    }

    /**
     * Returns all paths ordered by sortorder.
     *
     * @param bool $visibleonly Only visible paths.
     * @return \stdClass[] Keyed by id.
     */
    public static function get_paths(bool $visibleonly = false): array {
        global $DB;
        $conditions = $visibleonly ? ['visible' => 1] : [];
        return $DB->get_records(self::TABLE, $conditions, 'sortorder ASC, id ASC');
    }

    /**
     * Ordered course ids of a path.
     *
     * @param int $pathid Path id.
     * @return int[]
     */
    public static function get_course_ids(int $pathid): array {
        global $DB;
        // Explicit ORDER BY: get_fieldset_select() cannot sort and databases return rows in different orders.
        $sql = "SELECT courseid
                  FROM {" . self::COURSES_TABLE . "}
                 WHERE pathid = :pathid
              ORDER BY sortorder ASC, id ASC";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['pathid' => $pathid]));
    }

    /**
     * Ordered course ids of several paths.
     *
     * @param int[] $pathids Path ids.
     * @param bool $visibleonly Only visible courses (what learners may see).
     * @return array<int, int[]> Keyed by path id.
     */
    public static function get_courses_of_paths(array $pathids, bool $visibleonly = false): array {
        global $DB;
        if (!$pathids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($pathids, SQL_PARAMS_NAMED);
        $sql = "SELECT pc.id, pc.pathid, pc.courseid
                  FROM {" . self::COURSES_TABLE . "} pc
                  JOIN {course} c ON c.id = pc.courseid
                 WHERE pc.pathid $insql" . ($visibleonly ? ' AND c.visible = 1' : '') . "
              ORDER BY pc.pathid, pc.sortorder, pc.id";
        $result = array_fill_keys(array_map('intval', $pathids), []);
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $result[(int) $row->pathid][] = (int) $row->courseid;
        }
        return $result;
    }

    /**
     * Creates or updates a path and its ordered courses.
     *
     * @param \stdClass $data Fields: id (optional), name, description, descriptionformat, visible.
     * @param int[] $courseids Ordered course ids.
     * @return int Path id.
     */
    public static function save_path(\stdClass $data, array $courseids): int {
        global $DB, $USER;
        $now = \core\di::get(\core\clock::class)->time();
        $record = (object) [
            'name' => trim((string) $data->name),
            'description' => (string) ($data->description ?? ''),
            'descriptionformat' => (int) ($data->descriptionformat ?? FORMAT_HTML),
            'visible' => empty($data->visible) ? 0 : 1,
            'usermodified' => (int) $USER->id,
            'timemodified' => $now,
        ];

        $transaction = $DB->start_delegated_transaction();
        if (!empty($data->id)) {
            $record->id = (int) $data->id;
            $DB->update_record(self::TABLE, $record);
            $pathid = $record->id;
        } else {
            $record->timecreated = $now;
            $record->sortorder = (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {' . self::TABLE . '}') + 1;
            $pathid = (int) $DB->insert_record(self::TABLE, $record);
        }

        $DB->delete_records(self::COURSES_TABLE, ['pathid' => $pathid]);
        $sortorder = 0;
        foreach (array_values(array_unique(array_map('intval', $courseids))) as $courseid) {
            if ($courseid <= 0 || $courseid == SITEID) {
                continue;
            }
            $DB->insert_record(self::COURSES_TABLE, (object) [
                'pathid' => $pathid,
                'courseid' => $courseid,
                'sortorder' => ++$sortorder,
            ]);
        }
        $transaction->allow_commit();
        return $pathid;
    }

    /**
     * Deletes a path, its courses, image, ratings and activity.
     *
     * @param int $pathid Path id.
     * @return void
     */
    public static function delete_path(int $pathid): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::COURSES_TABLE, ['pathid' => $pathid]);
        $DB->delete_records('block_aicourserecommender_feedback', ['itemtype' => 'path', 'itemid' => $pathid]);
        $DB->delete_records('block_aicourserecommender_activity', ['itemtype' => 'path', 'itemid' => $pathid]);
        $DB->delete_records(self::TABLE, ['id' => $pathid]);
        get_file_storage()->delete_area_files(\context_system::instance()->id, config::COMPONENT, self::FILEAREA, $pathid);
        $transaction->allow_commit();
    }

    /**
     * Shows or hides a path.
     *
     * @param int $pathid Path id.
     * @param bool $visible Visibility.
     * @return void
     */
    public static function set_visible(int $pathid, bool $visible): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $pathid,
            'visible' => $visible ? 1 : 0,
            'timemodified' => \core\di::get(\core\clock::class)->time(),
        ]);
    }

    /**
     * Moves a path one position up or down.
     *
     * @param int $pathid Path id.
     * @param int $direction -1 to move up, 1 to move down.
     * @return void
     */
    public static function move(int $pathid, int $direction): void {
        global $DB;
        $ids = array_keys(self::get_paths());
        $index = array_search($pathid, $ids);
        $target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);
        if ($target === false || $target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $transaction = $DB->start_delegated_transaction();
        foreach ($ids as $position => $id) {
            $DB->set_field(self::TABLE, 'sortorder', $position + 1, ['id' => $id]);
        }
        $transaction->allow_commit();
    }

    /**
     * Formatted path name for the current language.
     *
     * @param \stdClass $path Path record.
     * @return string
     */
    public static function format_name(\stdClass $path): string {
        return format_string($path->name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Formatted path description for the current language.
     *
     * @param \stdClass $path Path record.
     * @return string HTML.
     */
    public static function format_description(\stdClass $path): string {
        return format_text((string) $path->description, (int) $path->descriptionformat, ['context' => \context_system::instance()]);
    }

    /**
     * URL of the path image, or a generated pattern when there is none.
     *
     * @param int $pathid Path id.
     * @return string
     */
    public static function get_image_url(int $pathid): string {
        global $OUTPUT;
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            config::COMPONENT,
            self::FILEAREA,
            $pathid,
            'itemid, filepath, filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_valid_image()) {
                return \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }
        return $OUTPUT->get_generated_image_for_id($pathid + 100000);
    }

    /**
     * Visible paths that contain at least one candidate course.
     *
     * @param int[] $candidateids Candidate course ids of the user.
     * @return array<int, array> Keyed by path id: ['path' => record, 'courses' => ordered ids].
     */
    public static function get_candidate_paths(array $candidateids): array {
        if (!config::get_int('showpaths')) {
            return [];
        }
        $paths = self::get_paths(true);
        if (!$paths) {
            return [];
        }
        $candidates = array_flip($candidateids);
        $result = [];
        // Hidden courses are never sent to the AI nor counted for learners.
        foreach (self::get_courses_of_paths(array_keys($paths), true) as $pathid => $courseids) {
            foreach ($courseids as $courseid) {
                if (isset($candidates[$courseid])) {
                    $result[$pathid] = ['path' => $paths[$pathid], 'courses' => $courseids];
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Compact data of candidate paths for the ranking prompt, and a hash of each one.
     *
     * @param array $paths Output of {@see get_candidate_paths()}.
     * @param int[] $candidateids Candidate course ids of the user.
     * @return array{items: array[], hashes: array<int, string>}
     */
    public static function get_prompt_data(array $paths, array $candidateids): array {
        global $DB;
        $candidates = array_flip($candidateids);
        $allcourseids = [];
        foreach ($paths as $path) {
            $allcourseids = array_merge($allcourseids, $path['courses']);
        }
        $allcourseids = array_values(array_unique($allcourseids));
        $names = [];
        if ($allcourseids) {
            [$insql, $params] = $DB->get_in_or_equal($allcourseids, SQL_PARAMS_NAMED);
            $names = $DB->get_records_select_menu('course', "id $insql", $params, '', 'id, fullname');
        }
        $summaries = summary_manager::get_summaries($allcourseids);
        $system = \context_system::instance();

        $items = [];
        $hashes = [];
        foreach ($paths as $pathid => $path) {
            $courses = [];
            foreach ($path['courses'] as $courseid) {
                if (!isset($names[$courseid])) {
                    continue;
                }
                $summary = isset($summaries[$courseid]) ? $summaries[$courseid]->summary : '';
                $courses[] = [
                    'name' => profile_collector::clean($names[$courseid], 255, $system),
                    'summary' => \core_text::substr((string) $summary, 0, 200),
                    'opennow' => isset($candidates[$courseid]),
                ];
            }
            $item = [
                'id' => (int) $pathid,
                'title' => profile_collector::clean($path['path']->name, 255, $system),
                'description' => profile_collector::clean(
                    (string) $path['path']->description,
                    self::PROMPT_DESCRIPTION_LENGTH,
                    $system
                ),
                'courses' => $courses,
            ];
            $items[] = $item;
            $hashes[(int) $pathid] = sha1(json_encode([$item['title'], $item['description'], $path['courses'],
                $path['path']->timemodified]));
        }
        return ['items' => $items, 'hashes' => $hashes];
    }

    /**
     * Status of each course of a path for a user, in path order.
     *
     * @param int $pathid Path id.
     * @param int $userid User id.
     * @return array[] Each with course (record), status (completed, inprogress, available, unavailable),
     *                 progress (int|null) and directenrol (bool).
     */
    public static function get_progress(int $pathid, int $userid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $courseids = self::get_course_ids($pathid);
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_select('course', "id $insql", $params);
        $candidates = (new candidate_finder())->get_candidates($userid, $courseids);
        $helper = new enrolment_helper();

        $result = [];
        foreach ($courseids as $courseid) {
            if (!isset($courses[$courseid])) {
                continue;
            }
            $course = $courses[$courseid];
            if (!\core_course_category::can_view_course_info($course, $userid)) {
                continue;
            }
            $context = \context_course::instance($courseid);
            $enrolled = is_enrolled($context, $userid, '', true);
            $status = 'unavailable';
            $progress = null;
            if ($enrolled) {
                $completion = new \completion_info($course);
                if ($completion->is_enabled() && $completion->is_course_complete($userid)) {
                    $status = 'completed';
                    $progress = 100;
                } else {
                    $status = 'inprogress';
                    $percentage = \core_completion\progress::get_course_progress_percentage($course, $userid);
                    $progress = $percentage === null ? null : (int) floor($percentage);
                }
            } else if (isset($candidates[$courseid])) {
                $status = 'available';
            }
            $result[] = [
                'course' => $course,
                'status' => $status,
                'progress' => $progress,
                'directenrol' => $status === 'available' && $helper->get_direct_instance($courseid, $userid) !== null,
            ];
        }
        return $result;
    }
}
