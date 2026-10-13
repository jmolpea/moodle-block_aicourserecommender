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

namespace block_aicourserecommender\form;

#[\PHPUnit\Framework\Attributes\CoversClass(\block_aicourserecommender\form\report_filter_form::class)]
/**
 * Tests of the date range filter of the report.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\form\report_filter_form
 */
final class report_filter_form_test extends \advanced_testcase {
    public function test_labels_use_valid_strings(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/blocks/aicourserecommender/report.php');

        $form = new report_filter_form($PAGE->url);
        $html = $form->render();

        // An unknown string identifier raises a debugging message and shows the identifier in brackets.
        $this->assertDebuggingNotCalled();
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringContainsString(get_string('report_from', 'block_aicourserecommender'), $html);
        $this->assertStringContainsString(get_string('report_to', 'block_aicourserecommender'), $html);
    }

    public function test_validation_rejects_inverted_range(): void {
        $this->resetAfterTest();
        $form = new report_filter_form();
        $now = time();

        $this->assertArrayHasKey('to', $form->validation(['from' => $now, 'to' => $now - DAYSECS], []));
        $this->assertSame([], $form->validation(['from' => $now - DAYSECS, 'to' => $now], []));
    }
}
