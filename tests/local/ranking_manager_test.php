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

use block_aicourserecommender\tests\fake_ai_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');

/**
 * Tests of the ranking: AI calls, validation, cache, daily limit and paging.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\ranking_manager
 */
final class ranking_manager_test extends \advanced_testcase {
    /** @var fake_ai_client Fake AI. */
    protected fake_ai_client $ai;

    /** @var \block_aicourserecommender_generator Plugin generator. */
    protected $plugingenerator;

    /** @var \stdClass Learner. */
    protected \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ai = new fake_ai_client();
        \core\di::set(ai_client::class, $this->ai);
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
        $this->user = $this->getDataGenerator()->create_user(['city' => 'Madrid', 'firstname' => 'Secretname',
            'email' => 'secret@example.com']);
        $this->setUser($this->user);
        $this->plugingenerator->create_answers((int) $this->user->id);
    }

    /**
     * Creates candidate courses.
     *
     * @param int $count Number of courses.
     * @return \stdClass[]
     */
    protected function create_courses(int $count): array {
        $courses = [];
        for ($i = 1; $i <= $count; $i++) {
            $courses[] = $this->plugingenerator->create_candidate_course(['fullname' => 'Course ' . $i]);
        }
        return $courses;
    }

    /**
     * Number of AI calls logged.
     *
     * @return int
     */
    protected function calls(): int {
        global $DB;
        return $DB->count_records(ai_client::LOG_TABLE);
    }

    public function test_questionnaire_state_without_answers(): void {
        global $DB;
        $DB->delete_records('block_aicourserecommender_answers');
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_QUESTIONNAIRE, $result['status']);
        $this->assertSame(0, $this->calls());
    }

    public function test_first_page_and_personal_data_not_sent(): void {
        $this->create_courses(6);
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_OK, $result['status']);
        $this->assertCount(4, $result['courses']);
        $this->assertTrue($result['hasmorecourses']);
        $this->assertSame(1, $result['courses'][0]['position']);
        $this->assertSame(1, $this->calls());

        $prompt = $this->ai->prompts[0];
        $this->assertStringContainsString('Madrid', $prompt);
        $this->assertStringNotContainsString('Secretname', $prompt);
        $this->assertStringNotContainsString('secret@example.com', $prompt);
        $this->assertStringNotContainsString($this->user->username, $prompt);
    }

    public function test_second_visit_within_ttl_does_not_call_ai(): void {
        $this->create_courses(3);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(1, $this->calls());
    }

    public function test_expired_ranking_calls_ai(): void {
        global $DB;
        $this->create_courses(2);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        $DB->set_field(ranking_manager::TABLE, 'timeexpires', time() - 1, ['userid' => $this->user->id]);
        $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(2, $this->calls());
    }

    public function test_changing_answers_calls_ai(): void {
        $this->create_courses(2);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        answers_manager::save_answers((int) $this->user->id, [3 => 'Data analysis, advanced']);
        $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(2, $this->calls());
        $this->assertStringContainsString('Data analysis, advanced', end($this->ai->prompts));
    }

    public function test_new_candidate_calls_ai_but_removed_candidate_does_not(): void {
        $courses = $this->create_courses(3);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);

        // A course stops being a candidate: no call, and it is not shown.
        $this->getDataGenerator()->enrol_user($this->user->id, $courses[0]->id);
        $result = $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(1, $this->calls());
        $this->assertNotContains((int) $courses[0]->id, array_column($result['courses'], 'id'));

        // A new candidate appears: new call.
        $this->plugingenerator->create_candidate_course(['fullname' => 'Brand new']);
        $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(2, $this->calls());
    }

    public function test_institution_prompt_change_calls_ai(): void {
        $this->create_courses(2);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        set_config('institutionprompt', 'Use a commercial tone.', 'block_aicourserecommender');
        $manager->get_recommendations((int) $this->user->id);
        $this->assertSame(2, $this->calls());
    }

    public function test_see_more_does_not_call_ai(): void {
        $this->create_courses(10);
        $manager = new ranking_manager();
        $first = $manager->get_recommendations((int) $this->user->id);
        $this->assertCount(4, $first['courses']);

        $second = $manager->get_more((int) $this->user->id, 'course', 4);
        $this->assertCount(4, $second['items']);
        $this->assertTrue($second['hasmore']);
        $this->assertSame(5, $second['items'][0]['position']);

        $third = $manager->get_more((int) $this->user->id, 'course', 8);
        $this->assertCount(2, $third['items']);
        $this->assertFalse($third['hasmore']);

        $shown = array_merge(
            array_column($first['courses'], 'id'),
            array_column($second['items'], 'id'),
            array_column($third['items'], 'id')
        );
        $this->assertCount(10, array_unique($shown));
        $this->assertSame(1, $this->calls());
    }

    public function test_invented_ids_never_reach_the_interface(): void {
        $courses = $this->create_courses(2);
        $this->ai->responses[] = json_encode(['courses' => [
            ['id' => 99999, 'score' => 99, 'reason' => 'Invented'],
            ['id' => (int) $courses[1]->id, 'score' => 80, 'reason' => 'Real'],
        ], 'paths' => [['id' => 555, 'score' => 90, 'reason' => 'Invented path']]]);
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame([(int) $courses[1]->id], array_column($result['courses'], 'id'));
        $this->assertSame([], $result['paths']);
    }

    public function test_invalid_json_is_retried_once(): void {
        $courses = $this->create_courses(1);
        $this->ai->responses = ['Here you have: course 1', json_encode(['courses' => [['id' => (int) $courses[0]->id,
            'score' => 70, 'reason' => 'Fits']], 'paths' => []])];
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_OK, $result['status']);
        $this->assertSame(2, $this->calls());
        $this->assertStringContainsString('=== CORRECTION ===', $this->ai->prompts[1]);
    }

    public function test_invalid_json_twice_is_an_error(): void {
        $this->create_courses(1);
        $this->ai->responses = ['not json', 'still not json'];
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_ERROR, $result['status']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame(2, $this->calls());
    }

    public function test_ai_failure_is_an_error(): void {
        $this->create_courses(1);
        $this->ai->responses = [false];
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_ERROR, $result['status']);
    }

    public function test_no_provider_is_an_error(): void {
        $this->create_courses(1);
        $this->ai->available = false;
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_ERROR, $result['status']);
        $this->assertSame(0, $this->calls());
    }

    public function test_markdown_institution_prompt_cannot_break_json(): void {
        $courses = $this->create_courses(1);
        set_config(
            'institutionprompt',
            'Answer in markdown with a table and a friendly introduction.',
            'block_aicourserecommender'
        );
        // A model that obeys the institution prompt returns markdown around the JSON; a table-only answer is invalid.
        $this->ai->responses = ["| Course | Score |\n|---|---|\n| Course 1 | 90 |", 'Here you go' . "\n" .
            json_encode(['courses' => [['id' => (int) $courses[0]->id, 'score' => 90, 'reason' => '**Great** fit']]])];
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_OK, $result['status']);
        $this->assertSame('Great fit', $result['courses'][0]['reason']);
        $prompt = $this->ai->prompts[0];
        $this->assertGreaterThan(strpos($prompt, 'Answer in markdown'), strpos($prompt, '=== OUTPUT FORMAT ==='));
    }

    public function test_daily_limit(): void {
        global $DB;
        $this->create_courses(2);
        set_config('dailylimit', 2, 'block_aicourserecommender');
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        $manager->get_recommendations((int) $this->user->id, true);
        $this->assertSame(2, $this->calls());

        // Limit reached: the last ranking is shown with a notice and no call is made.
        $result = $manager->get_recommendations((int) $this->user->id, true);
        $this->assertSame(2, $this->calls());
        $this->assertSame(ranking_manager::STATUS_OK, $result['status']);
        $this->assertNotEmpty($result['notice']);

        // Without a stored ranking, an error.
        $DB->delete_records(ranking_manager::TABLE);
        $result = $manager->get_recommendations((int) $this->user->id, true);
        $this->assertSame(ranking_manager::STATUS_ERROR, $result['status']);

        // Calls from scheduled tasks do not count.
        $DB->delete_records(ai_client::LOG_TABLE);
        $DB->insert_record(ai_client::LOG_TABLE, ['userid' => $this->user->id, 'calltype' => ai_client::CALL_INCREMENTAL,
            'success' => 1, 'timecreated' => time()]);
        $this->assertSame(0, ai_client::count_user_calls_today((int) $this->user->id));
    }

    public function test_no_candidates_no_call(): void {
        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame(ranking_manager::STATUS_NORESULTS, $result['status']);
        $this->assertSame(0, $this->calls());
    }

    public function test_negative_rating_hides_item_and_is_sent(): void {
        $courses = $this->create_courses(3);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        feedback_manager::save((int) $this->user->id, 'course', (int) $courses[0]->id, -1, 'Too basic');

        $result = $manager->get_recommendations((int) $this->user->id);
        $this->assertNotContains((int) $courses[0]->id, array_column($result['courses'], 'id'));
        $this->assertSame(1, $this->calls());

        $manager->get_recommendations((int) $this->user->id, true);
        $last = end($this->ai->prompts);
        $this->assertStringContainsString('course "Course 1": Too basic', $last);
        $this->assertStringNotContainsString('"name":"Course 1"', $last, 'Rated down courses are not candidates.');
    }

    public function test_prefilter_for_large_catalogues(): void {
        $this->create_courses(5);
        $match = $this->plugingenerator->create_candidate_course(['fullname' => 'Project management fundamentals']);
        set_config('maxcoursesperprompt', 2, 'block_aicourserecommender');
        $manager = new ranking_manager();
        $inputs = $manager->build_inputs((int) $this->user->id);
        $this->assertCount(2, $inputs['courses']);
        $this->assertContains((int) $match->id, array_column($inputs['courses'], 'id'));
    }

    public function test_summary_replaces_description_in_prompt(): void {
        global $DB;
        $course = $this->plugingenerator->create_candidate_course(['summary' => 'Long original description']);
        $DB->insert_record(summary_manager::TABLE, ['courseid' => $course->id, 'summary' => 'Short AI summary',
            'datahash' => sha1('x'), 'timemodified' => time()]);
        (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertStringContainsString('Short AI summary', $this->ai->prompts[0]);
        $this->assertStringNotContainsString('Long original description', $this->ai->prompts[0]);
    }

    public function test_rank_new_courses_merges_by_score(): void {
        $courses = $this->create_courses(2);
        $manager = new ranking_manager();
        $manager->get_recommendations((int) $this->user->id);
        $new = $this->plugingenerator->create_candidate_course(['fullname' => 'New course']);
        $this->ai->responses[] = json_encode(['courses' => [['id' => (int) $new->id, 'score' => 99, 'reason' => 'New']]]);
        $ranked = $manager->rank_new_courses((int) $this->user->id, [(int) $new->id]);
        $this->assertSame([(int) $new->id], array_column($ranked, 'id'));

        $stored = ranking_manager::get_stored((int) $this->user->id);
        $ids = array_column(json_decode($stored->courses, true), 'id');
        $this->assertSame((int) $new->id, $ids[0]);
        $this->assertCount(3, $ids);
        $this->assertArrayHasKey($new->id, json_decode($stored->candidates, true));
        $this->assertSame(0, ai_client::count_user_calls_today((int) $this->user->id) - 1, 'Incremental call not counted.');
        $this->assertNotEmpty($courses);
    }

    public function test_paths_are_recommended_when_they_have_a_candidate(): void {
        $courses = $this->create_courses(2);
        $closed = $this->getDataGenerator()->create_course();
        $path = $this->plugingenerator->create_path(['name' => 'Leadership', 'courses' => [$closed->id, $courses[0]->id]]);
        $emptypath = $this->plugingenerator->create_path(['name' => 'Closed', 'courses' => [$closed->id]]);
        $hidden = $this->plugingenerator->create_path(['name' => 'Hidden', 'courses' => [$courses[1]->id], 'visible' => 0]);

        $result = (new ranking_manager())->get_recommendations((int) $this->user->id);
        $this->assertSame([(int) $path->id], array_column($result['paths'], 'id'));
        $this->assertSame(2, $result['paths'][0]['coursecount']);
        $this->assertStringContainsString('"title":"Leadership"', $this->ai->prompts[0]);
        $this->assertStringNotContainsString('"title":"Closed"', $this->ai->prompts[0]);
        $this->assertNotEmpty($emptypath);
        $this->assertNotEmpty($hidden);

        set_config('showpaths', 0, 'block_aicourserecommender');
        $this->assertSame([], path_manager::get_candidate_paths([(int) $courses[0]->id]));
    }
}
