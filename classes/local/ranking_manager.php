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

use block_aicourserecommender\event\recommendations_viewed;
use block_aicourserecommender\output\card_exporter;

/**
 * Builds, stores and pages the AI ranking of each learner.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ranking_manager {
    /** @var string Ranking table. */
    public const TABLE = 'block_aicourserecommender_ranking';

    /** @var string Result status: recommendations available. */
    public const STATUS_OK = 'ok';
    /** @var string Result status: nothing to recommend now. */
    public const STATUS_NORESULTS = 'noresults';
    /** @var string Result status: the AI failed or is not available. */
    public const STATUS_ERROR = 'error';
    /** @var string Result status: the learner has not answered the questionnaire. */
    public const STATUS_QUESTIONNAIRE = 'questionnaire';

    /** @var candidate_finder Candidate finder. */
    protected candidate_finder $finder;

    /** @var ai_client AI client. */
    protected ai_client $client;

    /**
     * Constructor.
     *
     * @param candidate_finder|null $finder Candidate finder.
     * @param ai_client|null $client AI client, from the DI container by default.
     */
    public function __construct(?candidate_finder $finder = null, ?ai_client $client = null) {
        $this->finder = $finder ?? new candidate_finder();
        $this->client = $client ?? \core\di::get(ai_client::class);
    }

    /**
     * Marks the stored ranking of a user as expired. It is kept as fallback when the daily limit is reached.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function invalidate(int $userid): void {
        global $DB;
        $DB->set_field(self::TABLE, 'timeexpires', 0, ['userid' => $userid]);
    }

    /**
     * Stored ranking of a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function get_stored(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * First page of recommendations for a user. Calls the AI only when the stored ranking cannot be reused.
     *
     * @param int $userid User id.
     * @param bool $forcerefresh Ignore the stored ranking (still subject to the daily limit).
     * @return array Result with status, notice, error, courses, paths, hasmorecourses, hasmorepaths.
     */
    public function get_recommendations(int $userid, bool $forcerefresh = false): array {
        $result = [
            'status' => self::STATUS_OK,
            'notice' => '',
            'error' => '',
            'courses' => [],
            'paths' => [],
            'hasmorecourses' => false,
            'hasmorepaths' => false,
        ];
        if (!answers_manager::has_answers($userid)) {
            $result['status'] = self::STATUS_QUESTIONNAIRE;
            return $result;
        }

        $inputs = $this->build_inputs($userid);
        $stored = self::get_stored($userid);
        $now = \core\di::get(\core\clock::class)->time();

        $ranking = null;
        if (!$forcerefresh && $stored && $this->can_reuse($stored, $inputs, $now)) {
            $ranking = $stored;
        } else if (!$inputs['courses'] && !$inputs['paths']) {
            $result['status'] = self::STATUS_NORESULTS;
            return $result;
        } else if (ai_client::count_user_calls_today($userid) >= config::get_int('dailylimit')) {
            if (!$stored) {
                $result['status'] = self::STATUS_ERROR;
                $result['error'] = get_string('errorlimitnoranking', 'block_aicourserecommender');
                return $result;
            }
            $ranking = $stored;
            $result['notice'] = get_string('limitreached', 'block_aicourserecommender');
        } else if (!$this->client->is_text_available()) {
            $result['status'] = self::STATUS_ERROR;
            $result['error'] = get_string('errornoai', 'block_aicourserecommender');
            return $result;
        } else {
            $ranking = $this->generate($userid, $inputs, ai_client::CALL_RANKING);
            if (!$ranking) {
                $result['status'] = self::STATUS_ERROR;
                $result['error'] = get_string('erroraifailed', 'block_aicourserecommender');
                return $result;
            }
        }

        $courses = $this->filter_items(
            json_decode((string) $ranking->courses, true) ?: [],
            $inputs['candidateids'],
            $inputs['excludedcourses']
        );
        $paths = $this->filter_items(
            json_decode((string) $ranking->paths, true) ?: [],
            array_keys($inputs['candidatepaths']),
            $inputs['excludedpaths']
        );

        if (!$courses && !$paths) {
            $result['status'] = self::STATUS_NORESULTS;
            return $result;
        }
        $pagecourses = array_slice($courses, 0, config::get_int('maxresults'));
        $pagepaths = array_slice($paths, 0, config::get_int('maxpathresults'));
        $result['courses'] = card_exporter::export_courses($pagecourses, $userid, 0);
        $result['paths'] = card_exporter::export_paths($pagepaths, $inputs['candidatepaths'], $userid, 0);
        $result['hasmorecourses'] = count($courses) > count($pagecourses);
        $result['hasmorepaths'] = count($paths) > count($pagepaths);
        $this->log_shown($userid, $result['courses'], $result['paths']);
        return $result;
    }

    /**
     * Next page of the stored ranking. Never calls the AI.
     *
     * @param int $userid User id.
     * @param string $type "course" or "path".
     * @param int $offset Number of items already shown.
     * @return array ['items' => card data, 'hasmore' => bool]
     */
    public function get_more(int $userid, string $type, int $offset): array {
        $stored = self::get_stored($userid);
        if (!$stored) {
            return ['items' => [], 'hasmore' => false];
        }
        if ($type === 'path') {
            $ranked = json_decode((string) $stored->paths, true) ?: [];
            $candidateids = array_keys($this->finder->get_candidates($userid));
            $candidatepaths = path_manager::get_candidate_paths($candidateids);
            $items = $this->filter_items($ranked, array_keys($candidatepaths), feedback_manager::get_excluded_ids($userid, 'path'));
            $pagesize = config::get_int('maxpathresults');
            $page = array_slice($items, $offset, $pagesize);
            $cards = card_exporter::export_paths($page, $candidatepaths, $userid, $offset);
            $this->log_shown($userid, [], $cards);
        } else {
            $ranked = json_decode((string) $stored->courses, true) ?: [];
            $ids = array_map(static fn($item) => (int) $item['id'], $ranked);
            $candidateids = array_keys($this->finder->get_candidates($userid, $ids));
            $items = $this->filter_items($ranked, $candidateids, feedback_manager::get_excluded_ids($userid, 'course'));
            $pagesize = config::get_int('maxresults');
            $page = array_slice($items, $offset, $pagesize);
            $cards = card_exporter::export_courses($page, $userid, $offset);
            $this->log_shown($userid, $cards, []);
        }
        return ['items' => $cards, 'hasmore' => count($items) > $offset + count($page)];
    }

    /**
     * Keeps only ranked items that are still valid, in ranking order.
     *
     * @param array $ranked Ranked items (id, score, reason).
     * @param int[] $validids Ids that are currently candidates.
     * @param int[] $excludedids Ids excluded by negative ratings.
     * @return array[]
     */
    public function filter_items(array $ranked, array $validids, array $excludedids): array {
        $valid = array_flip($validids);
        $excluded = array_flip($excludedids);
        $result = [];
        foreach ($ranked as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (isset($valid[$id]) && !isset($excluded[$id])) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Collects everything needed to build or validate a ranking.
     *
     * @param int $userid User id.
     * @param int[]|null $onlycourseids Restrict the course candidates (incremental ranking).
     * @return array
     */
    public function build_inputs(int $userid, ?array $onlycourseids = null): array {
        $lang = current_language();
        $answers = answers_manager::get_answers($userid);
        $answerlist = [];
        foreach (questions::get_active() as $question) {
            $answerlist[] = [
                'question' => html_to_text($question['text'], 0, false),
                'answer' => $answers[$question['slot']] ?? '',
            ];
        }
        $profile = profile_collector::collect($userid);
        $negative = feedback_manager::get_negative_for_prompt($userid);

        $candidates = $this->finder->get_candidates($userid, $onlycourseids);
        $candidateids = array_keys($candidates);
        $excludedcourses = feedback_manager::get_excluded_ids($userid, 'course');
        $excludedpaths = feedback_manager::get_excluded_ids($userid, 'path');

        $courseids = array_values(array_diff($candidateids, $excludedcourses));
        $max = config::get_int('maxcoursesperprompt');
        if (count($courseids) > $max) {
            $courseids = $this->prefilter($courseids, $answerlist, $profile, $max);
        }
        [$courses, $coursehashes] = $this->get_course_prompt_data($courseids);

        $candidatepaths = [];
        $pathdata = ['items' => [], 'hashes' => []];
        if ($onlycourseids === null) {
            $candidatepaths = path_manager::get_candidate_paths($candidateids);
            $rankable = array_diff_key($candidatepaths, array_flip($excludedpaths));
            $pathdata = path_manager::get_prompt_data($rankable, $candidateids);
        }

        $userhash = sha1(json_encode([
            $answerlist,
            $profile,
            $lang,
            config::get_string('institutionprompt'),
            prompt_builder::PROMPT_VERSION,
        ]));
        ksort($coursehashes);
        $pathhashes = $pathdata['hashes'];
        ksort($pathhashes);

        return [
            'lang' => $lang,
            'answers' => $answerlist,
            'profile' => $profile,
            'negative' => $negative,
            'candidateids' => $candidateids,
            'excludedcourses' => $excludedcourses,
            'excludedpaths' => $excludedpaths,
            'candidatepaths' => $candidatepaths,
            'courses' => $courses,
            'coursehashes' => $coursehashes,
            'paths' => $pathdata['items'],
            'pathhashes' => $pathhashes,
            'userhash' => $userhash,
            'hash' => sha1($userhash . json_encode($coursehashes) . json_encode($pathhashes)),
        ];
    }

    /**
     * Whether a stored ranking can be reused for the current inputs.
     *
     * It can when it has not expired, the learner data, language and prompts did not change, and no new candidate
     * appeared. Candidates that disappeared (closed, finished, enrolled) do not force a new call: they are filtered out.
     *
     * @param \stdClass $stored Stored ranking.
     * @param array $inputs Output of {@see build_inputs()}.
     * @param int $now Current time.
     * @return bool
     */
    public function can_reuse(\stdClass $stored, array $inputs, int $now): bool {
        if ((int) $stored->timeexpires <= $now || $stored->userhash !== $inputs['userhash']) {
            return false;
        }
        if ($stored->hash === $inputs['hash']) {
            return true;
        }
        $oldcourses = json_decode((string) $stored->candidates, true) ?: [];
        foreach ($inputs['coursehashes'] as $id => $hash) {
            if (!isset($oldcourses[$id]) || $oldcourses[$id] !== $hash) {
                return false;
            }
        }
        $oldpaths = json_decode((string) $stored->pathcandidates, true) ?: [];
        foreach ($inputs['pathhashes'] as $id => $hash) {
            if (!isset($oldpaths[$id]) || $oldpaths[$id] !== $hash) {
                return false;
            }
        }
        return true;
    }

    /**
     * Calls the AI and stores the new ranking.
     *
     * @param int $userid User id.
     * @param array $inputs Output of {@see build_inputs()}.
     * @param string $calltype Call type for the log.
     * @return \stdClass|null Stored ranking or null when the AI failed.
     */
    protected function generate(int $userid, array $inputs, string $calltype): ?\stdClass {
        global $DB;
        $parsed = $this->call_ranking($userid, $inputs, $calltype);
        if ($parsed === null) {
            return null;
        }
        $now = \core\di::get(\core\clock::class)->time();
        $record = self::get_stored($userid) ?: (object) ['userid' => $userid, 'timecreated' => $now];
        $record->hash = $inputs['hash'];
        $record->userhash = $inputs['userhash'];
        $record->lang = $inputs['lang'];
        $record->courses = json_encode($parsed['courses'], JSON_UNESCAPED_UNICODE);
        $record->paths = json_encode($parsed['paths'], JSON_UNESCAPED_UNICODE);
        $record->candidates = json_encode((object) $inputs['coursehashes']);
        $record->pathcandidates = json_encode((object) $inputs['pathhashes']);
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->timeexpires = $now + max(60, config::get_int('cachettl'));
        if (!empty($record->id)) {
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->id = $DB->insert_record(self::TABLE, $record);
        }
        return $record;
    }

    /**
     * Sends the ranking prompt and validates the answer, retrying once with a correction prompt.
     *
     * @param int $userid User id.
     * @param array $inputs Output of {@see build_inputs()}.
     * @param string $calltype Call type for the log.
     * @return array|null Parsed ranking.
     */
    public function call_ranking(int $userid, array $inputs, string $calltype): ?array {
        $prompt = prompt_builder::build_ranking_prompt(
            $inputs['profile'],
            $inputs['answers'],
            $inputs['negative'],
            $inputs['courses'],
            $inputs['paths'],
            $inputs['lang']
        );
        $courseids = array_map(static fn($c) => (int) $c['id'], $inputs['courses']);
        $pathids = array_map(static fn($p) => (int) $p['id'], $inputs['paths']);
        $maxcourses = config::get_int('maxranked');
        $maxpaths = config::get_int('maxpaths');

        $response = $this->client->generate_text($prompt, $userid, $calltype);
        if (!$response['success']) {
            return null;
        }
        $parsed = response_parser::parse_ranking($response['text'], $courseids, $pathids, $maxcourses, $maxpaths);
        if ($parsed !== null) {
            return $parsed;
        }
        $retry = $this->client->generate_text(
            prompt_builder::build_correction_prompt($prompt, $response['text']),
            $userid,
            $calltype
        );
        if (!$retry['success']) {
            return null;
        }
        return response_parser::parse_ranking($retry['text'], $courseids, $pathids, $maxcourses, $maxpaths);
    }

    /**
     * Ranks new candidate courses for a learner and merges them into the stored ranking (scheduled task).
     *
     * @param int $userid User id.
     * @param int[] $newcourseids New candidate course ids.
     * @return array[] New ranked items (id, score, reason) that were merged.
     */
    public function rank_new_courses(int $userid, array $newcourseids): array {
        global $DB;
        $stored = self::get_stored($userid);
        if (!$stored || !$newcourseids || !$this->client->is_text_available()) {
            return [];
        }
        $inputs = $this->build_inputs($userid, $newcourseids);
        if (!$inputs['courses']) {
            return [];
        }
        $parsed = $this->call_ranking($userid, $inputs, ai_client::CALL_INCREMENTAL);
        if ($parsed === null) {
            return [];
        }
        $ranked = json_decode((string) $stored->courses, true) ?: [];
        $newids = array_flip(array_map(static fn($i) => $i['id'], $parsed['courses']));
        $ranked = array_values(array_filter($ranked, static fn($i) => !isset($newids[(int) $i['id']])));
        $ranked = array_merge($ranked, $parsed['courses']);
        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        $candidates = json_decode((string) $stored->candidates, true) ?: [];
        foreach ($inputs['coursehashes'] as $id => $hash) {
            $candidates[$id] = $hash;
        }
        ksort($candidates);
        $stored->courses = json_encode(array_slice($ranked, 0, config::get_int('maxranked')), JSON_UNESCAPED_UNICODE);
        $stored->candidates = json_encode((object) $candidates);
        $stored->timemodified = \core\di::get(\core\clock::class)->time();
        $DB->update_record(self::TABLE, $stored);
        return $parsed['courses'];
    }

    /**
     * Compact course data for the prompt and a hash per course.
     *
     * Courses with an AI summary are sent with the summary; the others with their description cut to 600 characters.
     *
     * @param int[] $courseids Course ids.
     * @return array{0: array[], 1: array<int, string>}
     */
    public function get_course_prompt_data(array $courseids): array {
        if (!$courseids) {
            return [[], []];
        }
        $metadata = course_data::get_metadata($courseids, course_data::FALLBACK_DESCRIPTION_LENGTH);
        $summaries = summary_manager::get_summaries($courseids);
        $items = [];
        $hashes = [];
        foreach ($courseids as $courseid) {
            if (!isset($metadata[$courseid])) {
                continue;
            }
            $item = $metadata[$courseid];
            unset($item['shortname'], $item['lang']);
            if (isset($summaries[$courseid]) && trim((string) $summaries[$courseid]->summary) !== '') {
                unset($item['description']);
                $item['summary'] = $summaries[$courseid]->summary;
                $hashes[$courseid] = $summaries[$courseid]->datahash;
            } else {
                $hashes[$courseid] = course_data::hash($metadata[$courseid]);
            }
            $items[] = $item;
        }
        return [$items, $hashes];
    }

    /**
     * Local pre-filter for large catalogues: keeps the courses whose name, tags, summary and category share the most
     * words with the learner answers and profile.
     *
     * @param int[] $courseids Candidate ids.
     * @param array $answers Answers list.
     * @param array $profile Profile data.
     * @param int $max Number of courses to keep.
     * @return int[]
     */
    public function prefilter(array $courseids, array $answers, array $profile, int $max): array {
        global $DB;
        $learnertext = implode(' ', array_column($answers, 'answer')) . ' ' . implode(' ', $profile);
        $words = self::tokenize($learnertext);
        if (!$words) {
            return array_slice($courseids, 0, $max);
        }
        $scores = [];
        $categories = \core_course_category::make_categories_list();
        foreach (array_chunk($courseids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $courses = $DB->get_records_select('course', "id $insql", $params, '', 'id, fullname, category');
            $summaries = summary_manager::get_summaries($chunk);
            $tags = \core_tag_tag::get_items_tags('core', 'course', $chunk);
            foreach ($courses as $course) {
                $text = $course->fullname . ' ' . ($categories[$course->category] ?? '') . ' ' .
                    ($summaries[$course->id]->summary ?? '');
                foreach ($tags[$course->id] ?? [] as $tag) {
                    $text .= ' ' . $tag->rawname;
                }
                $scores[(int) $course->id] = count(array_intersect_key($words, self::tokenize($text)));
            }
        }
        // Stable order: score desc, then course id desc (newer courses first).
        uksort($scores, static fn($a, $b) => [$scores[$b], $b] <=> [$scores[$a], $a]);
        return array_slice(array_keys($scores), 0, $max);
    }

    /**
     * Lower case words of at least 3 characters, as a set.
     *
     * @param string $text Text.
     * @return array<string, true>
     */
    public static function tokenize(string $text): array {
        $text = \core_text::strtolower(html_to_text($text, 0, false));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $set = [];
        foreach ($words as $word) {
            if (\core_text::strlen($word) >= 3) {
                $set[$word] = true;
            }
        }
        return $set;
    }

    /**
     * Records impressions and triggers the viewed event.
     *
     * @param int $userid User id.
     * @param array[] $courses Course cards shown.
     * @param array[] $paths Path cards shown.
     * @return void
     */
    protected function log_shown(int $userid, array $courses, array $paths): void {
        if (!$courses && !$paths) {
            return;
        }
        activity_logger::log_shown($userid, 'course', $courses);
        activity_logger::log_shown($userid, 'path', $paths);
        recommendations_viewed::create([
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => [
                'courseids' => array_map(static fn($c) => (int) $c['id'], $courses),
                'pathids' => array_map(static fn($p) => (int) $p['id'], $paths),
            ],
        ])->trigger();
    }
}
