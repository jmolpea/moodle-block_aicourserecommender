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
 * Landing page of a learning path.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_aicourserecommender\event\path_viewed;
use block_aicourserecommender\local\path_manager;
use block_aicourserecommender\output\path_page;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('Guests cannot view learning paths');
}
$context = context_system::instance();
require_capability('block/aicourserecommender:use', $context);

$path = path_manager::get_path($id, MUST_EXIST);
if (!$path->visible && !has_capability('block/aicourserecommender:managepaths', $context)) {
    throw new moodle_exception('errorpathnotfound', 'block_aicourserecommender');
}
$name = path_manager::format_name($path);

$url = new moodle_url('/blocks/aicourserecommender/path.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title($name);
$PAGE->set_heading(format_string($SITE->fullname, true, ['context' => $context]));
$PAGE->navbar->add(get_string('learningpaths', 'block_aicourserecommender'));
$PAGE->navbar->add($name, $url);
$PAGE->add_body_class('block-aicourserecommender-pathpage');

path_viewed::create([
    'objectid' => $path->id,
    'context' => $context,
    'relateduserid' => $USER->id,
])->trigger();

$renderer = $PAGE->get_renderer('block_aicourserecommender');
$page = new path_page($path, (int) $USER->id);
$content = $renderer->render($page);
$PAGE->requires->js_call_amd('block_aicourserecommender/path', 'init', [(int) $path->id]);

echo $OUTPUT->header();
echo $content;
echo $OUTPUT->footer();
