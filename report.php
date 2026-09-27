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
 * Usage report of the AI course recommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_aicourserecommender\form\report_filter_form;
use block_aicourserecommender\local\report;
use block_aicourserecommender\table\ailog_table;
use block_aicourserecommender\table\items_table;

require_once(__DIR__ . '/../../config.php');

$now = time();
$from = optional_param('from', usergetmidnight($now - 30 * DAYSECS), PARAM_INT);
$to = optional_param('to', $now, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$tablename = optional_param('table', '', PARAM_ALPHA);

require_login(null, false);
$context = context_system::instance();
require_capability('block/aicourserecommender:viewreports', $context);

$component = 'block_aicourserecommender';
$title = get_string('report', $component);

$form = new report_filter_form(new moodle_url('/blocks/aicourserecommender/report.php'));
if ($data = $form->get_data()) {
    $from = usergetmidnight($data->from);
    $to = usergetmidnight($data->to) + DAYSECS - 1;
} else {
    $form->set_data(['from' => $from, 'to' => $to]);
}

$url = new moodle_url('/blocks/aicourserecommender/report.php', ['from' => $from, 'to' => $to]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading(format_string($SITE->fullname, true, ['context' => $context]));
$PAGE->navbar->add($title, $url);

$tables = [
    'course' => new items_table('course', $from, $to, $url),
    'path' => new items_table('path', $from, $to, $url),
    'ailog' => new ailog_table($from, $to, $url, true),
];
$filenames = [
    'course' => 'aicourserecommender_courses',
    'path' => 'aicourserecommender_paths',
    'ailog' => 'aicourserecommender_ailog',
];

if ($download !== '' && isset($tables[$tablename])) {
    $table = $tables[$tablename];
    $table->is_downloading($download, $filenames[$tablename] . '_' . userdate($from, '%Y%m%d') . '_' . userdate($to, '%Y%m%d'));
    $table->out(100, false);
    die();
}

$totals = report::get_totals($from, $to);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->render_from_template('block_aicourserecommender/report_totals', ['totals' => $totals]);

foreach (['course' => 'report_topcourses', 'path' => 'report_toppaths', 'ailog' => 'report_ailog'] as $key => $heading) {
    echo $OUTPUT->heading(get_string($heading, $component), 3);
    $tables[$key]->show_download_buttons_at([TABLE_P_BOTTOM]);
    $tables[$key]->is_downloadable(true);
    $tables[$key]->out(25, false);
}

echo $OUTPUT->footer();
