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

/**
 * Create or edit a learning path.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_aicourserecommender\form\path_form;
use block_aicourserecommender\local\path_manager;

require_once(__DIR__ . '/../../config.php');

$id = optional_param('id', 0, PARAM_INT);

require_login(null, false);
$context = context_system::instance();
require_capability('block/aicourserecommender:managepaths', $context);

$component = 'block_aicourserecommender';
$url = new moodle_url('/blocks/aicourserecommender/editpath.php', ['id' => $id]);
$returnurl = new moodle_url('/blocks/aicourserecommender/managepaths.php');
$title = get_string($id ? 'editpath' : 'addpath', $component);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($SITE->fullname, true, ['context' => $context]));
$PAGE->navbar->add(get_string('managepaths', $component), $returnurl);
$PAGE->navbar->add($title);

$data = new stdClass();
$data->id = 0;
$data->description = '';
$data->descriptionformat = FORMAT_HTML;
$data->visible = 1;
if ($id) {
    $data = clone path_manager::get_path($id, MUST_EXIST);
    $data->courses = path_manager::get_course_ids($id);
    $data->courseorder = implode(',', $data->courses);
}
$data = file_prepare_standard_editor(
    $data,
    'description',
    path_form::editor_options(),
    $context,
    $component,
    'pathdescription',
    $id ?: null
);
$data = file_prepare_standard_filemanager(
    $data,
    'image',
    path_form::image_options(),
    $context,
    $component,
    path_manager::FILEAREA,
    $id ?: null
);

$form = new path_form($url);
$form->set_data($data);

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($formdata = $form->get_data()) {
    $record = (object) [
        'id' => $id,
        'name' => $formdata->name,
        'description' => $formdata->description_editor['text'] ?? '',
        'descriptionformat' => $formdata->description_editor['format'] ?? FORMAT_HTML,
        'visible' => $formdata->visible,
    ];
    $pathid = path_manager::save_path($record, path_form::get_ordered_courses($formdata));
    file_save_draft_area_files(
        $formdata->image_filemanager,
        $context->id,
        $component,
        path_manager::FILEAREA,
        $pathid,
        path_form::image_options()
    );

    // An AI generated image replaces the uploaded one. It must be in the draft area of the current user.
    if (!empty($formdata->aiimage) && preg_match('~^(\d+)/(.+)$~', $formdata->aiimage, $matches)) {
        $fs = get_file_storage();
        $draft = $fs->get_file(
            context_user::instance($USER->id)->id,
            'user',
            'draft',
            (int) $matches[1],
            '/',
            clean_param($matches[2], PARAM_FILE)
        );
        if ($draft && $draft->is_valid_image()) {
            $fs->delete_area_files($context->id, $component, path_manager::FILEAREA, $pathid);
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => $component,
                'filearea' => path_manager::FILEAREA,
                'itemid' => $pathid,
                'filepath' => '/',
                'filename' => $draft->get_filename(),
            ], $draft);
        }
    }
    redirect($returnurl, get_string('pathsaved', $component), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->requires->js_call_amd('block_aicourserecommender/pathform', 'init', []);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
