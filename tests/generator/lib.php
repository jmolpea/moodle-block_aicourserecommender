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

use block_aicourserecommender\local\path_manager;

/**
 * Data generator of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_aicourserecommender_generator extends testing_block_generator {
    /**
     * Creates a course with an open self enrolment instance (no key, new enrolments allowed).
     *
     * @param array $record Course fields.
     * @param array $enrol Self enrolment instance fields to override.
     * @return stdClass Course.
     */
    public function create_candidate_course(array $record = [], array $enrol = []): stdClass {
        global $DB;
        $course = $this->datagenerator->create_course($record);
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        if (!$instance) {
            $plugin = enrol_get_plugin('self');
            $instanceid = $plugin->add_instance($course, $plugin->get_instance_defaults());
            $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        }
        $instance->status = ENROL_INSTANCE_ENABLED;
        $instance->customint6 = 1;
        $instance->customint3 = 0;
        $instance->customint5 = 0;
        $instance->password = '';
        $instance->enrolstartdate = 0;
        $instance->enrolenddate = 0;
        foreach ($enrol as $field => $value) {
            $instance->$field = $value;
        }
        $DB->update_record('enrol', $instance);
        return $course;
    }

    /**
     * Creates a learning path.
     *
     * @param array|stdClass $record Fields: name, description, visible, courses (ids or comma separated shortnames).
     * @return stdClass Path record.
     */
    public function create_path($record = []): stdClass {
        global $DB;
        $record = (array) $record;
        $courses = $record['courses'] ?? [];
        if (is_string($courses)) {
            $ids = [];
            foreach (array_filter(array_map('trim', explode(',', $courses))) as $shortname) {
                $ids[] = (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
            }
            $courses = $ids;
        }
        $data = (object) [
            'name' => $record['name'] ?? 'Learning path',
            'description' => $record['description'] ?? '<p>A learning path.</p>',
            'descriptionformat' => FORMAT_HTML,
            'visible' => $record['visible'] ?? 1,
        ];
        $id = path_manager::save_path($data, $courses);
        return path_manager::get_path($id, MUST_EXIST);
    }

    /**
     * Saves questionnaire answers for a user, with consent.
     *
     * @param int $userid User id.
     * @param array $answers Answers keyed by slot.
     * @return void
     */
    public function create_answers(int $userid, array $answers = []): void {
        global $DB;
        $answers = $answers ?: [1 => 'Teacher', 2 => 'Lead projects', 3 => 'Project management, basic', 4 => '3 hours'];
        $now = time();
        $record = $DB->get_record('block_aicourserecommender_answers', ['userid' => $userid]);
        $data = (object) [
            'userid' => $userid,
            'answers' => json_encode($answers),
            'consent' => 1,
            'timeconsent' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if ($record) {
            $data->id = $record->id;
            $DB->update_record('block_aicourserecommender_answers', $data);
        } else {
            $DB->insert_record('block_aicourserecommender_answers', $data);
        }
    }
}
