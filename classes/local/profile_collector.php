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
 * Collects the learner profile data that may be sent to the AI.
 *
 * Never returns names, email, username, idnumber, phone, address, IP or any id.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_collector {
    /** @var int Maximum length of each profile field. */
    public const MAX_FIELD_LENGTH = 500;

    /** @var int Maximum length of the profile description. */
    public const MAX_DESCRIPTION_LENGTH = 1500;

    /**
     * Returns the profile data of a user as label => value, only non-empty values.
     *
     * @param int $userid User id.
     * @return array<string, string> Keys are English labels, so the prompt stays neutral.
     */
    public static function collect(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $context = \context_user::instance($userid);
        $data = [];

        foreach (config::get_profile_fields() as $field) {
            switch ($field) {
                case 'city':
                case 'institution':
                case 'department':
                    $data[$field] = self::clean((string) $user->$field, self::MAX_FIELD_LENGTH, $context);
                    break;
                case 'country':
                    $countries = get_string_manager()->get_list_of_countries(true, 'en');
                    $data['country'] = $user->country ? ($countries[$user->country] ?? $user->country) : '';
                    break;
                case 'interests':
                    $tags = \core_tag_tag::get_item_tags_array('core', 'user', $userid);
                    $data['interests'] = self::clean(implode(', ', $tags), self::MAX_FIELD_LENGTH, $context);
                    break;
                case 'description':
                    $description = file_rewrite_pluginfile_urls(
                        (string) $user->description,
                        'pluginfile.php',
                        $context->id,
                        'user',
                        'profile',
                        null
                    );
                    $description = format_text($description, $user->descriptionformat, ['context' => $context]);
                    $data['profile description'] = self::clean($description, self::MAX_DESCRIPTION_LENGTH, $context);
                    break;
            }
        }

        $custom = config::get_custom_profile_fields();
        if ($custom) {
            foreach (profile_get_user_fields_with_data($userid) as $formfield) {
                if (!in_array($formfield->field->shortname, $custom, true) || $formfield->is_empty()) {
                    continue;
                }
                $label = format_string($formfield->field->name, true, ['context' => \context_system::instance()]);
                $value = $formfield->display_data();
                $data[self::clean($label, 100, $context)] = self::clean((string) $value, self::MAX_FIELD_LENGTH, $context);
            }
        }

        foreach ($data as $label => $value) {
            $data[$label] = self::redact($value, $user);
        }
        $data['language'] = self::get_language_name(current_language());

        return array_filter($data, static fn($value) => $value !== '');
    }

    /**
     * Removes personal identifiers from free text before it is sent to the AI: email addresses, web addresses,
     * phone-like numbers and the names, username and email of the user.
     *
     * @param string $text Text.
     * @param \stdClass|null $user User whose identifiers are removed.
     * @return string
     */
    public static function redact(string $text, ?\stdClass $user = null): string {
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/[^\s@<>()]+@[^\s@<>()]+\.[a-z]{2,}/iu', '[email]', $text);
        $text = preg_replace('~\b(?:https?://|www\.)\S+~iu', '[link]', $text);
        // Phone and document numbers: 9 or more digits, with the usual separators (years like 2020-2024 are kept).
        $text = preg_replace_callback('/\+?\d[\d\s().\/-]{6,}\d/u', static function (array $match): string {
            return preg_match_all('/\d/', $match[0]) >= 9 ? '[number]' : $match[0];
        }, $text);
        if ($user) {
            $identifiers = [];
            foreach (['firstname', 'lastname', 'middlename', 'alternatename', 'username', 'idnumber'] as $field) {
                foreach (preg_split('/\s+/u', trim((string) ($user->$field ?? ''))) as $part) {
                    if (\core_text::strlen($part) >= 3) {
                        $identifiers[] = preg_quote($part, '/');
                    }
                }
            }
            if ($identifiers) {
                $text = preg_replace(
                    '/(?<![\p{L}\p{N}])(?:' . implode('|', array_unique($identifiers)) . ')(?![\p{L}\p{N}])/iu',
                    '[name]',
                    $text
                );
            }
        }
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Converts HTML or multilang text into plain text in the current language, cut to a maximum length.
     *
     * @param string $text Text to clean.
     * @param int $maxlength Maximum length in characters.
     * @param \context $context Context used for filters.
     * @return string
     */
    public static function clean(string $text, int $maxlength, \context $context): string {
        if (trim($text) === '') {
            return '';
        }
        $text = format_string($text, false, ['context' => $context, 'escape' => false]);
        $text = html_to_text($text, 0, false);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (\core_text::strlen($text) > $maxlength) {
            $text = rtrim(\core_text::substr($text, 0, $maxlength - 1)) . '…';
        }
        return $text;
    }

    /**
     * English name of a language pack, with the code.
     *
     * @param string $lang Language code.
     * @return string For example "Spanish (es)".
     */
    public static function get_language_name(string $lang): string {
        $languages = get_string_manager()->get_list_of_languages('en');
        $parent = explode('_', $lang)[0];
        $name = $languages[$lang] ?? ($languages[$parent] ?? $lang);
        return $name . ' (' . $lang . ')';
    }
}
