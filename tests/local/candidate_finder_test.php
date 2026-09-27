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
 * Tests of the candidate course rules.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\candidate_finder
 */
final class candidate_finder_test extends \advanced_testcase {
    /** @var \block_aicourserecommender_generator Plugin generator. */
    protected $plugingenerator;

    /** @var \stdClass Learner. */
    protected $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('block_aicourserecommender');
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
    }

    /**
     * Candidate ids for the learner.
     *
     * @return int[]
     */
    protected function candidates(): array {
        return array_keys((new candidate_finder())->get_candidates((int) $this->user->id));
    }

    public function test_open_visible_course_is_candidate(): void {
        $course = $this->plugingenerator->create_candidate_course();
        $this->assertEquals([$course->id], $this->candidates());
    }

    public function test_rule1_hidden_course_and_site_are_excluded(): void {
        $this->plugingenerator->create_candidate_course(['visible' => 0]);
        $this->assertEquals([], $this->candidates());
        $this->assertNotContains(SITEID, $this->candidates());
    }

    public function test_rule2_finished_course_is_excluded(): void {
        $this->plugingenerator->create_candidate_course(['startdate' => time() - 20 * DAYSECS, 'enddate' => time() - DAYSECS]);
        $future = $this->plugingenerator->create_candidate_course(['startdate' => time(), 'enddate' => time() + DAYSECS]);
        $noend = $this->plugingenerator->create_candidate_course(['enddate' => 0]);
        $this->assertEqualsCanonicalizing([$future->id, $noend->id], $this->candidates());
    }

    public function test_rule3_enrolment_must_be_open(): void {
        global $DB;
        $disabled = $this->plugingenerator->create_candidate_course([], ['status' => ENROL_INSTANCE_DISABLED]);
        $notyet = $this->plugingenerator->create_candidate_course([], ['enrolstartdate' => time() + DAYSECS]);
        $closed = $this->plugingenerator->create_candidate_course([], ['enrolenddate' => time() - DAYSECS]);
        $nonew = $this->plugingenerator->create_candidate_course([], ['customint6' => 0]);
        $full = $this->plugingenerator->create_candidate_course([], ['customint3' => 1]);
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $full->id, null, 'self');
        $withkey = $this->plugingenerator->create_candidate_course([], ['password' => 'secret']);
        $open = $this->plugingenerator->create_candidate_course([], ['enrolstartdate' => time() - DAYSECS,
            'enrolenddate' => time() + DAYSECS, 'customint3' => 5]);

        $candidates = $this->candidates();
        $this->assertNotContains((int) $disabled->id, $candidates);
        $this->assertNotContains((int) $notyet->id, $candidates);
        $this->assertNotContains((int) $closed->id, $candidates);
        $this->assertNotContains((int) $nonew->id, $candidates);
        $this->assertNotContains((int) $full->id, $candidates);
        // Courses with an enrolment key are candidates; the card links to the course instead of enrolling.
        $this->assertContains((int) $withkey->id, $candidates);
        $this->assertContains((int) $open->id, $candidates);

        // Only the configured methods count.
        set_config('enrolmethods', 'guest', 'block_aicourserecommender');
        $this->assertEquals([], $this->candidates());
        $this->assertTrue($DB->record_exists('enrol', ['courseid' => $open->id, 'enrol' => 'self']));
    }

    public function test_rule3_cohort_restriction(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->plugingenerator->create_candidate_course([], ['customint5' => $cohort->id]);
        $this->assertNotContains((int) $course->id, $this->candidates());
        cohort_add_member($cohort->id, $this->user->id);
        $this->assertContains((int) $course->id, $this->candidates());
    }

    public function test_rule4_enrolled_or_suspended_user_is_excluded(): void {
        $active = $this->plugingenerator->create_candidate_course();
        $suspended = $this->plugingenerator->create_candidate_course();
        $free = $this->plugingenerator->create_candidate_course();
        $this->getDataGenerator()->enrol_user($this->user->id, $active->id);
        $this->getDataGenerator()->enrol_user($this->user->id, $suspended->id, null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertEquals([$free->id], $this->candidates());
    }

    public function test_rule5_category_filter_includes_subcategories(): void {
        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $other = $this->getDataGenerator()->create_category();
        $incat = $this->plugingenerator->create_candidate_course(['category' => $parent->id]);
        $insub = $this->plugingenerator->create_candidate_course(['category' => $child->id]);
        $outside = $this->plugingenerator->create_candidate_course(['category' => $other->id]);

        set_config('categoryfilter', 1, 'block_aicourserecommender');
        set_config('categories', (string) $parent->id, 'block_aicourserecommender');
        $this->assertEqualsCanonicalizing([$incat->id, $insub->id], $this->candidates());

        set_config('categoryfilter', 0, 'block_aicourserecommender');
        $this->assertContains((int) $outside->id, $this->candidates());
    }

    public function test_rule6_exclusion_custom_field(): void {
        $category = $this->getDataGenerator()->create_custom_field_category(['component' => 'core_course', 'area' => 'course']);
        $field = $this->getDataGenerator()->create_custom_field(['categoryid' => $category->get('id'), 'type' => 'checkbox',
            'shortname' => 'norecommend']);
        $excluded = $this->plugingenerator->create_candidate_course(['customfield_norecommend' => 1]);
        $included = $this->plugingenerator->create_candidate_course(['customfield_norecommend' => 0]);

        set_config('excludefield', $field->get('id'), 'block_aicourserecommender');
        $this->assertEquals([$included->id], $this->candidates());
        set_config('excludefield', 0, 'block_aicourserecommender');
        $this->assertEqualsCanonicalizing([$included->id, $excluded->id], $this->candidates());
    }

    public function test_rule7_user_must_see_course_info(): void {
        $category = $this->getDataGenerator()->create_category();
        $course = $this->plugingenerator->create_candidate_course(['category' => $category->id]);
        $this->assertContains((int) $course->id, $this->candidates());

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/category:viewcourselist', CAP_PROHIBIT, $roleid, \context_coursecat::instance($category->id));
        role_assign($roleid, $this->user->id, \context_coursecat::instance($category->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertNotContains((int) $course->id, $this->candidates());
    }

    public function test_self_enrol_capability_is_required(): void {
        $course = $this->plugingenerator->create_candidate_course();
        $userrole = (int) get_config('core', 'defaultuserroleid');
        assign_capability('enrol/self:enrolself', CAP_PROHIBIT, $userrole, \context_course::instance($course->id));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertNotContains((int) $course->id, $this->candidates());
    }

    public function test_restrict_ids(): void {
        $a = $this->plugingenerator->create_candidate_course();
        $b = $this->plugingenerator->create_candidate_course();
        $finder = new candidate_finder();
        $this->assertEquals([$b->id], array_keys($finder->get_candidates((int) $this->user->id, [$b->id])));
        $this->assertEquals([], $finder->get_candidates((int) $this->user->id, []));
        $this->assertCount(2, $finder->get_candidates((int) $this->user->id));
        $this->assertNotEmpty($a);
    }

    public function test_multiple_instances_do_not_duplicate(): void {
        global $DB;
        $course = $this->plugingenerator->create_candidate_course();
        $plugin = enrol_get_plugin('self');
        $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED, 'customint6' => 1, 'name' => 'Second']);
        $this->assertEquals(2, $DB->count_records('enrol', ['courseid' => $course->id, 'enrol' => 'self']));
        $this->assertEquals([$course->id], $this->candidates());
    }
}
