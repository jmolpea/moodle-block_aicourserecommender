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

namespace block_aicourserecommender\event;

/**
 * Event course_enrolled_from_recommendation.
 *
 * Triggered when a learner enrols in a course from a recommendation card or a learning path. Other: source, pathid.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_enrolled_from_recommendation extends \core\event\base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_course_enrolled_from_recommendation', 'block_aicourserecommender');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' enrolled in the course with id '$this->courseid' from a " .
            "{$this->other['source']}.";
    }

    /**
     * Related URL.
     *
     * @return \moodle_url|null
     */
    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }

    /**
     * Validates the event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['source'])) {
            throw new \coding_exception('The source must be set in other.');
        }
    }

    /**
     * Mapping of the object id for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'course', 'restore' => 'course'];
    }

    /**
     * Mapping of the other data for backup and restore.
     *
     * @return array|false
     */
    public static function get_other_mapping() {
        return false;
    }
}
