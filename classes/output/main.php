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
     * @return string wizard (first use, or consent or AI policy pending) or loading (returning learner).
     */
    public function get_state(): string {
        if ($this->needs_acceptance() || !answers_manager::has_answers($this->userid)) {
            return 'wizard';
        }
        return 'loading';
    }

    /**
     * Whether the learner must still accept the notice or the AI usage policy of the site.
     *
     * @return bool
     */
    protected function needs_acceptance(): bool {
        return answers_manager::needs_consent($this->userid) || !$this->client->has_accepted_policy($this->userid);
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

        $active = questions::get_active($system);
        $total = count($active);
        $questions = [];
        foreach (array_values($active) as $index => $question) {
            $answer = $answers[$question['slot']] ?? '';
            $progress = ['current' => $index + 1, 'total' => $total];
            $questions[] = [
                'slot' => $question['slot'],
                'number' => $index + 1,
                'text' => $question['text'],
                'help' => $question['help'],
                'hashelp' => $question['help'] !== '',
                'answer' => $answer,
                'hasanswer' => trim($answer) !== '',
                'isfirst' => $index === 0,
                'islast' => $index === $total - 1,
                'progress' => get_string('questionprogress', 'block_aicourserecommender', $progress),
                'percent' => (int) round(($index + 1) * 100 / max(1, $total)),
            ];
        }

        $requireconsent = (bool) config::get_int('requireconsent');
        $consenttext = trim(config::get_string('consenttext'));
        if ($consenttext === '') {
            $consenttext = get_string('consenttextdefault', 'block_aicourserecommender');
        }
        $policyaccepted = $this->client->has_accepted_policy($this->userid);

        return [
            'uniqid' => $this->uniqid,
            'state' => $state,
            'iswizard' => $state === 'wizard',
            'isloading' => $state === 'loading',
            'policyaccepted' => $policyaccepted,
            'needsconsent' => answers_manager::needs_consent($this->userid),
            'showacceptance' => $this->needs_acceptance(),
            'hasanswers' => answers_manager::has_answers($this->userid),
            'showconsenttext' => $requireconsent,
            'consenttext' => format_text($consenttext, FORMAT_HTML, ['context' => $system]),
            'questions' => $questions,
            'totalquestions' => $total,
            'maxlength' => questions::MAX_ANSWER_LENGTH,
            'speechlang' => str_replace('_', '-', current_language()),
            'catalogurl' => (new \moodle_url('/course/index.php'))->out(false),
            'requireconsent' => $requireconsent,
        ];
    }
}
