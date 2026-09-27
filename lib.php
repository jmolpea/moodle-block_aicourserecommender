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
 * Library callbacks of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves the learning path images.
 *
 * @param stdClass $course Course (site course).
 * @param stdClass|null $birecord Block instance record (not used, images live in the system context).
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Remaining path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Send options.
 * @return void|false
 */
function block_aicourserecommender_pluginfile(
    $course,
    $birecord,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== 'pathimage') {
        return false;
    }
    require_login();
    $pathid = (int) array_shift($args);
    $path = \block_aicourserecommender\local\path_manager::get_path($pathid);
    if (!$path || (!$path->visible && !has_capability('block/aicourserecommender:managepaths', $context))) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'block_aicourserecommender', $filearea, $pathid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}
