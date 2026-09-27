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

use block_aicourserecommender\local\report;

/**
 * Tests of the events and the report totals.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\event\recommendations_viewed
 * @covers     \block_aicourserecommender\event\recommendation_clicked
 * @covers     \block_aicourserecommender\event\recommendation_rated
 * @covers     \block_aicourserecommender\event\interests_updated
 * @covers     \block_aicourserecommender\event\path_viewed
 * @covers     \block_aicourserecommender\event\path_enrolled
 * @covers     \block_aicourserecommender\event\course_enrolled_from_recommendation
 * @covers     \block_aicourserecommender\local\report
 */
final class events_test extends \advanced_testcase {
    public function test_events_are_valid_and_logged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);
        $system = \context_system::instance();
        $events = [
            recommendations_viewed::create(['context' => $system, 'relateduserid' => $user->id,
                'other' => ['courseids' => [1], 'pathids' => []]]),
            recommendation_clicked::create(['context' => $system, 'relateduserid' => $user->id,
                'other' => ['itemtype' => 'course', 'itemid' => $course->id, 'position' => 2]]),
            recommendation_rated::create(['objectid' => 1, 'context' => $system, 'relateduserid' => $user->id,
                'other' => ['itemtype' => 'course', 'itemid' => $course->id, 'rating' => -1]]),
            interests_updated::create(['objectid' => 1, 'context' => $system, 'relateduserid' => $user->id]),
            path_viewed::create(['objectid' => 1, 'context' => $system, 'relateduserid' => $user->id]),
            path_enrolled::create(['objectid' => 1, 'context' => $system, 'relateduserid' => $user->id,
                'other' => ['count' => 2]]),
            course_enrolled_from_recommendation::create(['objectid' => $course->id, 'courseid' => $course->id,
                'context' => \context_course::instance($course->id), 'relateduserid' => $user->id,
                'other' => ['source' => 'recommendation', 'pathid' => 0]]),
        ];
        foreach ($events as $event) {
            $event->trigger();
            $this->assertNotEmpty($event->get_name());
            $this->assertNotEmpty($event->get_description());
            $this->assertContains($event->crud, ['c', 'r', 'u', 'd']);
        }
        $this->assertEquals(7, $DB->count_records('logstore_standard_log', ['component' => 'block_aicourserecommender']));
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $events[6]->edulevel);
    }

    public function test_missing_data_throws(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        recommendation_clicked::create(['context' => \context_system::instance(), 'relateduserid' => 2, 'other' => []]);
    }

    public function test_report_totals(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        foreach (['shown', 'shown', 'shown', 'shown', 'click', 'enrol'] as $action) {
            $DB->insert_record('block_aicourserecommender_activity', ['userid' => 2, 'action' => $action,
                'itemtype' => 'course', 'itemid' => 10, 'position' => 1, 'timecreated' => $now]);
        }
        $DB->insert_record('block_aicourserecommender_ailog', ['userid' => 2, 'calltype' => 'ranking', 'success' => 0,
            'timecreated' => $now]);
        $totals = report::get_raw_totals($now - 10, $now + 10);
        $this->assertSame(4, $totals['shown']);
        $this->assertSame(1, $totals['clicks']);
        $this->assertSame(25.0, $totals['ctr']);
        $this->assertSame(1, $totals['enrolments']);
        $this->assertSame(1, $totals['aierrors']);
        $this->assertCount(9, report::get_totals($now - 10, $now + 10));
    }
}
