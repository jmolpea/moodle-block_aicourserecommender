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

use block_aicourserecommender\admin\setting_questions;

/**
 * Tests of the questions, answers, consent and profile data.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\questions
 * @covers     \block_aicourserecommender\local\answers_manager
 * @covers     \block_aicourserecommender\local\profile_collector
 * @covers     \block_aicourserecommender\admin\setting_questions
 */
final class questions_test extends \advanced_testcase {
    public function test_default_questions(): void {
        $this->resetAfterTest();
        $active = questions::get_active();
        $this->assertCount(4, $active);
        $this->assertSame(get_string('question1', 'block_aicourserecommender'), $active[0]['text']);
        $this->assertNotEmpty($active[0]['help']);
    }

    public function test_custom_order_text_and_multilang(): void {
        $this->resetAfterTest();
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \filter_manager::reset_caches();
        $config = questions::get_default_config();
        $config[5] = ['text' => '<span lang="en" class="multilang">Fifth</span><span lang="es" class="multilang">Quinta</span>',
            'help' => '', 'enabled' => 1, 'sortorder' => 1];
        $config[1]['sortorder'] = 6;
        $config[2]['enabled'] = 0;
        set_config('questions', json_encode($config), 'block_aicourserecommender');

        $active = questions::get_active();
        $this->assertSame([5, 3, 4, 1], array_column($active, 'slot'));
        $this->assertSame('Fifth', $active[0]['text']);
    }

    public function test_validation_and_admin_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_questions();
        $data = [];
        for ($slot = 1; $slot <= 6; $slot++) {
            $data[$slot] = ['text' => '', 'help' => '', 'enabled' => 0, 'sortorder' => $slot];
        }
        $this->assertSame(get_string('errornoactivequestion', 'block_aicourserecommender'), $setting->write_setting($data));

        $data[6]['enabled'] = 1;
        $this->assertSame(get_string('errorquestionnotext', 'block_aicourserecommender', 6), $setting->write_setting($data));

        $data[6]['text'] = 'Custom question';
        $this->assertSame('', $setting->write_setting($data));
        $this->assertSame([6], array_column(questions::get_active(), 'slot'));
        $this->assertStringContainsString('Custom question', $setting->output_html($setting->get_setting()));
    }

    public function test_answers_history_and_consent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(answers_manager::needs_consent((int) $user->id));
        set_config('requireconsent', 0, 'block_aicourserecommender');
        $this->assertFalse(answers_manager::needs_consent((int) $user->id));
        set_config('requireconsent', 1, 'block_aicourserecommender');
        answers_manager::save_consent((int) $user->id);
        $this->assertFalse(answers_manager::needs_consent((int) $user->id));
        $this->assertGreaterThan(0, $DB->get_field(answers_manager::TABLE, 'timeconsent', ['userid' => $user->id]));

        $sink = $this->redirectEvents();
        answers_manager::save_answers((int) $user->id, [1 => 'Engineer <script>x</script>']);
        answers_manager::save_answers((int) $user->id, [1 => 'Manager']);
        $this->assertSame('Manager', answers_manager::get_answers((int) $user->id)[1]);
        $this->assertEquals(2, $DB->count_records(answers_manager::HISTORY_TABLE, ['userid' => $user->id]));
        $first = $DB->get_records(answers_manager::HISTORY_TABLE, ['userid' => $user->id], 'id');
        $this->assertStringNotContainsString('<script>', reset($first)->answers);
        $this->assertInstanceOf(\block_aicourserecommender\event\interests_updated::class, $sink->get_events()[0]);

        $this->expectException(\moodle_exception::class);
        answers_manager::save_answers((int) $user->id, [1 => '   ']);
    }

    public function test_profile_collector(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'sector',
            'name' => 'Sector']);
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'secret',
            'name' => 'Secret']);
        $user = $this->getDataGenerator()->create_user([
            'city' => 'Lisboa', 'country' => 'PT', 'institution' => 'Uni', 'department' => 'HR',
            'description' => '<p>I love <b>data</b></p>' . str_repeat('x', 3000),
            'email' => 'private@example.com', 'phone1' => '600000000', 'idnumber' => 'ID123',
            'profile_field_sector' => 'Health', 'profile_field_secret' => 'Hidden value',
        ]);
        \core_tag_tag::set_item_tags('core', 'user', $user->id, \context_user::instance($user->id), ['Robotics']);
        set_config('customprofilefields', 'sector', 'block_aicourserecommender');

        $data = profile_collector::collect((int) $user->id);
        $this->assertSame('Lisboa', $data['city']);
        $this->assertSame('Portugal', $data['country']);
        $this->assertSame('Robotics', $data['interests']);
        $this->assertSame('Health', $data['Sector']);
        $this->assertArrayHasKey('language', $data);
        $this->assertStringStartsWith('I love data', $data['profile description']);
        $this->assertLessThanOrEqual(profile_collector::MAX_DESCRIPTION_LENGTH, \core_text::strlen($data['profile description']));
        $all = implode(' ', $data);
        foreach (['private@example.com', '600000000', 'ID123', 'Hidden value', $user->username, $user->firstname] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }

        set_config('profilefields', 'city', 'block_aicourserecommender');
        $data = profile_collector::collect((int) $user->id);
        $this->assertArrayNotHasKey('institution', $data);
    }
}
