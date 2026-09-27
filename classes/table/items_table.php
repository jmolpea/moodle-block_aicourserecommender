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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Report table of the most recommended courses or learning paths.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class items_table extends \table_sql {
    use spreadsheet_safe;

    /** @var string course or path. */
    protected string $itemtype;

    /**
     * Constructor.
     *
     * @param string $itemtype course or path.
     * @param int $from Start of the period (timestamp).
     * @param int $to End of the period (timestamp).
     * @param \moodle_url $baseurl Page URL.
     */
    public function __construct(string $itemtype, int $from, int $to, \moodle_url $baseurl) {
        parent::__construct('block_aicourserecommender_' . $itemtype . 's');
        $this->itemtype = $itemtype;
        $component = 'block_aicourserecommender';

        $this->define_columns(['name', 'shown', 'clicks', 'enrolments', 'positive', 'negative']);
        $this->define_headers([
            get_string($itemtype === 'course' ? 'course' : 'path', $component),
            get_string('report_shown', $component),
            get_string('report_clicks', $component),
            get_string('report_enrolments', $component),
            get_string('report_positive', $component),
            get_string('report_negative', $component),
        ]);
        $this->define_baseurl(new \moodle_url($baseurl, ['table' => $itemtype]));
        $this->sortable(true, 'shown', SORT_DESC);
        $this->no_sorting('name');
        $this->collapsible(false);
        $this->pageable(true);
        $this->set_attribute('class', 'generaltable table-sm');
        $this->set_control_variables([
            TABLE_VAR_SORT => 'tsort' . $itemtype,
            TABLE_VAR_HIDE => 'thide' . $itemtype,
            TABLE_VAR_SHOW => 'tshow' . $itemtype,
            TABLE_VAR_IFIRST => 'tifirst' . $itemtype,
            TABLE_VAR_ILAST => 'tilast' . $itemtype,
            TABLE_VAR_PAGE => 'page' . $itemtype,
            TABLE_VAR_RESET => 'treset' . $itemtype,
            TABLE_VAR_DIR => 'tdir' . $itemtype,
        ]);

        if ($itemtype === 'course') {
            $join = 'JOIN {course} i ON i.id = a.itemid';
            $namefield = 'i.fullname';
        } else {
            $join = 'JOIN {block_aicourserecommender_paths} i ON i.id = a.itemid';
            $namefield = 'i.name';
        }
        $params = [
            'itemtype1' => $itemtype, 'itemtype2' => $itemtype, 'itemtype3' => $itemtype,
            'from1' => $from, 'to1' => $to, 'from2' => $from, 'to2' => $to, 'from3' => $from, 'to3' => $to,
            'shown' => 'shown', 'click' => 'click', 'enrol' => 'enrol',
        ];
        $from = "(SELECT a.itemid AS id, $namefield AS name,
                         SUM(CASE WHEN a.action = :shown THEN 1 ELSE 0 END) AS shown,
                         SUM(CASE WHEN a.action = :click THEN 1 ELSE 0 END) AS clicks,
                         SUM(CASE WHEN a.action = :enrol THEN 1 ELSE 0 END) AS enrolments,
                         (SELECT COUNT(1) FROM {block_aicourserecommender_feedback} fp
                           WHERE fp.itemtype = :itemtype2 AND fp.itemid = a.itemid AND fp.rating > 0
                             AND fp.timemodified >= :from2 AND fp.timemodified <= :to2) AS positive,
                         (SELECT COUNT(1) FROM {block_aicourserecommender_feedback} fn
                           WHERE fn.itemtype = :itemtype3 AND fn.itemid = a.itemid AND fn.rating < 0
                             AND fn.timemodified >= :from3 AND fn.timemodified <= :to3) AS negative
                    FROM {block_aicourserecommender_activity} a
                    $join
                   WHERE a.itemtype = :itemtype1 AND a.timecreated >= :from1 AND a.timecreated <= :to1
                GROUP BY a.itemid, $namefield) stats";
        $this->set_sql('stats.*', $from, '1 = 1', $params);
        $this->set_count_sql("SELECT COUNT(1) FROM $from", $params);
    }

    /**
     * Name column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_name($row) {
        if ($this->itemtype === 'course') {
            $context = \context_course::instance($row->id, IGNORE_MISSING);
            $name = format_string($row->name, true, ['context' => $context ?: \context_system::instance()]);
            $url = new \moodle_url('/course/view.php', ['id' => $row->id]);
        } else {
            $name = format_string($row->name, true, ['context' => \context_system::instance()]);
            $url = new \moodle_url('/blocks/aicourserecommender/path.php', ['id' => $row->id]);
        }
        if ($this->is_downloading()) {
            return self::spreadsheet_safe(format_string($row->name, true, ['escape' => false]));
        }
        return \html_writer::link($url, $name);
    }
}
