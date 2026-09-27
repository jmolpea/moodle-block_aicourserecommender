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

use block_aicourserecommender\event\course_enrolled_from_recommendation;
use block_aicourserecommender\event\path_enrolled;

/**
 * Direct enrolment from recommendations and learning paths. Only self enrolment without an enrolment key is direct.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolment_helper {
    /**
     * Self enrolment instance without key that the current user can use right now in a course.
     *
     * Uses enrol_self_plugin::can_self_enrol(), which works on the current user. For any other user null is returned.
     *
     * @param int $courseid Course id.
     * @param int $userid User id, must be the current user.
     * @return \stdClass|null
     */
    public function get_direct_instance(int $courseid, int $userid): ?\stdClass {
        global $CFG, $USER;
        if ((int) $USER->id !== $userid || !enrol_is_enabled('self') || !in_array('self', config::get_enrol_methods(), true)) {
            return null;
        }
        require_once($CFG->libdir . '/enrollib.php');
        $plugin = enrol_get_plugin('self');
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if ($instance->enrol !== 'self' || (string) $instance->password !== '') {
                continue;
            }
            if ($plugin->can_self_enrol($instance) === true) {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Enrols the current user in a recommended course.
     *
     * @param int $courseid Course id.
     * @param string $source "recommendation" or "path".
     * @param int $pathid Path id when the enrolment comes from a path.
     * @return bool True when the user was enrolled.
     */
    public function enrol_course(int $courseid, string $source = 'recommendation', int $pathid = 0): bool {
        global $USER;
        $userid = (int) $USER->id;
        $candidates = (new candidate_finder())->get_candidates($userid, [$courseid]);
        if (!isset($candidates[$courseid])) {
            return false;
        }
        $instance = $this->get_direct_instance($courseid, $userid);
        if (!$instance) {
            return false;
        }
        enrol_get_plugin('self')->enrol_self($instance);
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $userid)) {
            return false;
        }
        activity_logger::log($userid, activity_logger::ACTION_ENROL, 'course', $courseid);
        course_enrolled_from_recommendation::create([
            'objectid' => $courseid,
            'courseid' => $courseid,
            'context' => $context,
            'relateduserid' => $userid,
            'other' => ['source' => $source, 'pathid' => $pathid],
        ])->trigger();
        return true;
    }

    /**
     * Enrols the current user in every course of a path that has direct enrolment available.
     *
     * @param int $pathid Path id.
     * @return array[] One entry per course of the path: courseid, name and status (enrolled, already, unavailable).
     */
    public function enrol_path(int $pathid): array {
        global $USER;
        $path = path_manager::get_path($pathid, MUST_EXIST);
        $results = [];
        $count = 0;
        foreach (path_manager::get_progress($pathid, (int) $USER->id) as $item) {
            $course = $item['course'];
            $status = 'unavailable';
            if ($item['status'] === 'completed' || $item['status'] === 'inprogress') {
                $status = 'already';
            } else if ($item['directenrol'] && $this->enrol_course((int) $course->id, 'path', $pathid)) {
                $status = 'enrolled';
                $count++;
            }
            $results[] = [
                'courseid' => (int) $course->id,
                'name' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
                'status' => $status,
            ];
        }
        if ($count > 0) {
            activity_logger::log((int) $USER->id, activity_logger::ACTION_ENROL, 'path', $pathid);
        }
        path_enrolled::create([
            'objectid' => $path->id,
            'context' => \context_system::instance(),
            'relateduserid' => (int) $USER->id,
            'other' => ['count' => $count],
        ])->trigger();
        return $results;
    }
}
