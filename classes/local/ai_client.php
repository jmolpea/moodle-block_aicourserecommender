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

use core_ai\aiactions\generate_image;
use core_ai\aiactions\generate_text;

/**
 * Thin wrapper around the Moodle AI subsystem (core_ai). Every AI call of the plugin goes through here and is logged.
 *
 * The core manager is obtained from the DI container, so tests can replace it with a mock.
 * In Moodle 4.5 core_ai\manager::is_action_available() is static and in 5.0+ it is an instance method; calling it on
 * the instance works in both versions.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_client {
    /** @var string Call type: ranking requested by the learner. */
    public const CALL_RANKING = 'ranking';
    /** @var string Call type: ranking of new courses from the scheduled task. */
    public const CALL_INCREMENTAL = 'incremental';
    /** @var string Call type: course summary. */
    public const CALL_SUMMARY = 'summary';
    /** @var string Call type: learning path description. */
    public const CALL_DESCRIPTION = 'description';
    /** @var string Call type: learning path image. */
    public const CALL_IMAGE = 'image';

    /** @var string Log table. */
    public const LOG_TABLE = 'block_aicourserecommender_ailog';

    /** @var int Error code of the last text generation, 0 when it succeeded. */
    protected int $lasterrorcode = 0;

    /** @var string Error message of the last call, empty when it succeeded. */
    protected string $lasterror = '';

    /**
     * Whether the last call was rejected by the rate limit of the provider (HTTP 429, per user or site wide).
     *
     * @return bool
     */
    public function is_rate_limited(): bool {
        return $this->lasterrorcode === 429 || stripos($this->lasterror, 'rate limit') !== false;
    }

    /**
     * Returns the core AI manager.
     *
     * @return \core_ai\manager
     */
    protected function get_manager(): \core_ai\manager {
        return \core\di::get(\core_ai\manager::class);
    }

    /**
     * Whether at least one enabled provider offers text generation.
     *
     * @return bool
     */
    public function is_text_available(): bool {
        return $this->is_action_available(generate_text::class);
    }

    /**
     * Whether at least one enabled provider offers image generation.
     *
     * @return bool
     */
    public function is_image_available(): bool {
        return $this->is_action_available(generate_image::class);
    }

    /**
     * Whether an action is available.
     *
     * @param string $actionclass Fully qualified action class.
     * @return bool
     */
    protected function is_action_available(string $actionclass): bool {
        try {
            return (bool) $this->get_manager()->is_action_available($actionclass);
        } catch (\Throwable $e) {
            debugging('AI availability check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Whether the user has accepted the AI usage policy of the site.
     *
     * @param int $userid User id.
     * @return bool
     */
    public function has_accepted_policy(int $userid): bool {
        return (bool) \core_ai\manager::get_user_policy_status($userid);
    }

    /**
     * Generates text.
     *
     * @param string $prompt Prompt text.
     * @param int $userid User on whose behalf the call is made.
     * @param string $calltype One of the CALL_* constants, used in the log.
     * @param int|null $contextid Context id, system by default.
     * @return array{success: bool, text: string, error: string}
     */
    public function generate_text(string $prompt, int $userid, string $calltype, ?int $contextid = null): array {
        $contextid = $contextid ?? \context_system::instance()->id;
        $start = microtime(true);
        $text = '';
        $tokens = 0;
        $this->lasterrorcode = 0;
        try {
            $action = new generate_text(contextid: $contextid, userid: $userid, prompttext: $prompt);
            $response = $this->get_manager()->process_action($action);
            $success = $response->get_success();
            $error = $success ? '' : $this->get_error($response);
            $this->lasterrorcode = $success ? 0 : $response->get_errorcode();
            if ($success) {
                $data = $response->get_response_data();
                $text = (string) ($data['generatedcontent'] ?? '');
                $tokens = (int) ($data['prompttokens'] ?? 0) + (int) ($data['completiontokens'] ?? 0);
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }
        $this->log($userid, $calltype, $success, $error, $start, $tokens);
        return ['success' => $success, 'text' => $text, 'error' => $error];
    }

    /**
     * Generates one square image.
     *
     * @param string $prompt Prompt text.
     * @param int $userid User requesting the image.
     * @param int|null $contextid Context id, system by default.
     * @return array{success: bool, file: ?\stored_file, error: string} The file is in the user draft area.
     */
    public function generate_image(string $prompt, int $userid, ?int $contextid = null): array {
        $contextid = $contextid ?? \context_system::instance()->id;
        $start = microtime(true);
        $file = null;
        try {
            $action = new generate_image(
                contextid: $contextid,
                userid: $userid,
                prompttext: $prompt,
                quality: 'standard',
                aspectratio: 'landscape',
                numimages: 1,
                style: 'natural',
            );
            $response = $this->get_manager()->process_action($action);
            $success = $response->get_success();
            $error = $success ? '' : $this->get_error($response);
            if ($success) {
                $file = $response->get_response_data()['draftfile'] ?? null;
                if (!$file instanceof \stored_file) {
                    $success = false;
                    $error = 'No image returned';
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }
        $this->log($userid, self::CALL_IMAGE, $success, $error, $start, 0);
        return ['success' => $success, 'file' => $file, 'error' => $error];
    }

    /**
     * Error message of a failed response. Moodle 5.0 added get_error().
     *
     * @param \core_ai\aiactions\responses\response_base $response Response.
     * @return string
     */
    protected function get_error(\core_ai\aiactions\responses\response_base $response): string {
        $error = $response->get_errormessage();
        if (method_exists($response, 'get_error') && $response->get_error() !== '') {
            $error = $response->get_error() . ($error !== '' ? ': ' . $error : '');
        }
        return $error !== '' ? $error : 'Error ' . $response->get_errorcode();
    }

    /**
     * Writes an entry in the plugin AI log.
     *
     * @param int $userid User id.
     * @param string $calltype Call type.
     * @param bool $success Whether the call succeeded.
     * @param string $error Error message.
     * @param float $start Start time as returned by microtime(true).
     * @param int $tokens Tokens used, 0 when unknown.
     * @return void
     */
    protected function log(int $userid, string $calltype, bool $success, string $error, float $start, int $tokens): void {
        global $DB;
        $this->lasterror = $success ? '' : $error;
        $DB->insert_record(self::LOG_TABLE, (object) [
            'userid' => $userid,
            'calltype' => $calltype,
            'success' => $success ? 1 : 0,
            'error' => \core_text::substr($error, 0, 1000),
            'duration' => (int) round((microtime(true) - $start) * 1000),
            'tokens' => $tokens,
            'timecreated' => \core\di::get(\core\clock::class)->time(),
        ]);
    }

    /**
     * Number of ranking calls made by a user since the start of the current day (user timezone).
     *
     * @param int $userid User id.
     * @return int
     */
    public static function count_user_calls_today(int $userid): int {
        global $DB;
        $midnight = usergetmidnight(\core\di::get(\core\clock::class)->time());
        return $DB->count_records_select(self::LOG_TABLE, 'userid = :userid AND calltype = :calltype AND timecreated >= :since', [
            'userid' => $userid,
            'calltype' => self::CALL_RANKING,
            'since' => $midnight,
        ]);
    }
}
