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
 * External functions of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_aicourserecommender_get_recommendations' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Returns the first page of AI course and learning path recommendations of the current user.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_get_more' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Returns the next page of the stored ranking without calling the AI.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_save_answers' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Saves the questionnaire answers of the current user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_save_consent' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Records that the current user accepted the privacy notice.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_submit_feedback' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Saves a rating of a recommended course or learning path.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_log_click' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Records a click on a recommended course or learning path.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_enrol_course' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Enrols the current user in a recommended course with direct self enrolment.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_enrol_path' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Enrols the current user in every available course of a learning path.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:use',
    ],
    'block_aicourserecommender_generate_path_description' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Generates a learning path description with AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:managepaths',
    ],
    'block_aicourserecommender_generate_path_image' => [
        'classname' => 'block_aicourserecommender\external$1',
        'description' => 'Generates a learning path image with AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/aicourserecommender:managepaths',
    ],
];
