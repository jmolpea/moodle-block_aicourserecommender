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
 * Finds the courses that can be recommended to a user.
 *
 * A course is a candidate when all of these are true:
 *  1. It is visible and it is not the site course.
 *  2. It has not ended (enddate is 0 or in the future).
 *  3. At least one enabled instance of an allowed enrolment method is open now. For "self" this mirrors
 *     enrol_self_plugin::is_self_enrol_available(): new enrolments allowed, seats left and cohort membership,
 *     plus the enrol/self:enrolself capability. Courses with an enrolment key are candidates too; their card
 *     links to the course instead of enrolling directly.
 *  4. The user has no enrolment in it, active or suspended.
 *  5. It belongs to one of the selected categories or their subcategories, when the category filter is on.
 *  6. It is not marked with the "Do not recommend" course custom field.
 *  7. The user can see the course information.
 *
 * Everything that can be expressed in SQL is resolved in one query, so large catalogues are cheap.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidate_finder {
    /**
     * Candidate courses for a user.
     *
     * @param int $userid User id.
     * @param int[]|null $restrictids When given, only these course ids are checked.
     * @return \stdClass[] Course records keyed by id (id, category, fullname, shortname, startdate, enddate, visible).
     */
    public function get_candidates(int $userid, ?array $restrictids = null): array {
        global $DB;

        $methods = config::get_enrol_methods();
        $enabledplugins = explode(',', (string) get_config('core', 'enrol_plugins_enabled'));
        $methods = array_values(array_intersect($methods, $enabledplugins));
        if (!$methods || ($restrictids !== null && !$restrictids)) {
            return [];
        }

        $now = \core\di::get(\core\clock::class)->time();
        [$methodsql, $params] = $DB->get_in_or_equal($methods, SQL_PARAMS_NAMED, 'method');
        $params += [
            'siteid' => SITEID,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'now1' => $now,
            'now2' => $now,
            'now3' => $now,
            'userid1' => $userid,
            'userid2' => $userid,
            'self1' => 'self',
            'ctxlevel' => CONTEXT_COURSE,
        ];

        $where = [
            'c.id <> :siteid',
            'c.visible = 1',
            '(c.enddate = 0 OR c.enddate > :now1)',
            "NOT EXISTS (SELECT 1
                           FROM {user_enrolments} ue
                           JOIN {enrol} ue_e ON ue_e.id = ue.enrolid
                          WHERE ue_e.courseid = c.id AND ue.userid = :userid1)",
        ];

        if ($restrictids !== null) {
            [$idsql, $idparams] = $DB->get_in_or_equal(array_map('intval', $restrictids), SQL_PARAMS_NAMED, 'rid');
            $where[] = "c.id $idsql";
            $params += $idparams;
        }

        $categories = $this->get_category_ids();
        if ($categories !== null) {
            if (!$categories) {
                return [];
            }
            [$catsql, $catparams] = $DB->get_in_or_equal($categories, SQL_PARAMS_NAMED, 'cat');
            $where[] = "c.category $catsql";
            $params += $catparams;
        }

        $excludefield = config::get_int('excludefield');
        if ($excludefield && $DB->record_exists('customfield_field', ['id' => $excludefield, 'type' => 'checkbox'])) {
            $where[] = "NOT EXISTS (SELECT 1
                                      FROM {customfield_data} cfd
                                     WHERE cfd.fieldid = :excludefield AND cfd.instanceid = c.id AND cfd.intvalue = 1)";
            $params['excludefield'] = $excludefield;
        }

        // Mirrors enrol_self_plugin::is_self_enrol_available() so the whole catalogue is filtered in one query.
        $selfsql = "(e.enrol <> :self1 OR (
                        e.customint6 = 1
                        AND (e.customint3 IS NULL OR e.customint3 = 0
                             OR (SELECT COUNT(1) FROM {user_enrolments} cap WHERE cap.enrolid = e.id) < e.customint3)
                        AND (e.customint5 IS NULL OR e.customint5 = 0
                             OR EXISTS (SELECT 1 FROM {cohort_members} cm
                                         WHERE cm.cohortid = e.customint5 AND cm.userid = :userid2))
                    ))";

        $ctxfields = \context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT e.id AS enrolid, e.enrol, c.id, c.category, c.fullname, c.shortname, c.startdate, c.enddate,
                       c.visible, $ctxfields
                  FROM {course} c
                  JOIN {enrol} e ON e.courseid = c.id
                       AND e.status = :enabled
                       AND e.enrol $methodsql
                       AND (e.enrolstartdate = 0 OR e.enrolstartdate <= :now2)
                       AND (e.enrolenddate = 0 OR e.enrolenddate > :now3)
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxlevel
                 WHERE " . implode(' AND ', $where) . " AND $selfsql
              ORDER BY c.id";

        $rs = $DB->get_recordset_sql($sql, $params);
        $candidates = [];
        $rejected = [];
        $viewchecked = [];
        foreach ($rs as $row) {
            $courseid = (int) $row->id;
            if (isset($candidates[$courseid]) || isset($rejected[$courseid])) {
                continue;
            }
            \context_helper::preload_from_record($row);
            $context = \context_course::instance($courseid);
            if (!isset($viewchecked[$courseid])) {
                $viewchecked[$courseid] = true;
                $course = (object) ['id' => $courseid, 'visible' => $row->visible, 'category' => $row->category];
                if (!\core_course_category::can_view_course_info($course, $userid)) {
                    $rejected[$courseid] = true;
                    continue;
                }
            }
            if ($row->enrol === 'self' && !has_capability('enrol/self:enrolself', $context, $userid)) {
                // Another enrolment instance of the same course may still qualify.
                continue;
            }
            $candidates[$courseid] = (object) [
                'id' => $courseid,
                'category' => (int) $row->category,
                'fullname' => $row->fullname,
                'shortname' => $row->shortname,
                'startdate' => (int) $row->startdate,
                'enddate' => (int) $row->enddate,
                'visible' => (int) $row->visible,
            ];
        }
        $rs->close();
        return $candidates;
    }

    /**
     * Ids of the categories allowed by the category filter, including subcategories.
     *
     * @return int[]|null Null when the filter is off.
     */
    protected function get_category_ids(): ?array {
        $selected = config::get_category_filter();
        if ($selected === null) {
            return null;
        }
        $ids = [];
        foreach ($selected as $categoryid) {
            $category = \core_course_category::get($categoryid, IGNORE_MISSING, true);
            if (!$category) {
                continue;
            }
            $ids[] = (int) $category->id;
            foreach ($category->get_all_children_ids() as $childid) {
                $ids[] = (int) $childid;
            }
        }
        return array_values(array_unique($ids));
    }
}
