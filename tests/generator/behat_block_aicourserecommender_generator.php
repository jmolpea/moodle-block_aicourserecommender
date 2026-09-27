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
 * Behat data generator of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @category   test
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_block_aicourserecommender_generator extends behat_generator_base {
    /**
     * Entities that can be created from Behat.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'paths' => [
                'singular' => 'path',
                'datagenerator' => 'path',
                'required' => ['name', 'courses'],
            ],
            'candidate courses' => [
                'singular' => 'candidate course',
                'datagenerator' => 'candidate_course',
                'required' => ['shortname'],
            ],
        ];
    }
}
