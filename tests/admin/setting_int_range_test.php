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

namespace block_aicourserecommender\admin;

#[\PHPUnit\Framework\Attributes\CoversClass(\block_aicourserecommender\admin\setting_int_range::class)]
/**
 * Tests of the integer admin setting with a range.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\admin\setting_int_range
 */
final class setting_int_range_test extends \advanced_testcase {
    public function test_minimum_only(): void {
        $this->resetAfterTest();
        $setting = new setting_int_range('block_aicourserecommender/dailylimit', 'Limit', '', 5, 1);

        $this->assertTrue($setting->validate('1'));
        $this->assertTrue($setting->validate('500'));
        $this->assertSame(get_string('errorintmin', 'block_aicourserecommender', 1), $setting->validate('0'));
        $this->assertSame(get_string('errorintmin', 'block_aicourserecommender', 1), $setting->validate('-3'));
        $this->assertIsString($setting->validate('abc'));
        $this->assertIsString($setting->validate(''));
    }

    public function test_minimum_and_maximum(): void {
        $this->resetAfterTest();
        $setting = new setting_int_range('block_aicourserecommender/notifythreshold', 'Threshold', '', 70, 0, 100);
        $error = get_string('errorintrange', 'block_aicourserecommender', ['min' => 0, 'max' => 100]);

        $this->assertTrue($setting->validate('0'));
        $this->assertTrue($setting->validate('100'));
        $this->assertSame($error, $setting->validate('101'));
        $this->assertSame($error, $setting->validate('-1'));
    }

    public function test_invalid_value_is_not_saved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = new setting_int_range('block_aicourserecommender/dailylimit', 'Limit', '', 5, 1);

        $this->assertSame('', $setting->write_setting('3'));
        $this->assertEquals(3, get_config('block_aicourserecommender', 'dailylimit'));
        $this->assertNotSame('', $setting->write_setting('0'));
        $this->assertEquals(3, get_config('block_aicourserecommender', 'dailylimit'));
    }
}
