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

namespace block_aicourserecommender\admin;

use block_aicourserecommender\local\questions;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting that edits the six questionnaire questions together, so "at least one active question" can be
 * validated on save.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_questions extends \admin_setting {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'block_aicourserecommender/questions',
            new \lang_string('questions', 'block_aicourserecommender'),
            new \lang_string('questions_desc', 'block_aicourserecommender'),
            questions::get_default_config()
        );
    }

    /**
     * Current value.
     *
     * @return array|null
     */
    public function get_setting() {
        $value = $this->config_read($this->name);
        if ($value === null) {
            return null;
        }
        return questions::get_config();
    }

    /**
     * Validates and saves the questions.
     *
     * @param mixed $data Submitted data keyed by slot.
     * @return string Empty on success, error message otherwise.
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return get_string('errorsetting', 'admin');
        }
        $config = [];
        for ($slot = 1; $slot <= questions::MAX_QUESTIONS; $slot++) {
            $question = is_array($data[$slot] ?? null) ? $data[$slot] : [];
            $config[$slot] = [
                'text' => trim(clean_param((string) ($question['text'] ?? ''), PARAM_RAW_TRIMMED)),
                'help' => trim(clean_param((string) ($question['help'] ?? ''), PARAM_RAW_TRIMMED)),
                'enabled' => empty($question['enabled']) ? 0 : 1,
                'sortorder' => max(1, min(questions::MAX_QUESTIONS, (int) ($question['sortorder'] ?? $slot))),
            ];
        }
        $error = questions::validate_config($config);
        if ($error !== '') {
            return $error;
        }
        return $this->config_write($this->name, json_encode($config, JSON_UNESCAPED_UNICODE)) ? '' :
            get_string('errorsetting', 'admin');
    }

    /**
     * Renders the questions editor.
     *
     * @param mixed $data Current value.
     * @param string $query Admin search query.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;
        $data = is_array($data) ? $data : questions::get_default_config();
        $default = $this->get_defaultsetting();
        $rows = [];
        for ($slot = 1; $slot <= questions::MAX_QUESTIONS; $slot++) {
            $question = $data[$slot] ?? $default[$slot];
            $prefix = $this->get_full_name() . '[' . $slot . ']';
            $idprefix = $this->get_id() . '_' . $slot;
            $orderoptions = [];
            for ($i = 1; $i <= questions::MAX_QUESTIONS; $i++) {
                $orderoptions[] = ['value' => $i, 'label' => $i, 'selected' => (int) $question['sortorder'] === $i];
            }
            $rows[] = [
                'slot' => $slot,
                'idprefix' => $idprefix,
                'nameprefix' => $prefix,
                'text' => $question['text'],
                'help' => $question['help'],
                'enabled' => !empty($question['enabled']),
                'orderoptions' => $orderoptions,
                'hasdefault' => questions::has_default_text($slot),
                'defaulttext' => questions::has_default_text($slot) ?
                    get_string('question' . $slot, 'block_aicourserecommender') : '',
            ];
        }
        $element = $OUTPUT->render_from_template('block_aicourserecommender/setting_questions', ['questions' => $rows]);
        return format_admin_setting($this, $this->visiblename, $element, $this->description, false, '', null, $query);
    }
}
