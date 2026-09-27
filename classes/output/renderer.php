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

namespace block_aicourserecommender\output;

use core\output\plugin_renderer_base;

/**
 * Renderer of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Renders the main block content.
     *
     * @param main $main Renderable.
     * @return string
     */
    protected function render_main(main $main): string {
        return $this->render_from_template('block_aicourserecommender/main', $main->export_for_template($this));
    }

    /**
     * Renders the learning path landing page.
     *
     * @param path_page $page Renderable.
     * @return string
     */
    protected function render_path_page(path_page $page): string {
        return $this->render_from_template('block_aicourserecommender/path_page', $page->export_for_template($this));
    }

    /**
     * Notice shown to administrators when no AI provider offers text generation.
     *
     * @return string
     */
    public function render_no_provider_notice(): string {
        $url = new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']);
        return $this->render_from_template('block_aicourserecommender/noprovider', ['url' => $url->out(false)]);
    }
}
