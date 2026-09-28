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

namespace block_aicourserecommender\tests;

use block_aicourserecommender\local\ai_client;

/**
 * Fake AI client for PHPUnit and Behat. It never calls a provider.
 *
 * Queued answers are returned first. Without queued answers it builds a plausible answer from the prompt:
 * a ranking of every candidate, a short summary or a description per language.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_ai_client extends ai_client {
    /** @var array Queued answers: a string is a successful answer, false a failure. */
    public array $responses = [];

    /** @var string[] Prompts received. */
    public array $prompts = [];

    /** @var bool Whether text generation is available. */
    public bool $available = true;

    /** @var bool Whether image generation is available. */
    public bool $imageavailable = true;

    /** @var string|null When set, image generation fails with this provider error. */
    public ?string $imageerror = null;

    /** @var bool|null Policy status; null uses the real status. PHPUnit tests assume it was accepted. */
    public ?bool $policyaccepted = true;

    #[\Override]
    public function is_text_available(): bool {
        return $this->available;
    }

    #[\Override]
    public function is_image_available(): bool {
        return $this->imageavailable;
    }

    #[\Override]
    public function has_accepted_policy(int $userid): bool {
        return $this->policyaccepted ?? parent::has_accepted_policy($userid);
    }

    #[\Override]
    public function generate_text(string $prompt, int $userid, string $calltype, ?int $contextid = null): array {
        $start = microtime(true);
        $this->prompts[] = $prompt;
        $answer = $this->responses ? array_shift($this->responses) : self::auto_answer($prompt);
        $success = $answer !== false;
        $error = $success ? '' : 'Fake failure';
        $this->log($userid, $calltype, $success, $error, $start, $success ? 42 : 0);
        return ['success' => $success, 'text' => $success ? (string) $answer : '', 'error' => $error];
    }

    #[\Override]
    public function generate_image(string $prompt, int $userid, ?int $contextid = null): array {
        $start = microtime(true);
        $this->prompts[] = $prompt;
        if ($this->imageerror !== null) {
            $this->log($userid, self::CALL_IMAGE, false, $this->imageerror, $start, 0);
            return ['success' => false, 'file' => null, 'error' => $this->imageerror];
        }
        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $file = $fs->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'ai-image.png',
        ], base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->log($userid, self::CALL_IMAGE, true, '', $start, 0);
        return ['success' => true, 'file' => $file, 'error' => ''];
    }

    /**
     * Builds an answer from the prompt.
     *
     * @param string $prompt Prompt.
     * @return string
     */
    public static function auto_answer(string $prompt): string {
        if (str_contains($prompt, '=== CANDIDATE COURSES ===')) {
            $courses = self::extract_items($prompt, '=== CANDIDATE COURSES ===', '=== CANDIDATE PATHS ===');
            $paths = self::extract_items($prompt, '=== CANDIDATE PATHS ===', '=== OUTPUT FORMAT ===');
            $ranking = ['courses' => [], 'paths' => []];
            foreach (['courses' => $courses, 'paths' => $paths] as $key => $items) {
                $score = 95;
                foreach ($items as $item) {
                    $name = $item['name'] ?? ($item['title'] ?? '');
                    $ranking[$key][] = [
                        'id' => $item['id'],
                        'score' => max(40, $score),
                        'reason' => 'It fits your goals: ' . $name,
                    ];
                    $score -= 5;
                }
            }
            return json_encode($ranking);
        }
        if (preg_match('/Write it in each of these languages: (.*)\./', $prompt, $matches)) {
            preg_match_all('/"([a-z_]+)"/', $matches[1], $langs);
            $result = [];
            foreach ($langs[1] as $lang) {
                $result[$lang] = 'Generated description (' . $lang . ')';
            }
            return json_encode($result);
        }
        return 'A practical introductory course for professionals.';
    }

    /**
     * Candidate items (one JSON object per line) between two markers of the prompt.
     *
     * @param string $prompt Prompt.
     * @param string $from Start marker.
     * @param string $to End marker.
     * @return array[]
     */
    protected static function extract_items(string $prompt, string $from, string $to): array {
        $start = strpos($prompt, $from);
        $end = strpos($prompt, $to, $start);
        if ($start === false || $end === false) {
            return [];
        }
        $items = [];
        foreach (explode("\n", substr($prompt, $start + strlen($from), $end - $start - strlen($from))) as $line) {
            $item = json_decode(trim($line), true);
            if (is_array($item) && isset($item['id'])) {
                $items[] = $item;
            }
        }
        return $items;
    }
}
