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

/**
 * Tests of learning paths and path enrolment.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\path_manager
 * @covers     \block_aicourserecommender\local\enrolment_helper
 */
final class path_manager_test extends \advanced_testcase {
    /** @var \block_aicourserecommender_generator Plugin generator. */
    protected $plugingenerator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
    }

    public function test_save_order_and_move(): void {
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $c3 = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $a = $this->plugingenerator->create_path(['name' => 'A', 'courses' => [$c3->id, $c1->id, $c2->id, $c1->id]]);
        $b = $this->plugingenerator->create_path(['name' => 'B', 'courses' => [$c1->id]]);
        $this->assertSame([(int) $c3->id, (int) $c1->id, (int) $c2->id], path_manager::get_course_ids((int) $a->id));
        $this->assertSame([(int) $a->id, (int) $b->id], array_map('intval', array_keys(path_manager::get_paths())));

        path_manager::move((int) $b->id, -1);
        $this->assertSame([(int) $b->id, (int) $a->id], array_map('intval', array_keys(path_manager::get_paths())));
        path_manager::move((int) $b->id, -1);
        $this->assertSame([(int) $b->id, (int) $a->id], array_map('intval', array_keys(path_manager::get_paths())));

        path_manager::set_visible((int) $a->id, false);
        $this->assertSame([(int) $b->id], array_map('intval', array_keys(path_manager::get_paths(true))));

        // Update keeps the id and replaces the courses.
        path_manager::save_path((object) ['id' => $a->id, 'name' => 'A2', 'description' => '', 'visible' => 1], [$c2->id]);
        $this->assertSame('A2', path_manager::get_path((int) $a->id)->name);
        $this->assertSame([(int) $c2->id], path_manager::get_course_ids((int) $a->id));
    }

    public function test_delete_removes_everything(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $path = $this->plugingenerator->create_path(['courses' => [$course->id]]);
        feedback_manager::save((int) $user->id, 'path', (int) $path->id, -1, 'No');
        activity_logger::log((int) $user->id, activity_logger::ACTION_CLICK, 'path', (int) $path->id, 1);

        path_manager::delete_path((int) $path->id);
        $this->assertFalse($DB->record_exists(path_manager::TABLE, ['id' => $path->id]));
        $this->assertFalse($DB->record_exists(path_manager::COURSES_TABLE, ['pathid' => $path->id]));
        $this->assertFalse($DB->record_exists(feedback_manager::TABLE, ['itemtype' => 'path', 'itemid' => $path->id]));
        $this->assertFalse($DB->record_exists(activity_logger::TABLE, ['itemtype' => 'path', 'itemid' => $path->id]));
    }

    public function test_multilang_name_is_formatted(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \filter_manager::reset_caches();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $path = $this->plugingenerator->create_path(['courses' => [$course->id],
            'name' => '<span lang="en" class="multilang">English title</span><span lang="es" class="multilang">Título</span>']);
        $this->assertSame('English title', path_manager::format_name($path));
    }

    public function test_progress_and_enrol_path(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $CFG->enablecompletion = 1;
        $user = $this->getDataGenerator()->create_user();

        $completed = $this->plugingenerator->create_candidate_course(['enablecompletion' => 1]);
        $inprogress = $this->plugingenerator->create_candidate_course(['enablecompletion' => 1]);
        $available = $this->plugingenerator->create_candidate_course();
        $withkey = $this->plugingenerator->create_candidate_course([], ['password' => 'key']);
        $closed = $this->getDataGenerator()->create_course(['startdate' => time() + 10 * DAYSECS]);

        $this->setAdminUser();
        $path = $this->plugingenerator->create_path(['courses' => [$completed->id, $inprogress->id, $available->id,
            $withkey->id, $closed->id]]);

        $this->getDataGenerator()->enrol_user($user->id, $completed->id);
        $this->getDataGenerator()->enrol_user($user->id, $inprogress->id);
        $ccompletion = new \completion_completion(['course' => $completed->id, 'userid' => $user->id]);
        $ccompletion->mark_complete();

        $this->setUser($user);
        $progress = path_manager::get_progress((int) $path->id, (int) $user->id);
        $statuses = array_column($progress, 'status');
        $this->assertSame(['completed', 'inprogress', 'available', 'available', 'unavailable'], $statuses);
        $this->assertSame([false, false, true, false, false], array_column($progress, 'directenrol'));

        $results = (new enrolment_helper())->enrol_path((int) $path->id);
        $bycourse = array_column($results, 'status', 'courseid');
        $this->assertSame('already', $bycourse[$completed->id]);
        $this->assertSame('already', $bycourse[$inprogress->id]);
        $this->assertSame('enrolled', $bycourse[$available->id]);
        $this->assertSame('unavailable', $bycourse[$withkey->id]);
        $this->assertSame('unavailable', $bycourse[$closed->id]);
        $this->assertTrue(is_enrolled(\context_course::instance($available->id), $user));
        $this->assertFalse(is_enrolled(\context_course::instance($withkey->id), $user));

        $this->assertTrue($DB->record_exists(activity_logger::TABLE, ['userid' => $user->id, 'action' => 'enrol',
            'itemtype' => 'path', 'itemid' => $path->id]));
    }

    public function test_enrol_course_only_direct_self(): void {
        $user = $this->getDataGenerator()->create_user();
        $direct = $this->plugingenerator->create_candidate_course();
        $withkey = $this->plugingenerator->create_candidate_course([], ['password' => 'key']);
        $closed = $this->plugingenerator->create_candidate_course([], ['enrolenddate' => time() - DAYSECS]);
        $this->setUser($user);
        $sink = $this->redirectEvents();

        $helper = new enrolment_helper();
        $this->assertTrue($helper->enrol_course((int) $direct->id));
        $this->assertFalse($helper->enrol_course((int) $withkey->id));
        $this->assertFalse($helper->enrol_course((int) $closed->id));
        $this->assertFalse($helper->enrol_course((int) $direct->id), 'Already enrolled.');

        $events = array_filter($sink->get_events(), static fn($e) =>
            $e instanceof \block_aicourserecommender\event\course_enrolled_from_recommendation);
        $this->assertCount(1, $events);
        $this->assertEquals($direct->id, reset($events)->courseid);
    }

    public function test_image_url_default_and_uploaded(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $path = $this->plugingenerator->create_path(['courses' => [$course->id]]);
        $this->assertStringStartsWith('data:image/svg+xml', path_manager::get_image_url((int) $path->id));

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'block_aicourserecommender',
            'filearea' => path_manager::FILEAREA,
            'itemid' => $path->id,
            'filepath' => '/',
            'filename' => 'image.png',
        ], base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->assertStringContainsString('/pluginfile.php/', path_manager::get_image_url((int) $path->id));
    }
}
