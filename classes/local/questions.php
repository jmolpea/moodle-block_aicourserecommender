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
 * Questionnaire configuration: up to six questions, four with default language strings.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class questions {
    /** @var int Maximum number of questions. */
    public const MAX_QUESTIONS = 6;

    /** @var int Number of questions that have a default text in the language files. */
    public const DEFAULT_QUESTIONS = 4;

    /** @var int Maximum length of each answer. */
    public const MAX_ANSWER_LENGTH = 1000;

    /**
     * Default raw configuration: the four default questions active, the rest inactive.
     *
     * @return array[] Keyed by slot (1..6).
     */
    public static function get_default_config(): array {
        $config = [];
        for ($slot = 1; $slot <= self::MAX_QUESTIONS; $slot++) {
            $config[$slot] = [
                'text' => '',
                'help' => '',
                'enabled' => $slot <= self::DEFAULT_QUESTIONS ? 1 : 0,
                'sortorder' => $slot,
            ];
        }
        return $config;
    }

    /**
     * Raw configuration as stored by the admin setting, merged with the defaults.
     *
     * @return array[] Keyed by slot (1..6).
     */
    public static function get_config(): array {
        $config = self::get_default_config();
        $stored = json_decode(config::get_string('questions'), true);
        if (is_array($stored)) {
            foreach ($stored as $slot => $question) {
                $slot = (int) $slot;
                if (!isset($config[$slot]) || !is_array($question)) {
                    continue;
                }
                $config[$slot] = [
                    'text' => (string) ($question['text'] ?? ''),
                    'help' => (string) ($question['help'] ?? ''),
                    'enabled' => empty($question['enabled']) ? 0 : 1,
                    'sortorder' => (int) ($question['sortorder'] ?? $slot),
                ];
            }
        }
        return $config;
    }

    /**
     * Whether a slot has a default question text in the language files.
     *
     * @param int $slot Question slot.
     * @return bool
     */
    public static function has_default_text(int $slot): bool {
        return $slot >= 1 && $slot <= self::DEFAULT_QUESTIONS;
    }

    /**
     * Validates a raw configuration.
     *
     * @param array $config Raw configuration keyed by slot.
     * @return string Empty string when valid, otherwise the error message.
     */
    public static function validate_config(array $config): string {
        $active = 0;
        foreach ($config as $slot => $question) {
            if (empty($question['enabled'])) {
                continue;
            }
            if (trim((string) $question['text']) === '' && !self::has_default_text((int) $slot)) {
                return get_string('errorquestionnotext', 'block_aicourserecommender', $slot);
            }
            $active++;
        }
        if ($active === 0) {
            return get_string('errornoactivequestion', 'block_aicourserecommender');
        }
        return '';
    }

    /**
     * Active questions, sorted, with the text resolved for the current language.
     *
     * @param \context|null $context Context used by the multilang filter.
     * @return array[] Each with slot, text and help (both formatted, ready to display).
     */
    public static function get_active(?\context $context = null): array {
        $context = $context ?? \context_system::instance();
        $result = [];
        foreach (self::get_config() as $slot => $question) {
            if (empty($question['enabled'])) {
                continue;
            }
            $text = trim($question['text']);
            $help = trim($question['help']);
            if ($text === '') {
                if (!self::has_default_text($slot)) {
                    continue;
                }
                $text = get_string('question' . $slot, 'block_aicourserecommender');
                if ($help === '') {
                    $help = get_string('question' . $slot . 'help', 'block_aicourserecommender');
                }
            }
            $result[] = [
                'slot' => $slot,
                'sortorder' => $question['sortorder'],
                'text' => format_string($text, true, ['context' => $context]),
                'help' => $help === '' ? '' : format_text($help, FORMAT_HTML, ['context' => $context, 'para' => false]),
            ];
        }
        usort($result, static fn(array $a, array $b): int => [$a['sortorder'], $a['slot']] <=> [$b['sortorder'], $b['slot']]);
        return $result;
    }

    /**
     * Cleans the answers sent by a learner: only active slots, plain text, trimmed and cut to the maximum length.
     *
     * @param array $answers Answers keyed by slot.
     * @return array<int, string>
     */
    public static function clean_answers(array $answers): array {
        $clean = [];
        foreach (self::get_active() as $question) {
            $slot = $question['slot'];
            $value = clean_param((string) ($answers[$slot] ?? ''), PARAM_TEXT);
            $value = trim(\core_text::substr($value, 0, self::MAX_ANSWER_LENGTH));
            $clean[$slot] = $value;
        }
        return $clean;
    }
}
