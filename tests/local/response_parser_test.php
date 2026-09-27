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
 * Tests of the validation of the AI answers.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\response_parser
 */
final class response_parser_test extends \basic_testcase {
    public function test_valid_json(): void {
        $json = '{"courses":[{"id":3,"score":70,"reason":"B"},{"id":2,"score":90,"reason":"A"}],' .
            '"paths":[{"id":5,"score":60,"reason":"P"}]}';
        $result = response_parser::parse_ranking($json, [2, 3], [5], 10, 10);
        $this->assertSame([2, 3], array_column($result['courses'], 'id'));
        $this->assertSame([90, 70], array_column($result['courses'], 'score'));
        $this->assertSame('A', $result['courses'][0]['reason']);
        $this->assertSame([5], array_column($result['paths'], 'id'));
    }

    public function test_invalid_json(): void {
        $this->assertNull(response_parser::parse_ranking('Sure! Here are your courses: 1, 2 and 3.', [1, 2, 3], [], 10, 10));
        $this->assertNull(response_parser::parse_ranking('{"courses": [', [1], [], 10, 10));
        $this->assertNull(response_parser::parse_ranking('{"paths": []}', [1], [], 10, 10));
        $this->assertNull(response_parser::parse_ranking('[{"id":1}]', [1], [], 10, 10));
        $this->assertNull(response_parser::parse_ranking('{"courses": [], "paths": "none"}', [1], [], 10, 10));
    }

    public function test_code_fences_are_tolerated(): void {
        $fence = str_repeat(chr(96), 3);
        $json = $fence . "json\n{\"courses\":[{\"id\":1,\"score\":80,\"reason\":\"Fits\"}],\"paths\":[]}\n" . $fence;
        $result = response_parser::parse_ranking($json, [1], [], 10, 10);
        $this->assertSame([1], array_column($result['courses'], 'id'));
    }

    public function test_invented_ids_are_dropped(): void {
        $json = '{"courses":[{"id":999,"score":99,"reason":"Invented"},{"id":1,"score":50,"reason":"Real"},' .
            '{"id":1,"score":40,"reason":"Duplicate"},{"id":"abc","score":90},{"score":80}],' .
            '"paths":[{"id":77,"score":90,"reason":"Invented"}]}';
        $result = response_parser::parse_ranking($json, [1, 2], [5], 10, 10);
        $this->assertSame([1], array_column($result['courses'], 'id'));
        $this->assertSame('Real', $result['courses'][0]['reason']);
        $this->assertSame([], $result['paths']);
    }

    public function test_long_reasons_are_cut_and_cleaned(): void {
        $long = str_repeat('Very long reason with <b>html</b> and **markdown**. ', 20);
        $json = json_encode(['courses' => [['id' => 1, 'score' => 150, 'reason' => $long]], 'paths' => []]);
        $result = response_parser::parse_ranking($json, [1], [], 10, 10);
        $reason = $result['courses'][0]['reason'];
        $this->assertLessThanOrEqual(response_parser::MAX_REASON_LENGTH, \core_text::strlen($reason));
        $this->assertStringNotContainsString('<b>', $reason);
        $this->assertStringNotContainsString('**', $reason);
        $this->assertSame(100, $result['courses'][0]['score']);
    }

    public function test_script_injection_is_removed(): void {
        $json = json_encode(['courses' => [['id' => 1, 'score' => 80, 'reason' => '<script>alert(1)</script>Good fit']]]);
        $result = response_parser::parse_ranking($json, [1], [], 10, 10);
        $this->assertStringNotContainsString('<script>', $result['courses'][0]['reason']);
        $this->assertStringContainsString('Good fit', $result['courses'][0]['reason']);
    }

    public function test_maximum_items(): void {
        $courses = [];
        for ($i = 1; $i <= 30; $i++) {
            $courses[] = ['id' => $i, 'score' => 100 - $i, 'reason' => 'R' . $i];
        }
        $result = response_parser::parse_ranking(json_encode(['courses' => $courses, 'paths' => []]), range(1, 30), [], 24, 6);
        $this->assertCount(24, $result['courses']);
        $this->assertSame(1, $result['courses'][0]['id']);
    }
}
