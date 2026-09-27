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

namespace block_aicourserecommender\external;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\profile_collector;
use block_aicourserecommender\local\prompt_builder;
use block_aicourserecommender\local\response_parser;
use block_aicourserecommender\local\summary_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Generates a learning path description with AI in every installed language, wrapped for the multilang filter.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_path_description extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'Path title'),
            'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'Course id'), 'Ordered course ids'),
        ]);
    }

    /**
     * Generates the description.
     *
     * @param string $title Path title.
     * @param int[] $courseids Ordered course ids.
     * @return array
     */
    public static function execute(string $title, array $courseids): array {
        global $DB, $USER;
        ['title' => $title, 'courseids' => $courseids] = self::validate_parameters(
            self::execute_parameters(),
            ['title' => $title, 'courseids' => $courseids]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/aicourserecommender:managepaths', $context);

        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (!$courseids) {
            return ['success' => false, 'description' => '', 'error' => get_string('errornocourses', 'block_aicourserecommender')];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $names = $DB->get_records_select_menu('course', "id $insql", $params, '', 'id, fullname');
        $summaries = summary_manager::get_summaries($courseids);
        $courses = [];
        foreach ($courseids as $courseid) {
            if (!isset($names[$courseid])) {
                continue;
            }
            $coursecontext = \context_course::instance($courseid);
            $summary = $summaries[$courseid]->summary ?? '';
            if ($summary === '') {
                $summary = profile_collector::clean(
                    (string) $DB->get_field('course', 'summary', ['id' => $courseid]),
                    400,
                    $coursecontext
                );
            }
            $courses[] = ['name' => profile_collector::clean($names[$courseid], 255, $coursecontext), 'summary' => $summary];
        }

        $languages = self::get_languages();
        $prompt = prompt_builder::build_path_description_prompt(
            profile_collector::clean($title, 255, $context),
            $courses,
            $languages
        );
        $client = \core\di::get(ai_client::class);
        $result = $client->generate_text($prompt, (int) $USER->id, ai_client::CALL_DESCRIPTION);
        if (!$result['success']) {
            return ['success' => false, 'description' => '', 'error' => get_string('erroraifailed', 'block_aicourserecommender')];
        }
        $data = response_parser::decode_json_object($result['text']);
        $texts = [];
        foreach (array_keys($languages) as $lang) {
            if (isset($data[$lang]) && is_string($data[$lang]) && trim($data[$lang]) !== '') {
                $texts[$lang] = trim(clean_param($data[$lang], PARAM_TEXT));
            }
        }
        if (!$texts) {
            if (count($languages) === 1 && trim($result['text']) !== '' && $data === null) {
                $texts[array_key_first($languages)] = trim(clean_param($result['text'], PARAM_TEXT));
            } else {
                return ['success' => false, 'description' => '',
                    'error' => get_string('erroraifailed', 'block_aicourserecommender')];
            }
        }
        return ['success' => true, 'description' => self::build_html($texts), 'error' => ''];
    }

    /**
     * Installed languages as code => English name.
     *
     * @return array<string, string>
     */
    public static function get_languages(): array {
        $languages = [];
        $names = get_string_manager()->get_list_of_languages('en');
        foreach (array_keys(get_string_manager()->get_list_of_translations()) as $code) {
            $parent = explode('_', $code)[0];
            $languages[$code] = $names[$code] ?? ($names[$parent] ?? $code);
        }
        return $languages;
    }

    /**
     * HTML description: one paragraph, one multilang span per language when there are several.
     *
     * @param string[] $texts Text keyed by language code.
     * @return string
     */
    public static function build_html(array $texts): string {
        if (count($texts) === 1) {
            return \html_writer::tag('p', s(reset($texts)));
        }
        $spans = '';
        foreach ($texts as $lang => $text) {
            $spans .= \html_writer::tag('span', s($text), ['lang' => $lang, 'class' => 'multilang']);
        }
        return \html_writer::tag('p', $spans);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Generated'),
            'description' => new external_value(PARAM_RAW, 'HTML description'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
        ]);
    }
}
