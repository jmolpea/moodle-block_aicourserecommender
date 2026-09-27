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

namespace block_aicourserecommender\form;

use block_aicourserecommender\local\ai_client;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Learning path edit form.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_form extends \moodleform {
    /**
     * Editor options of the description.
     *
     * @return array
     */
    public static function editor_options(): array {
        return ['maxfiles' => 0, 'noclean' => false, 'context' => \context_system::instance()];
    }

    /**
     * File manager options of the image.
     *
     * @return array
     */
    public static function image_options(): array {
        return [
            'maxfiles' => 1,
            'subdirs' => 0,
            // Raster images only: SVG can contain scripts.
            'accepted_types' => ['.jpg', '.jpeg', '.png', '.gif', '.webp'],
            'maxbytes' => 0,
        ];
    }

    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        global $OUTPUT;
        $mform = $this->_form;
        $component = 'block_aicourserecommender';
        $client = \core\di::get(ai_client::class);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('pathname', $component), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 1333), 'maxlength', 1333, 'client');
        $mform->addHelpButton('name', 'pathname', $component);

        $mform->addElement('course', 'courses', get_string('pathcourses', $component), [
            'multiple' => true,
            'includefrontpage' => false,
        ]);
        $mform->addRule('courses', null, 'required', null, 'client');
        $mform->addHelpButton('courses', 'pathcourses', $component);

        $mform->addElement('hidden', 'courseorder', '');
        $mform->setType('courseorder', PARAM_SEQUENCE);
        $mform->addElement(
            'static',
            'courseorderui',
            get_string('pathcourseorder', $component),
            $OUTPUT->render_from_template('block_aicourserecommender/path_form_order', [])
        );

        $mform->addElement(
            'editor',
            'description_editor',
            get_string('pathdescription', $component),
            ['rows' => 8],
            self::editor_options()
        );
        $mform->setType('description_editor', PARAM_RAW);
        $available = $client->is_text_available();
        $mform->addElement(
            'static',
            'aidescription',
            '',
            $OUTPUT->render_from_template('block_aicourserecommender/path_form_ai', [
                'action' => 'generate-description',
                'label' => get_string('generatedescription', $component),
                'available' => $available,
                'unavailable' => get_string('errornoai', $component),
            ])
        );

        $mform->addElement(
            'filemanager',
            'image_filemanager',
            get_string('pathimage', $component),
            null,
            self::image_options()
        );
        $mform->addElement('hidden', 'aiimage', '');
        $mform->setType('aiimage', PARAM_RAW_TRIMMED);
        if ($client->is_image_available()) {
            $mform->addElement(
                'static',
                'aiimagebutton',
                '',
                $OUTPUT->render_from_template('block_aicourserecommender/path_form_ai', [
                    'action' => 'generate-image',
                    'label' => get_string('generateimage', $component),
                    'available' => true,
                    'isimage' => true,
                ])
            );
        }

        $mform->addElement('advcheckbox', 'visible', get_string('visible'));
        $mform->setDefault('visible', 1);

        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        $courseids = array_filter(array_map('intval', (array) ($data['courses'] ?? [])));
        if (!$courseids) {
            $errors['courses'] = get_string('errornocourses', 'block_aicourserecommender');
        } else {
            [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            if ($DB->record_exists_select('course', "id $insql AND visible = 0", $params)) {
                $errors['courses'] = get_string('errorhiddencourse', 'block_aicourserecommender');
            }
        }
        return $errors;
    }

    /**
     * Ordered course ids from the submitted data: the order chosen by the user, then any remaining course.
     *
     * @param \stdClass $data Submitted data.
     * @return int[]
     */
    public static function get_ordered_courses(\stdClass $data): array {
        $selected = array_values(array_filter(array_map('intval', (array) ($data->courses ?? []))));
        $order = array_filter(array_map('intval', explode(',', (string) ($data->courseorder ?? ''))));
        $result = [];
        foreach ($order as $courseid) {
            if (in_array($courseid, $selected, true) && !in_array($courseid, $result, true)) {
                $result[] = $courseid;
            }
        }
        foreach ($selected as $courseid) {
            if (!in_array($courseid, $result, true)) {
                $result[] = $courseid;
            }
        }
        return $result;
    }
}
