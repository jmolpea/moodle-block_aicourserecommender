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

namespace block_aicourserecommender\privacy;

use block_aicourserecommender\local\activity_logger;
use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\local\feedback_manager;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests of the privacy provider.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Creates data for a user in every table.
     *
     * @param \stdClass $user User.
     * @return void
     */
    protected function create_user_data(\stdClass $user): void {
        global $DB;
        $this->setUser($user);
        answers_manager::save_consent((int) $user->id);
        answers_manager::save_answers((int) $user->id, [1 => 'Nurse', 3 => 'Leadership']);
        feedback_manager::save((int) $user->id, 'course', 5, -1, 'Too long');
        activity_logger::log((int) $user->id, activity_logger::ACTION_CLICK, 'course', 5, 1);
        $DB->insert_record('block_aicourserecommender_ranking', ['userid' => $user->id, 'hash' => sha1('a'),
            'userhash' => sha1('b'), 'lang' => 'en', 'courses' => '[{"id":5,"score":80,"reason":"R"}]', 'paths' => '[]',
            'timecreated' => time(), 'timemodified' => time(), 'timeexpires' => time() + 100]);
        $DB->insert_record('block_aicourserecommender_ailog', ['userid' => $user->id, 'calltype' => 'ranking',
            'success' => 1, 'duration' => 10, 'tokens' => 5, 'timecreated' => time()]);
    }

    public function test_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('block_aicourserecommender'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());
        foreach (provider::USER_TABLES as $table) {
            $this->assertContains($table, $names);
        }
        $this->assertContains('core_ai', $names);
    }

    public function test_contexts_users_export_and_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->create_user_data($u1);
        $this->create_user_data($u2);
        $context = \context_user::instance($u1->id);

        $contextlist = provider::get_contexts_for_userid((int) $u1->id);
        $this->assertContains((int) $context->id, array_map('intval', $contextlist->get_contextids()));

        $userlist = new userlist($context, 'block_aicourserecommender');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $u1->id], array_map('intval', $userlist->get_userids()));

        $this->export_context_data_for_user((int) $u1->id, $context, 'block_aicourserecommender');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $pluginname = get_string('pluginname', 'block_aicourserecommender');
        $answers = $writer->get_data([$pluginname, get_string('privacy:answers', 'block_aicourserecommender')]);
        $this->assertSame('Nurse', $answers->answers[1]);
        $ratings = $writer->get_data([$pluginname, get_string('privacy:feedback', 'block_aicourserecommender')]);
        $this->assertSame('Too long', $ratings->ratings[0]['reason']);

        provider::delete_data_for_user(new approved_contextlist($u1, 'block_aicourserecommender', [$context->id]));
        foreach (provider::USER_TABLES as $table) {
            $this->assertFalse($DB->record_exists($table, ['userid' => $u1->id]), $table);
            $this->assertTrue($DB->record_exists($table, ['userid' => $u2->id]), $table);
        }

        $context2 = \context_user::instance($u2->id);
        provider::delete_data_for_users(new approved_userlist($context2, 'block_aicourserecommender', [$u2->id]));
        foreach (provider::USER_TABLES as $table) {
            $this->assertFalse($DB->record_exists($table, ['userid' => $u2->id]), $table);
        }
    }

    public function test_delete_all_in_context_and_paths(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_user_data($user);
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($user);
        $path = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender')
            ->create_path(['courses' => [$course->id]]);

        $system = \context_system::instance();
        $contextids = array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        $this->assertContains((int) $system->id, $contextids);

        $userlist = new userlist($system, 'block_aicourserecommender');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $user->id, array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));
        $this->assertFalse($DB->record_exists('block_aicourserecommender_answers', ['userid' => $user->id]));

        provider::delete_data_for_user(new approved_contextlist($user, 'block_aicourserecommender', [$system->id]));
        $this->assertEquals(0, $DB->get_field('block_aicourserecommender_paths', 'usermodified', ['id' => $path->id]));
    }

    public function test_user_deleted_observer(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_user_data($user);
        $this->setAdminUser();
        delete_user($user);
        foreach (provider::USER_TABLES as $table) {
            $this->assertFalse($DB->record_exists($table, ['userid' => $user->id]), $table);
        }
    }
}
