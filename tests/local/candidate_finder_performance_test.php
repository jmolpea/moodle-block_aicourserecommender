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
 * Performance test of the candidate finder with a large catalogue.
 *
 * It creates 5,000 courses with the Moodle data generator, so it is skipped unless the environment variable
 * BLOCK_AICOURSERECOMMENDER_PERF is set, for example:
 *
 *     BLOCK_AICOURSERECOMMENDER_PERF=5000 vendor/bin/phpunit --filter candidate_finder_performance_test
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\candidate_finder
 */
final class candidate_finder_performance_test extends \advanced_testcase {
    public function test_large_catalogue_under_one_second(): void {
        $count = (int) getenv('BLOCK_AICOURSERECOMMENDER_PERF');
        if ($count <= 0) {
            $this->markTestSkipped('Set BLOCK_AICOURSERECOMMENDER_PERF=5000 to run the performance test.');
        }
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
        $categories = [];
        for ($i = 0; $i < 20; $i++) {
            $categories[] = $this->getDataGenerator()->create_category()->id;
        }
        $user = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < $count; $i++) {
            $enrol = [];
            if ($i % 10 === 0) {
                // One in ten courses is closed, so the query has something to filter.
                $enrol = ['enrolenddate' => time() - DAYSECS];
            }
            $record = ['category' => $categories[$i % 20], 'fullname' => 'Course ' . $i];
            $course = $generator->create_candidate_course($record, $enrol);
            if ($i % 50 === 0) {
                $this->getDataGenerator()->enrol_user($user->id, $course->id);
            }
        }
        $this->setUser($user);
        accesslib_clear_all_caches_for_unit_testing();
        \cache_helper::purge_all();

        $finder = new candidate_finder();
        $start = microtime(true);
        $candidates = $finder->get_candidates((int) $user->id);
        $elapsed = microtime(true) - $start;

        fwrite(STDERR, sprintf("\nCandidate finder: %d courses, %d candidates, %.3f s\n", $count, count($candidates), $elapsed));
        $this->assertGreaterThan(0, count($candidates));
        $this->assertLessThan(1.0, $elapsed);
    }
}
