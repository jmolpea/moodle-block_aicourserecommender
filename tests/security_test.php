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

use block_aicourserecommender\external\delete_my_data;
use block_aicourserecommender\external\enrol_course;
use block_aicourserecommender\external\generate_path_description;
use block_aicourserecommender\external\generate_path_image;
use block_aicourserecommender\external\log_click;
use block_aicourserecommender\external\submit_feedback;
use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\local\path_manager;
use block_aicourserecommender\local\profile_collector;
use block_aicourserecommender\local\ranking_manager;
use block_aicourserecommender\local\response_parser;
use block_aicourserecommender\table\items_table;
use block_aicourserecommender\task\cleanup;
use block_aicourserecommender\tests\fake_ai_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');

/**
 * Security, permission and privacy hardening tests.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\ranking_manager
 * @covers     \block_aicourserecommender\local\profile_collector
 * @covers     \block_aicourserecommender\external\helper
 * @covers     \block_aicourserecommender\task\cleanup
 */
final class security_test extends \advanced_testcase {
    /** @var fake_ai_client Fake AI. */
    protected fake_ai_client $ai;

    /** @var \block_aicourserecommender_generator Plugin generator. */
    protected $plugingenerator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ai = new fake_ai_client();
        \core\di::set(ai_client::class, $this->ai);
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
    }

    /**
     * Learner with answers and a stored ranking of the given courses.
     *
     * @param int $count Candidate courses to create.
     * @return array [user, courses]
     */
    protected function learner_with_ranking(int $count = 2): array {
        $courses = [];
        for ($i = 0; $i < $count; $i++) {
            $courses[] = $this->plugingenerator->create_candidate_course();
        }
        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->create_answers((int) $user->id);
        $this->setUser($user);
        (new ranking_manager())->get_recommendations((int) $user->id);
        return [$user, $courses];
    }

    public function test_ai_policy_is_enforced_on_the_server(): void {
        global $DB;
        $this->plugingenerator->create_candidate_course();
        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->create_answers((int) $user->id);
        $this->setUser($user);
        $this->ai->policyaccepted = false;

        $result = (new ranking_manager())->get_recommendations((int) $user->id);
        $this->assertSame(ranking_manager::STATUS_AIPOLICY, $result['status']);
        $this->assertSame([], $this->ai->prompts);
        $this->assertSame(0, $DB->count_records(ai_client::LOG_TABLE));
    }

    public function test_path_ai_functions_require_policy(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $this->ai->policyaccepted = false;
        try {
            generate_path_description::execute('Path', [$course->id]);
            $this->fail('Policy should be required');
        } catch (\moodle_exception $e) {
            $this->assertSame('erroraipolicy', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        generate_path_image::execute('Path', '');
    }

    public function test_items_must_have_been_recommended(): void {
        [$user, $courses] = $this->learner_with_ranking();
        $other = $this->plugingenerator->create_candidate_course(['visible' => 0]);

        // Recommended course: accepted.
        $this->assertTrue(submit_feedback::execute('course', (int) $courses[0]->id, 1, '')['success']);
        $this->assertTrue(log_click::execute('course', (int) $courses[0]->id, 999)['success']);

        foreach (
            [
            fn() => submit_feedback::execute('course', (int) $other->id, -1, 'x'),
            fn() => log_click::execute('course', (int) $other->id, 1),
            fn() => enrol_course::execute((int) $other->id),
            fn() => log_click::execute('path', 12345, 1),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Items that were not recommended must be rejected');
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('not recommended', $e->debuginfo ?? $e->getMessage());
            }
        }
        $this->assertNotEmpty($user);
    }

    public function test_click_position_is_bounded(): void {
        global $DB;
        [$user, $courses] = $this->learner_with_ranking();
        log_click::execute('course', (int) $courses[0]->id, 100000);
        $position = $DB->get_field(
            'block_aicourserecommender_activity',
            'position',
            ['userid' => $user->id, 'action' => 'click']
        );
        $this->assertEquals(24, $position);
    }

    public function test_path_description_ignores_hidden_courses_for_managers_without_access(): void {
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0, 'fullname' => 'Secret project']);
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Public course']);
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/aicourserecommender:managepaths', CAP_ALLOW, $roleid, \context_system::instance());
        role_assign($roleid, $manager->id, \context_system::instance());
        $this->setUser($manager);

        $result = generate_path_description::execute('Path', [$hidden->id]);
        $this->assertFalse($result['success']);
        $this->assertSame([], $this->ai->prompts);

        generate_path_description::execute('Path', [$hidden->id, $visible->id]);
        $this->assertStringContainsString('Public course', end($this->ai->prompts));
        $this->assertStringNotContainsString('Secret project', end($this->ai->prompts));
    }

    public function test_hidden_path_courses_never_reach_the_prompt(): void {
        $open = $this->plugingenerator->create_candidate_course(['fullname' => 'Open course']);
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0, 'fullname' => 'Hidden secret']);
        $this->setAdminUser();
        $this->plugingenerator->create_path(['name' => 'Mixed', 'courses' => [$open->id, $hidden->id]]);
        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->create_answers((int) $user->id);
        $this->setUser($user);

        $result = (new ranking_manager())->get_recommendations((int) $user->id);
        $this->assertStringNotContainsString('Hidden secret', $this->ai->prompts[0]);
        $this->assertSame(1, $result['paths'][0]['coursecount']);
    }

    public function test_personal_identifiers_are_redacted(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Marisol', 'lastname' => 'Quintanilla',
            'username' => 'mquintanilla', 'idnumber' => 'EMP-7781']);
        $text = 'I am Marisol Quintanilla (mquintanilla), mail me at marisol.q@example.org or +34 612 345 678, ' .
            'see https://evil.example/x and www.example.com. I worked 2020-2024 as nurse, 3 hours a week.';
        $clean = profile_collector::redact($text, $user);
        foreach (
            ['Marisol', 'Quintanilla', 'mquintanilla', 'marisol.q@example.org', '612 345 678', 'evil.example',
                'www.example.com'] as $secret
        ) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertStringContainsString('2020-2024', $clean);
        $this->assertStringContainsString('3 hours a week', $clean);
        $this->assertStringContainsString('[email]', $clean);

        // Answers are redacted in the prompt.
        $this->plugingenerator->create_candidate_course();
        $this->plugingenerator->create_answers((int) $user->id, [1 => 'Marisol, nurse, call 612345678']);
        $this->setUser($user);
        (new ranking_manager())->get_recommendations((int) $user->id);
        $this->assertStringNotContainsString('Marisol', $this->ai->prompts[0]);
        $this->assertStringNotContainsString('612345678', $this->ai->prompts[0]);
    }

    public function test_links_are_removed_from_ai_reasons(): void {
        $reason = response_parser::clean_reason('Great fit! Pay at https://phishing.example/pay or write to a@b.com now');
        $this->assertStringNotContainsString('phishing', $reason);
        $this->assertStringNotContainsString('a@b.com', $reason);
        $this->assertStringContainsString('Great fit', $reason);
    }

    public function test_delete_my_data(): void {
        global $DB;
        [$user, $courses] = $this->learner_with_ranking();
        submit_feedback::execute('course', (int) $courses[0]->id, -1, 'No');
        $this->assertTrue(delete_my_data::execute()['success']);
        foreach (['answers', 'answerhist', 'ranking', 'feedback', 'activity'] as $table) {
            $this->assertFalse($DB->record_exists('block_aicourserecommender_' . $table, ['userid' => $user->id]), $table);
        }
        $this->assertTrue(answers_manager::needs_consent((int) $user->id));
    }

    public function test_retention_and_history_cap(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        for ($i = 0; $i < answers_manager::MAX_HISTORY + 5; $i++) {
            answers_manager::save_answers((int) $user->id, [1 => 'Answer ' . $i]);
        }
        $this->assertEquals(answers_manager::MAX_HISTORY, $DB->count_records(
            answers_manager::HISTORY_TABLE,
            ['userid' => $user->id]
        ));

        $old = time() - 400 * DAYSECS;
        $DB->insert_record('block_aicourserecommender_activity', ['userid' => $user->id, 'action' => 'shown',
            'itemtype' => 'course', 'itemid' => 1, 'position' => 1, 'timecreated' => $old]);
        $DB->insert_record('block_aicourserecommender_ailog', ['userid' => $user->id, 'calltype' => 'ranking',
            'success' => 1, 'timecreated' => $old]);
        $DB->insert_record('block_aicourserecommender_activity', ['userid' => $user->id, 'action' => 'shown',
            'itemtype' => 'course', 'itemid' => 1, 'position' => 1, 'timecreated' => time()]);
        $this->assertSame(2, cleanup::run(365));
        $this->assertEquals(1, $DB->count_records('block_aicourserecommender_activity'));
        $this->assertSame(0, cleanup::run(0));
    }

    public function test_spreadsheet_formulas_are_neutralised(): void {
        $this->assertSame("'=HYPERLINK(\"x\")", items_table::spreadsheet_safe('=HYPERLINK("x")'));
        $this->assertSame("'+1", items_table::spreadsheet_safe('+1'));
        $this->assertSame('Normal name', items_table::spreadsheet_safe('Normal name'));
    }

    public function test_path_images_are_raster_only(): void {
        $types = \block_aicourserecommender\form\path_form::image_options()['accepted_types'];
        $this->assertNotContains('.svg', $types);
        $this->assertNotContains('web_image', $types);
    }

    public function test_guest_block_content_is_empty(): void {
        global $PAGE;
        $this->setGuestUser();
        $PAGE->set_url('/');
        $block = block_instance('aicourserecommender');
        $block->page = $PAGE;
        $this->assertSame('', $block->get_content()->text);
    }

    public function test_block_formats_exclude_courses(): void {
        $formats = block_instance('aicourserecommender')->applicable_formats();
        $this->assertTrue($formats['site-index']);
        $this->assertTrue($formats['my']);
        $this->assertFalse($formats['all']);
        $this->assertArrayNotHasKey('course-view', $formats);
        $this->assertFalse(block_instance('aicourserecommender')->instance_allow_multiple());
    }

    public function test_external_services_are_ajax_with_capabilities(): void {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/blocks/aicourserecommender/db/services.php');
        foreach ($functions as $name => $function) {
            $this->assertTrue($function['ajax'], $name);
            $this->assertNotEmpty($function['capabilities'], $name);
            $this->assertTrue(class_exists($function['classname']), $name);
        }
        $this->assertSame(
            'block/aicourserecommender:managepaths',
            $functions['block_aicourserecommender_generate_path_image']['capabilities']
        );
    }
}
