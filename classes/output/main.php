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

namespace block_aicourserecommender\output;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\local\config;
use block_aicourserecommender\local\questions;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;

/**
 * Main content of the block: consent, AI policy and questionnaire. Results are loaded by JavaScript.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class main implements renderable, templatable {
    /** @var string Unique id of the block container. */
    protected string $uniqid;

    /**
     * Constructor.
     *
     * @param int $userid Current user id.
     * @param ai_client $client AI client.
     */
    public function __construct(
        /** @var int Current user id. */
        protected int $userid,
        /** @var ai_client AI client. */
        protected ai_client $client,
    ) {
        $this->uniqid = \html_writer::random_id('aicr');
    }

    /**
     * Unique id of the block container.
     *
     * @return string
     */
    public function get_uniqid(): string {
        return $this->uniqid;
    }

    /**
     * Initial state of the block.
     *
     * @return string consent, aipolicy, questionnaire or loading.
     */
    public function get_state(): string {
        if (answers_manager::needs_consent($this->userid)) {
            return 'consent';
        }
        if (!$this->client->has_accepted_policy($this->userid)) {
            return 'aipolicy';
        }
        if (!answers_manager::has_answers($this->userid)) {
            return 'questionnaire';
        }
        return 'loading';
    }

    /**
     * Template data.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $system = \context_system::instance();
        $state = $this->get_state();
        $answers = answers_manager::get_answers($this->userid);

        $questions = [];
        foreach (questions::get_active($system) as $question) {
            $questions[] = [
                'slot' => $question['slot'],
                'text' => $question['text'],
                'help' => $question['help'],
                'hashelp' => $question['help'] !== '',
                'answer' => $answers[$question['slot']] ?? '',
            ];
        }

        $consenttext = trim(config::get_string('consenttext'));
        if ($consenttext === '') {
            $consenttext = get_string('consenttextdefault', 'block_aicourserecommender');
        }

        return [
            'uniqid' => $this->uniqid,
            'state' => $state,
            'isconsent' => $state === 'consent',
            'isaipolicy' => $state === 'aipolicy',
            'isquestionnaire' => $state === 'questionnaire',
            'isloading' => $state === 'loading',
            'policyaccepted' => $this->client->has_accepted_policy($this->userid),
            'hasanswers' => answers_manager::has_answers($this->userid),
            'consenttext' => format_text($consenttext, FORMAT_HTML, ['context' => $system]),
            'questions' => $questions,
            'maxlength' => questions::MAX_ANSWER_LENGTH,
            'speechlang' => str_replace('_', '-', current_language()),
            'catalogurl' => (new \moodle_url('/course/index.php'))->out(false),
            'requireconsent' => (bool) config::get_int('requireconsent'),
        ];
    }
}
