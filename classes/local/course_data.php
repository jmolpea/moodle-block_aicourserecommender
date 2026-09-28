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

use core_course\customfield\course_handler;

/**
 * Builds the metadata of courses that is sent to the AI.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_data {
    /** @var int Length of the description sent when a course has no AI summary yet. */
    public const FALLBACK_DESCRIPTION_LENGTH = 600;

    /** @var int Length of the full description used to build the AI summary. */
    public const SUMMARY_SOURCE_LENGTH = 4000;

    /**
     * Full metadata of some courses, keyed by course id.
     *
     * Values are plain text in the site default language (multilang resolved), without HTML.
     *
     * @param int[] $courseids Course ids.
     * @param int $descriptionlength Maximum length of the description.
     * @return array<int, array>
     */
    public static function get_metadata(array $courseids, int $descriptionlength = self::SUMMARY_SOURCE_LENGTH): array {
        global $CFG, $DB;
        // Not loaded by default in cron and CLI scripts.
        require_once($CFG->libdir . '/filelib.php');
        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_select(
            'course',
            "id $insql",
            $params,
            'id',
            'id, category, fullname, shortname, summary, summaryformat, startdate, enddate, lang'
        );

        $categories = \core_course_category::make_categories_list('', 0, ' / ');
        $tags = \core_tag_tag::get_items_tags('core', 'course', array_keys($courses));
        $fields = self::get_custom_fields(array_keys($courses));
        $enrols = self::get_enrol_info(array_keys($courses));

        $result = [];
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $summary = file_rewrite_pluginfile_urls(
                (string) $course->summary,
                'pluginfile.php',
                $context->id,
                'course',
                'summary',
                null
            );
            $summary = format_text($summary, $course->summaryformat, ['context' => $context]);
            $coursetags = [];
            foreach ($tags[$course->id] ?? [] as $tag) {
                $coursetags[] = $tag->get_display_name(false);
            }
            $item = [
                'id' => (int) $course->id,
                'name' => profile_collector::clean($course->fullname, 255, $context),
                'shortname' => profile_collector::clean($course->shortname, 255, $context),
                'category' => profile_collector::clean($categories[$course->category] ?? '', 500, $context),
                'start' => $course->startdate ? date('Y-m-d', (int) $course->startdate) : '',
                'end' => $course->enddate ? date('Y-m-d', (int) $course->enddate) : '',
                'lang' => (string) $course->lang,
                'tags' => $coursetags,
                'fields' => $fields[$course->id] ?? [],
                'enrolment' => $enrols[$course->id] ?? [],
                'description' => profile_collector::clean($summary, $descriptionlength, $context),
            ];
            $result[(int) $course->id] = array_filter($item, static fn($v) => $v !== '' && $v !== []);
        }
        return $result;
    }

    /**
     * Hash of the metadata of a course. Changes when anything the AI sees changes.
     *
     * @param array $metadata Metadata as returned by {@see get_metadata()}.
     * @return string
     */
    public static function hash(array $metadata): string {
        return sha1(json_encode($metadata, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Custom course fields as name => value, for the given courses.
     *
     * Fields that are not visible to everyone are skipped unless the admin allows them.
     * The exclusion field is never sent.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, array<string, string>>
     */
    public static function get_custom_fields(array $courseids): array {
        if (!$courseids) {
            return [];
        }
        $includehidden = (bool) config::get_int('includehiddenfields');
        $excludefield = config::get_int('excludefield');
        $handler = course_handler::create();
        $result = [];
        foreach ($handler->get_instances_data($courseids, true) as $courseid => $datas) {
            foreach ($datas as $data) {
                $field = $data->get_field();
                if ((int) $field->get('id') === $excludefield) {
                    continue;
                }
                if (!$includehidden && (int) $field->get_configdata_property('visibility') !== course_handler::VISIBLETOALL) {
                    continue;
                }
                $value = $data->export_value();
                if ($value === null || $value === '' || $value === false) {
                    continue;
                }
                $context = \context_course::instance($courseid);
                $name = profile_collector::clean($field->get_formatted_name(), 100, $context);
                $result[$courseid][$name] = profile_collector::clean((string) $value, 300, $context);
            }
        }
        return $result;
    }

    /**
     * Enabled enrolment methods of the given courses, with the cost for paid methods.
     *
     * @param int[] $courseids Course ids.
     * @return array<int, string[]> For example ["self", "fee 20 EUR"].
     */
    public static function get_enrol_info(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['status'] = ENROL_INSTANCE_ENABLED;
        $instances = $DB->get_records_select(
            'enrol',
            "courseid $insql AND status = :status",
            $params,
            'courseid, sortorder',
            'id, courseid, enrol, cost, currency, password'
        );
        $result = [];
        $enabled = array_flip(explode(',', (string) get_config('core', 'enrol_plugins_enabled')));
        foreach ($instances as $instance) {
            if (!isset($enabled[$instance->enrol])) {
                continue;
            }
            $label = $instance->enrol;
            if (($instance->enrol === 'fee' || $instance->enrol === 'paypal') && (float) $instance->cost > 0) {
                $label .= ' ' . format_float((float) $instance->cost, 2, false) . ' ' . $instance->currency;
            } else if ($instance->enrol === 'self' && $instance->password !== '' && $instance->password !== null) {
                $label .= ' (enrolment key required)';
            }
            $result[$instance->courseid][] = $label;
        }
        foreach ($result as $courseid => $labels) {
            $result[$courseid] = array_values(array_unique($labels));
        }
        return $result;
    }
}
