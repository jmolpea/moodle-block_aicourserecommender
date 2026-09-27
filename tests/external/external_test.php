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

namespace block_aicourserecommender\external;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\tests\fake_ai_client;
use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests of the external functions.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\external\get_recommendations
 * @covers     \block_aicourserecommender\external\get_more
 * @covers     \block_aicourserecommender\external\save_answers
 * @covers     \block_aicourserecommender\external\save_consent
 * @covers     \block_aicourserecommender\external\submit_feedback
 * @covers     \block_aicourserecommender\external\log_click
 * @covers     \block_aicourserecommender\external\enrol_course
 * @covers     \block_aicourserecommender\external\enrol_path
 * @covers     \block_aicourserecommender\external\generate_path_description
 * @covers     \block_aicourserecommender\external\generate_path_image
 */
final class external_test extends \externallib_advanced_testcase {
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

    public function test_full_learner_flow(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        for ($i = 1; $i <= 6; $i++) {
            $this->plugingenerator->create_candidate_course(['fullname' => 'Course ' . $i]);
        }

        // Consent is required first.
        try {
            save_answers::execute([['slot' => 1, 'answer' => 'Teacher']]);
            $this->fail('Consent should be required');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorconsentrequired', $e->errorcode);
        }
        $result = external_api::clean_returnvalue(save_consent::execute_returns(), save_consent::execute(true));
        $this->assertTrue($result['success']);

        $result = external_api::clean_returnvalue(get_recommendations::execute_returns(), get_recommendations::execute());
        $this->assertSame('questionnaire', $result['status']);

        $long = str_repeat('a', 1500);
        $result = external_api::clean_returnvalue(
            save_answers::execute_returns(),
            save_answers::execute([['slot' => 1, 'answer' => 'Teacher'], ['slot' => 3, 'answer' => $long],
            ['slot' => 9, 'answer' => 'Ignored']])
        );
        $saved = array_column($result['answers'], 'answer', 'slot');
        $this->assertSame('Teacher', $saved[1]);
        $this->assertSame(1000, \core_text::strlen($saved[3]));
        $this->assertArrayNotHasKey(9, $saved);
        $this->assertEquals(1, $DB->count_records('block_aicourserecommender_answerhist', ['userid' => $user->id]));

        $result = external_api::clean_returnvalue(get_recommendations::execute_returns(), get_recommendations::execute());
        $this->assertSame('ok', $result['status']);
        $this->assertCount(4, $result['courses']);
        $this->assertTrue($result['hasmorecourses']);

        $more = external_api::clean_returnvalue(get_more::execute_returns(), get_more::execute('course', 4));
        $this->assertCount(2, $more['courses']);
        $this->assertFalse($more['hasmore']);

        $courseid = $result['courses'][0]['id'];
        $rated = external_api::clean_returnvalue(
            submit_feedback::execute_returns(),
            submit_feedback::execute('course', $courseid, -1, 'Not for me')
        );
        $this->assertSame(-1, $rated['rating']);

        $click = external_api::clean_returnvalue(log_click::execute_returns(), log_click::execute('course', $courseid, 1));
        $this->assertTrue($click['success']);

        $enrol = external_api::clean_returnvalue(
            enrol_course::execute_returns(),
            enrol_course::execute($result['courses'][1]['id'])
        );
        $this->assertTrue($enrol['success']);

        $this->assertEquals(1, $DB->count_records(ai_client::LOG_TABLE));
    }

    public function test_guest_and_capability(): void {
        $this->setGuestUser();
        $this->expectException(\require_login_exception::class);
        get_recommendations::execute();
    }

    public function test_capability_prohibited(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('block/aicourserecommender:use', CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $user->id, \context_system::instance());
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        get_recommendations::execute();
    }

    public function test_invalid_item_type(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        answers_manager::save_consent((int) $user->id);
        $this->expectException(\invalid_parameter_exception::class);
        submit_feedback::execute('user', 1, 1, '');
    }

    public function test_enrol_path(): void {
        $user = $this->getDataGenerator()->create_user();
        $c1 = $this->plugingenerator->create_candidate_course();
        $c2 = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $path = $this->plugingenerator->create_path(['courses' => [$c1->id, $c2->id]]);
        $hidden = $this->plugingenerator->create_path(['courses' => [$c1->id], 'visible' => 0]);

        $this->setUser($user);
        $result = external_api::clean_returnvalue(enrol_path::execute_returns(), enrol_path::execute($path->id));
        $this->assertSame(1, $result['count']);
        $this->assertSame(['enrolled', 'unavailable'], array_column($result['results'], 'status'));

        $this->expectException(\moodle_exception::class);
        enrol_path::execute($hidden->id);
    }

    public function test_generate_path_description_and_image(): void {
        $c1 = $this->getDataGenerator()->create_course(['fullname' => 'Excel basics']);
        $this->setAdminUser();

        $result = external_api::clean_returnvalue(
            generate_path_description::execute_returns(),
            generate_path_description::execute('Data path', [$c1->id])
        );
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Generated description (en)', $result['description']);
        $this->assertStringContainsString('Excel basics', end($this->ai->prompts));

        $image = external_api::clean_returnvalue(
            generate_path_image::execute_returns(),
            generate_path_image::execute('Data path', '<p>Learn data</p>')
        );
        $this->assertTrue($image['success']);
        $this->assertSame('ai-image.png', $image['filename']);

        $this->ai->imageavailable = false;
        $image = generate_path_image::execute('Data path', '');
        $this->assertFalse($image['success']);
    }

    public function test_generate_path_description_requires_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        generate_path_description::execute('Path', [1]);
    }

    public function test_multilang_description_html(): void {
        $html = generate_path_description::build_html(['en' => 'Hello', 'es' => 'Hola <b>']);
        $expected = '<p><span lang="en" class="multilang">Hello</span>' .
            '<span lang="es" class="multilang">Hola &lt;b&gt;</span></p>';
        $this->assertSame($expected, $html);
        $this->assertSame('<p>Only</p>', generate_path_description::build_html(['en' => 'Only']));
    }
}
