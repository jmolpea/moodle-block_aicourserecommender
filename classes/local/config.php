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

namespace block_aicourserecommender\local;

/**
 * Typed access to the plugin settings, with the defaults in one place.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string Plugin component. */
    public const COMPONENT = 'block_aicourserecommender';

    /** @var array Default values of the integer settings. */
    public const INT_DEFAULTS = [
        'cachettl' => 86400,
        'dailylimit' => 5,
        'maxcoursesperprompt' => 120,
        'maxresults' => 4,
        'maxranked' => 24,
        'maxpathresults' => 2,
        'maxpaths' => 6,
        'notifythreshold' => 70,
        'negativedays' => 90,
        'showpaths' => 1,
        'notifyenabled' => 1,
        'notifyactivedays' => 90,
        'notifymaxusers' => 200,
        'requireconsent' => 1,
        'categoryfilter' => 0,
        'excludefield' => 0,
        'includehiddenfields' => 0,
        'summariesperrun' => 50,
        'retentiondays' => 365,
    ];

    /** @var string[] Standard profile fields that can be sent to the AI. */
    public const STANDARD_PROFILE_FIELDS = ['city', 'country', 'institution', 'department', 'interests', 'description'];

    /**
     * Returns an integer setting.
     *
     * @param string $name Setting name.
     * @return int
     */
    public static function get_int(string $name): int {
        $value = get_config(self::COMPONENT, $name);
        if ($value === false || $value === '' || $value === null) {
            return self::INT_DEFAULTS[$name] ?? 0;
        }
        return (int) $value;
    }

    /**
     * Returns a string setting.
     *
     * @param string $name Setting name.
     * @return string
     */
    public static function get_string(string $name): string {
        $value = get_config(self::COMPONENT, $name);
        return ($value === false || $value === null) ? '' : (string) $value;
    }

    /**
     * Returns a comma separated list setting as an array.
     *
     * @param string $name Setting name.
     * @param array $default Value used when the setting was never saved.
     * @return string[]
     */
    public static function get_list(string $name, array $default = []): array {
        $value = get_config(self::COMPONENT, $name);
        if ($value === false || $value === null) {
            return $default;
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
    }

    /**
     * Enrolment methods that make a course a candidate.
     *
     * @return string[]
     */
    public static function get_enrol_methods(): array {
        return self::get_list('enrolmethods', ['self']);
    }

    /**
     * Standard profile fields sent to the AI.
     *
     * @return string[]
     */
    public static function get_profile_fields(): array {
        $fields = self::get_list('profilefields', self::STANDARD_PROFILE_FIELDS);
        return array_values(array_intersect($fields, self::STANDARD_PROFILE_FIELDS));
    }

    /**
     * Custom profile field shortnames sent to the AI.
     *
     * @return string[]
     */
    public static function get_custom_profile_fields(): array {
        return self::get_list('customprofilefields');
    }

    /**
     * Course category ids used by the category filter, or null when the filter is off.
     *
     * @return int[]|null
     */
    public static function get_category_filter(): ?array {
        if (!self::get_int('categoryfilter')) {
            return null;
        }
        return array_map('intval', self::get_list('categories'));
    }
}
