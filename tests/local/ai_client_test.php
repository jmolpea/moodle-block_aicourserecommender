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

use core_ai\aiactions\generate_text;
use core_ai\aiactions\responses\response_generate_text;

/**
 * Tests of the AI client with a mocked core AI manager.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \block_aicourserecommender\local\ai_client
 */
final class ai_client_test extends \advanced_testcase {
    /**
     * Registers a mocked core manager that returns the given response.
     *
     * @param response_generate_text $response Response.
     * @param string|null $expectedprompt Prompt the action must carry.
     * @return void
     */
    protected function mock_manager(response_generate_text $response, ?string $expectedprompt = null): void {
        $manager = $this->createMock(\core_ai\manager::class);
        $manager->expects($this->once())
            ->method('process_action')
            ->willReturnCallback(function ($action) use ($response, $expectedprompt) {
                $this->assertInstanceOf(generate_text::class, $action);
                if ($expectedprompt !== null) {
                    $this->assertSame($expectedprompt, $action->get_configuration('prompttext'));
                }
                return $response;
            });
        \core\di::set(\core_ai\manager::class, $manager);
    }

    public function test_generate_text_success_is_logged_with_tokens(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => '{"courses":[]}', 'prompttokens' => 100, 'completiontokens' => 20]);
        $this->mock_manager($response, 'PROMPT');

        $result = (new ai_client())->generate_text('PROMPT', (int) $user->id, ai_client::CALL_RANKING);
        $this->assertTrue($result['success']);
        $this->assertSame('{"courses":[]}', $result['text']);

        $log = $DB->get_record(ai_client::LOG_TABLE, ['userid' => $user->id]);
        $this->assertEquals(1, $log->success);
        $this->assertEquals(120, $log->tokens);
        $this->assertSame(ai_client::CALL_RANKING, $log->calltype);
        $this->assertSame(1, ai_client::count_user_calls_today((int) $user->id));
    }

    public function test_generate_text_failure_is_logged(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $response = new response_generate_text(success: false, errorcode: 429, errormessage: 'Rate limited');
        $this->mock_manager($response);

        $result = (new ai_client())->generate_text('PROMPT', (int) $user->id, ai_client::CALL_SUMMARY);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Rate limited', $result['error']);
        $log = $DB->get_record(ai_client::LOG_TABLE, ['userid' => $user->id]);
        $this->assertEquals(0, $log->success);
        $this->assertStringContainsString('Rate limited', $log->error);
    }

    public function test_exception_is_caught(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $manager = $this->createMock(\core_ai\manager::class);
        $manager->method('process_action')->willThrowException(new \moodle_exception(
            'generalexceptionmessage',
            'error',
            '',
            'Boom'
        ));
        \core\di::set(\core_ai\manager::class, $manager);

        $result = (new ai_client())->generate_text('PROMPT', (int) $user->id, ai_client::CALL_RANKING);
        $this->assertFalse($result['success']);
    }

    public function test_no_real_provider_means_not_available(): void {
        $this->resetAfterTest();
        // No provider is configured on the test site.
        $this->assertFalse((new ai_client())->is_text_available());
        $this->assertFalse((new ai_client())->is_image_available());
    }
}
