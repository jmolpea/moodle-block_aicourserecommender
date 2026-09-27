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

namespace block_aicourserecommender;

use block_aicourserecommender\privacy\provider;
use block_aicourserecommender\task\refresh_summaries;
use block_aicourserecommender\task\update_course_summary;

/**
 * Event observers. They never call the AI directly: summaries are refreshed by ad hoc tasks.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A course was created, updated or restored: queue its summary refresh.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    public static function course_changed(\core\event\base $event): void {
        self::queue_course((int) ($event->courseid ?: $event->objectid));
    }

    /**
     * A course was deleted: remove its summary and its place in learning paths.
     *
     * @param \core\event\course_deleted $event Event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $courseid = (int) $event->objectid;
        $DB->delete_records('block_aicourserecommender_coursesum', ['courseid' => $courseid]);
        $DB->delete_records('block_aicourserecommender_pathcourses', ['courseid' => $courseid]);
    }

    /**
     * A tag was added to or removed from an item: refresh the summary when the item is a course.
     *
     * @param \core\event\base $event tag_added or tag_removed event.
     * @return void
     */
    public static function tag_changed(\core\event\base $event): void {
        $other = $event->other;
        if (($other['itemtype'] ?? '') === 'course' && ($other['component'] ?? 'core') === 'core') {
            self::queue_course((int) $other['itemid']);
        }
    }

    /**
     * A custom field changed: summaries whose metadata changed are refreshed by an ad hoc task.
     *
     * @param \core\event\base $event Custom field event.
     * @return void
     */
    public static function customfield_changed(\core\event\base $event): void {
        if ($event->component !== 'core_customfield') {
            return;
        }
        \core\task\manager::queue_adhoc_task(new refresh_summaries(), true);
    }

    /**
     * A user was deleted: remove every record of the plugin about them.
     *
     * @param \core\event\user_deleted $event Event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        provider::delete_user_data((int) $event->objectid);
    }

    /**
     * Queues the summary refresh of a course.
     *
     * @param int $courseid Course id.
     * @return void
     */
    protected static function queue_course(int $courseid): void {
        if ($courseid <= 0 || $courseid == SITEID) {
            return;
        }
        $task = new update_course_summary();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
