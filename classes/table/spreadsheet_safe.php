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

namespace block_aicourserecommender\table;

/**
 * Protects downloaded report cells against spreadsheet formula injection.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait spreadsheet_safe {
    /**
     * Prefixes a value with an apostrophe when a spreadsheet would read it as a formula.
     *
     * Course names, user names and provider errors are written by other people and end up in CSV or Excel files
     * opened by administrators.
     *
     * @param string $value Cell value.
     * @return string
     */
    public static function spreadsheet_safe(string $value): string {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
