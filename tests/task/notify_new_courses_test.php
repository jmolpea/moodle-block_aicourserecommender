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

namespace block_aicourserecommender\task;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\ranking_manager;
use block_aicourserecommender\tests\fake_ai_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');

/**
 * Tests of the daily task that ranks new courses and notifies learners.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\task\notify_new_courses
 */
final class notify_new_courses_test extends \advanced_testcase {
    public function test_new_course_is_ranked_and_notified(): void {
        global $DB;
        $this->resetAfterTest();
        $ai = new fake_ai_client();
        $ai->policyaccepted = true;
        \core\di::set(ai_client::class, $ai);
        $generator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');

        $user = $this->getDataGenerator()->create_user(['lastaccess' => time()]);
        $inactive = $this->getDataGenerator()->create_user(['lastaccess' => time() - 200 * DAYSECS]);
        \core_ai\manager::user_policy_accepted((int) $user->id, \context_system::instance()->id);
        \core_ai\manager::user_policy_accepted((int) $inactive->id, \context_system::instance()->id);
        $generator->create_candidate_course(['fullname' => 'Old course']);
        foreach ([$user, $inactive] as $learner) {
            $generator->create_answers((int) $learner->id);
            $this->setUser($learner);
            (new ranking_manager())->get_recommendations((int) $learner->id);
        }
        $this->setAdminUser();
        $DB->set_field(ranking_manager::TABLE, 'timemodified', time() - DAYSECS, []);

        $new = $generator->create_candidate_course(['fullname' => 'New course']);
        $ai->responses[] = json_encode(['courses' => [['id' => (int) $new->id, 'score' => 85, 'reason' => 'It fits.']]]);

        $sink = $this->redirectMessages();
        $task = new notify_new_courses();
        $this->expectOutputRegex('/Learners processed: 1/');
        $task->execute();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($user->id, $messages[0]->useridto);
        $this->assertStringContainsString('New course', $messages[0]->subject);

        $ranked = json_decode(ranking_manager::get_stored((int) $user->id)->courses, true);
        $this->assertContains((int) $new->id, array_column($ranked, 'id'));
        $this->assertSame(1, $DB->count_records(ai_client::LOG_TABLE, ['calltype' => ai_client::CALL_INCREMENTAL]));
        $this->assertSame(1, ai_client::count_user_calls_today((int) $user->id), 'The task call does not count.');

        // Checked learners are not processed again the same day.
        $this->assertSame([], $task->get_users());
    }

    public function test_below_threshold_or_disabled_no_notification(): void {
        global $DB;
        $this->resetAfterTest();
        $ai = new fake_ai_client();
        \core\di::set(ai_client::class, $ai);
        $generator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
        $user = $this->getDataGenerator()->create_user(['lastaccess' => time()]);
        \core_ai\manager::user_policy_accepted((int) $user->id, \context_system::instance()->id);
        $generator->create_candidate_course();
        $generator->create_answers((int) $user->id);
        $this->setUser($user);
        (new ranking_manager())->get_recommendations((int) $user->id);
        $this->setAdminUser();
        $DB->set_field(ranking_manager::TABLE, 'timemodified', time() - DAYSECS, []);

        $new = $generator->create_candidate_course();
        $ai->responses[] = json_encode(['courses' => [['id' => (int) $new->id, 'score' => 50, 'reason' => 'Meh']]]);
        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/Learners processed/');
        (new notify_new_courses())->execute();
        $this->assertCount(0, $sink->get_messages());
    }
}
