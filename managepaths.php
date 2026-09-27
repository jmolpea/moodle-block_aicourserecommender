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
 * Learning paths management.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_aicourserecommender\local\path_manager;

require_once(__DIR__ . '/../../config.php');

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

require_login(null, false);
$context = context_system::instance();
require_capability('block/aicourserecommender:managepaths', $context);

$component = 'block_aicourserecommender';
$url = new moodle_url('/blocks/aicourserecommender/managepaths.php');
$title = get_string('managepaths', $component);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($SITE->fullname, true, ['context' => $context]));
$PAGE->navbar->add($title, $url);

if ($action && $id) {
    $path = path_manager::get_path($id, MUST_EXIST);
    if ($action === 'delete') {
        if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
            path_manager::delete_path($id);
            redirect($url, get_string('pathdeleted', $component), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmdeletepath', $component, path_manager::format_name($path)),
            new moodle_url($url, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
            $url
        );
        echo $OUTPUT->footer();
        die();
    }
    require_sesskey();
    switch ($action) {
        case 'hide':
            path_manager::set_visible($id, false);
            break;
        case 'show':
            path_manager::set_visible($id, true);
            break;
        case 'up':
            path_manager::move($id, -1);
            break;
        case 'down':
            path_manager::move($id, 1);
            break;
    }
    redirect($url);
}

$paths = array_values(path_manager::get_paths());
$courses = path_manager::get_courses_of_paths(array_map(static fn($p) => (int) $p->id, $paths));
$rows = [];
foreach ($paths as $index => $path) {
    $actionurl = static fn(string $name): string =>
        (new moodle_url($url, ['action' => $name, 'id' => $path->id, 'sesskey' => sesskey()]))->out(false);
    $rows[] = [
        'id' => (int) $path->id,
        'name' => path_manager::format_name($path),
        'visible' => (bool) $path->visible,
        'coursecount' => count($courses[$path->id] ?? []),
        'viewurl' => (new moodle_url('/blocks/aicourserecommender/path.php', ['id' => $path->id]))->out(false),
        'editurl' => (new moodle_url('/blocks/aicourserecommender/editpath.php', ['id' => $path->id]))->out(false),
        'hideurl' => $actionurl('hide'),
        'showurl' => $actionurl('show'),
        'deleteurl' => (new moodle_url($url, ['action' => 'delete', 'id' => $path->id]))->out(false),
        'upurl' => $index > 0 ? $actionurl('up') : '',
        'downurl' => $index < count($paths) - 1 ? $actionurl('down') : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template('block_aicourserecommender/managepaths', [
    'paths' => $rows,
    'haspaths' => !empty($rows),
    'addurl' => (new moodle_url('/blocks/aicourserecommender/editpath.php'))->out(false),
    'reporturl' => (new moodle_url('/blocks/aicourserecommender/report.php'))->out(false),
    'canreport' => has_capability('block/aicourserecommender:viewreports', $context),
    'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'blocksettingaicourserecommender']))->out(false),
    'cansettings' => has_capability('moodle/site:config', $context),
]);
echo $OUTPUT->footer();
