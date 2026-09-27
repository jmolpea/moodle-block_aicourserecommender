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
 * Validates the JSON returned by the AI.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_parser {
    /** @var int Maximum length of a reason. */
    public const MAX_REASON_LENGTH = 300;

    /**
     * Decodes a JSON object from an AI answer.
     *
     * Some models wrap JSON in markdown code fences even when asked not to. Fences and any text around the outermost
     * object are removed before decoding; the content itself must still be valid JSON.
     *
     * @param string $text AI answer.
     * @return array|null Decoded object or null when it is not a JSON object.
     */
    public static function decode_json_object(string $text): ?array {
        $text = trim($text);
        $text = preg_replace('/^\x60{3}[a-zA-Z]*\s*|\s*\x60{3}$/', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) && !array_is_list($data) ? $data : null;
    }

    /**
     * Parses and validates a ranking answer.
     *
     * Unknown ids (not sent as candidates) and duplicates are dropped, scores are clamped to 0..100 and reasons are
     * cleaned and cut to 300 characters.
     *
     * @param string $text AI answer.
     * @param int[] $courseids Candidate course ids that were sent.
     * @param int[] $pathids Candidate path ids that were sent.
     * @param int $maxcourses Maximum courses kept.
     * @param int $maxpaths Maximum paths kept.
     * @return array|null ['courses' => [...], 'paths' => [...]] or null when the answer is not valid.
     */
    public static function parse_ranking(
        string $text,
        array $courseids,
        array $pathids,
        int $maxcourses,
        int $maxpaths
    ): ?array {
        $data = self::decode_json_object($text);
        if ($data === null || !isset($data['courses']) || !is_array($data['courses'])) {
            return null;
        }
        if (isset($data['paths']) && !is_array($data['paths'])) {
            return null;
        }
        return [
            'courses' => self::clean_items($data['courses'], $courseids, $maxcourses),
            'paths' => self::clean_items($data['paths'] ?? [], $pathids, $maxpaths),
        ];
    }

    /**
     * Cleans a list of ranked items.
     *
     * @param array $items Items from the AI.
     * @param int[] $validids Allowed ids.
     * @param int $max Maximum number of items.
     * @return array[] Each with id, score and reason, sorted by score.
     */
    public static function clean_items(array $items, array $validids, int $max): array {
        $valid = array_flip(array_map('intval', $validids));
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                continue;
            }
            $id = (int) $item['id'];
            if (!isset($valid[$id]) || isset($result[$id])) {
                continue;
            }
            $score = is_numeric($item['score'] ?? null) ? (int) round((float) $item['score']) : 0;
            $result[$id] = [
                'id' => $id,
                'score' => max(0, min(100, $score)),
                'reason' => self::clean_reason((string) ($item['reason'] ?? '')),
            ];
        }
        $result = array_values($result);
        usort($result, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($result, 0, max(0, $max));
    }

    /**
     * Cleans a reason: plain text, no markdown emphasis, at most 300 characters.
     *
     * @param string $reason Reason from the AI.
     * @return string
     */
    public static function clean_reason(string $reason): string {
        $reason = clean_param($reason, PARAM_TEXT);
        $reason = str_replace(['**', '__', "\x60"], '', $reason);
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if (\core_text::strlen($reason) > self::MAX_REASON_LENGTH) {
            $reason = rtrim(\core_text::substr($reason, 0, self::MAX_REASON_LENGTH - 1)) . '…';
        }
        return $reason;
    }
}
