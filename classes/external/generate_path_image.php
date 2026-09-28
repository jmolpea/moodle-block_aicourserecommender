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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Generates a learning path image with AI. The image stays in the user draft area until the path form is saved.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_path_image extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'title' => new external_value(PARAM_TEXT, 'Path title'),
            'description' => new external_value(PARAM_RAW, 'Path description (HTML)'),
        ]);
    }

    /**
     * Generates the image.
     *
     * @param string $title Path title.
     * @param string $description Path description.
     * @return array
     */
    public static function execute(string $title, string $description): array {
        global $USER;
        ['title' => $title, 'description' => $description] = self::validate_parameters(
            self::execute_parameters(),
            ['title' => $title, 'description' => $description]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/aicourserecommender:managepaths', $context);

        $empty = ['success' => false, 'drafturl' => '', 'draftitemid' => 0, 'filename' => ''];
        $client = \core\di::get(ai_client::class);
        helper::require_ai_policy($client);
        if (!$client->is_image_available()) {
            return $empty + ['error' => get_string('errornoimageai', 'block_aicourserecommender')];
        }
        $prompt = prompt_builder::build_image_prompt(
            profile_collector::clean($title, 255, $context),
            profile_collector::clean($description, 600, $context)
        );
        $result = $client->generate_image($prompt, (int) $USER->id);
        if (!$result['success'] || !$result['file']) {
            // Managers see the reason given by the provider (for example an unsupported model or parameter).
            $reason = rtrim(\core_text::substr(clean_param((string) $result['error'], PARAM_TEXT), 0, 300), '. ');
            return $empty + ['error' => get_string('errorimagefailed', 'block_aicourserecommender', $reason)];
        }
        /** @var \stored_file $file */
        $file = $result['file'];
        $url = \moodle_url::make_draftfile_url($file->get_itemid(), $file->get_filepath(), $file->get_filename(), false);
        return [
            'success' => true,
            'drafturl' => $url->out(false),
            'draftitemid' => (int) $file->get_itemid(),
            'filename' => $file->get_filename(),
            'error' => '',
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Generated'),
            'drafturl' => new external_value(PARAM_URL, 'Preview URL of the draft image'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id of the image'),
            'filename' => new external_value(PARAM_FILE, 'File name of the image'),
            'error' => new external_value(PARAM_TEXT, 'Error message'),
        ]);
    }
}
