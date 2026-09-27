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
 * Event path_viewed.
 *
 * Triggered when a learner views a learning path landing page.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_viewed extends \core\event\base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'block_aicourserecommender_paths';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_path_viewed', 'block_aicourserecommender');
    }

    /**
     * Non-localised description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' viewed the learning path with id '$this->objectid'.";
    }

    /**
     * Related URL.
     *
     * @return \moodle_url|null
     */
    public function get_url() {
        return new \moodle_url('/blocks/aicourserecommender/path.php', ['id' => $this->objectid]);
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
    }

    /**
     * Mapping of the object id for backup and restore.
     *
     * @return array|false
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
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
