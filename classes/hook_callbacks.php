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

namespace block_aicourserecommender;

use block_aicourserecommender\local\ai_client;

/**
 * Hook callbacks.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Replaces the AI client with a fake one on Behat test sites only, so acceptance tests never call a provider.
     *
     * @param \core\hook\di_configuration $hook Hook.
     * @return void
     */
    public static function di_configuration(\core\hook\di_configuration $hook): void {
        global $CFG;
        if (!defined('BEHAT_SITE_RUNNING') || !get_config('block_aicourserecommender', 'behatfakeai')) {
            return;
        }
        require_once($CFG->dirroot . '/blocks/aicourserecommender/tests/fixtures/fake_ai_client.php');
        $hook->add_definition(ai_client::class, static fn() => new \block_aicourserecommender\tests\fake_ai_client());
    }
}
