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

use block_aicourserecommender\task\generate_summaries;
use block_aicourserecommender\tests\fake_ai_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');

/**
 * Tests of course metadata, summaries, tasks and observers.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\summary_manager
 * @covers     \block_aicourserecommender\local\course_data
 * @covers     \block_aicourserecommender\observer
 * @covers     \block_aicourserecommender\task\generate_summaries
 */
final class summary_manager_test extends \advanced_testcase {
    /** @var fake_ai_client Fake AI. */
    protected fake_ai_client $ai;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ai = new fake_ai_client();
        \core\di::set(ai_client::class, $this->ai);
    }

    public function test_metadata_contains_everything_but_private_fields(): void {
        $category = $this->getDataGenerator()->create_custom_field_category(['component' => 'core_course', 'area' => 'course']);
        $this->getDataGenerator()->create_custom_field(['categoryid' => $category->get('id'), 'type' => 'text',
            'shortname' => 'level', 'name' => 'Level', 'configdata' => ['visibility' => 2]]);
        $this->getDataGenerator()->create_custom_field(['categoryid' => $category->get('id'), 'type' => 'text',
            'shortname' => 'cost', 'name' => 'Cost centre', 'configdata' => ['visibility' => 0]]);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Excel', 'summary' => '<p>Spreadsheets</p>',
            'startdate' => mktime(0, 0, 0, 10, 1, 2030), 'customfield_level' => 'Basic', 'customfield_cost' => 'CC-99']);
        \core_tag_tag::set_item_tags('core', 'course', $course->id, \context_course::instance($course->id), ['Office']);

        $data = course_data::get_metadata([$course->id])[$course->id];
        $this->assertSame('Excel', $data['name']);
        $this->assertSame('Spreadsheets', $data['description']);
        $this->assertSame('2030-10-01', $data['start']);
        $this->assertSame(['Office'], $data['tags']);
        $this->assertSame('Basic', $data['fields']['Level']);
        $this->assertArrayNotHasKey('Cost centre', $data['fields']);

        set_config('includehiddenfields', 1, 'block_aicourserecommender');
        $data = course_data::get_metadata([$course->id])[$course->id];
        $this->assertSame('CC-99', $data['fields']['Cost centre']);
    }

    public function test_summary_generated_once_until_data_changes(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => 'Original']);
        $this->assertTrue(summary_manager::update_course((int) $course->id));
        $this->assertFalse(summary_manager::update_course((int) $course->id), 'Same hash, no call.');
        $this->assertCount(1, $this->ai->prompts);
        $this->assertStringContainsString('Original', $this->ai->prompts[0]);

        $DB->set_field('course', 'summary', 'Changed', ['id' => $course->id]);
        $this->assertTrue(summary_manager::update_course((int) $course->id));
        $this->assertCount(2, $this->ai->prompts);
        $this->assertSame(
            'A practical introductory course for professionals.',
            $DB->get_field(summary_manager::TABLE, 'summary', ['courseid' => $course->id])
        );
    }

    public function test_scheduled_task_limit_and_hidden_courses(): void {
        global $DB;
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();
        $hidden = $this->getDataGenerator()->create_course(['visible' => 0]);
        $ended = $this->getDataGenerator()->create_course(['startdate' => time() - 10 * DAYSECS, 'enddate' => time() - DAYSECS]);

        $this->expectOutputRegex('/Summary generated/');
        $this->assertSame(2, generate_summaries::run(2));
        $this->assertSame(1, generate_summaries::run(10));
        $this->assertSame(0, generate_summaries::run(10));
        $this->assertFalse($DB->record_exists(summary_manager::TABLE, ['courseid' => $hidden->id]));
        $this->assertFalse($DB->record_exists(summary_manager::TABLE, ['courseid' => $ended->id]));

        $this->ai->available = false;
        $this->assertSame(0, generate_summaries::run(10));
    }

    public function test_task_stops_at_provider_rate_limit(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_course();
        }
        $this->ai->responses = ['Summary one.', fake_ai_client::RATE_LIMITED];
        $this->expectOutputRegex('/rate limit was reached/');
        $this->assertSame(1, generate_summaries::run(10));
        // One success and one rejected call: the other three courses were not attempted.
        $this->assertCount(2, $this->ai->prompts);
    }

    public function test_observers_queue_tasks_and_clean_up(): void {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $tasks = \core\task\manager::get_adhoc_tasks(\block_aicourserecommender\task\update_course_summary::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($course->id, reset($tasks)->get_custom_data()->courseid);

        $DB->insert_record(summary_manager::TABLE, ['courseid' => $course->id, 'summary' => 'S', 'datahash' => 'x',
            'timemodified' => time()]);
        $path = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender')
            ->create_path(['courses' => [$course->id]]);
        delete_course($course, false);
        $this->assertFalse($DB->record_exists(summary_manager::TABLE, ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists(path_manager::COURSES_TABLE, ['pathid' => $path->id]));
    }
}
