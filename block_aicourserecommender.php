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

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\output\main;

/**
 * AI course recommender block.
 *
 * The block never calls the AI while the page is rendered: it prints a container and an AMD module loads the
 * recommendations through AJAX.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_aicourserecommender extends block_base {
    /**
     * Initialises the block.
     *
     * @return void
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_aicourserecommender');
    }

    /**
     * Pages where the block can be added: site front page and Dashboard only.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'all' => false,
            'site-index' => true,
            'my' => true,
        ];
    }

    /**
     * Only one instance per page.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * The block has site settings.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Returns the block content.
     *
     * @return stdClass
     */
    public function get_content() {
        global $USER;

        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }
        $systemcontext = context_system::instance();
        if (!has_capability('block/aicourserecommender:use', $systemcontext)) {
            return $this->content;
        }

        $renderer = $this->page->get_renderer('block_aicourserecommender');
        $client = \core\di::get(ai_client::class);
        if (!$client->is_text_available()) {
            if (has_capability('moodle/site:config', $systemcontext)) {
                $this->content->text = $renderer->render_no_provider_notice();
            }
            return $this->content;
        }

        $main = new main((int) $USER->id, $client);
        $this->content->text = $renderer->render($main);
        $this->page->requires->js_call_amd('block_aicourserecommender/block', 'init', [$main->get_uniqid()]);
        return $this->content;
    }

    /**
     * Returns the plugin settings that are safe to expose to external clients.
     *
     * @return stdClass
     */
    public function get_config_for_external() {
        $config = get_config('block_aicourserecommender');
        return (object) [
            'instance' => new stdClass(),
            'plugin' => (object) [
                'maxresults' => $config->maxresults ?? 4,
                'maxpathresults' => $config->maxpathresults ?? 2,
                'showpaths' => $config->showpaths ?? 1,
            ],
        ];
    }
}
