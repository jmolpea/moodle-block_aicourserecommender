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
 * Report table of the AI calls.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ailog_table extends \table_sql {
    /** @var bool Whether the user column is shown. */
    protected bool $showusers;

    /**
     * Constructor.
     *
     * @param int $from Start of the period (timestamp).
     * @param int $to End of the period (timestamp).
     * @param \moodle_url $baseurl Page URL.
     * @param bool $showusers Show the user of each call.
     */
    public function __construct(int $from, int $to, \moodle_url $baseurl, bool $showusers) {
        parent::__construct('block_aicourserecommender_ailog');
        $this->showusers = $showusers;
        $component = 'block_aicourserecommender';

        $columns = ['timecreated'];
        $headers = [get_string('date')];
        if ($showusers) {
            $columns[] = 'fullname';
            $headers[] = get_string('user');
        }
        $columns = array_merge($columns, ['calltype', 'success', 'error', 'duration', 'tokens']);
        $headers = array_merge($headers, [
            get_string('report_calltype', $component),
            get_string('report_success', $component),
            get_string('error'),
            get_string('report_duration', $component),
            get_string('report_tokens', $component),
        ]);
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_baseurl(new \moodle_url($baseurl, ['table' => 'ailog']));
        $this->sortable(true, 'timecreated', SORT_DESC);
        $this->no_sorting('error');
        $this->no_sorting('fullname');
        $this->collapsible(false);
        $this->pageable(true);
        $this->set_attribute('class', 'generaltable table-sm');
        $this->set_control_variables([
            TABLE_VAR_SORT => 'tsortailog',
            TABLE_VAR_HIDE => 'thideailog',
            TABLE_VAR_SHOW => 'tshowailog',
            TABLE_VAR_IFIRST => 'tifirstailog',
            TABLE_VAR_ILAST => 'tilastailog',
            TABLE_VAR_PAGE => 'pageailog',
            TABLE_VAR_RESET => 'tresetailog',
            TABLE_VAR_DIR => 'tdirailog',
        ]);

        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $this->set_sql(
            "l.id, l.userid, l.calltype, l.success, l.error, l.duration, l.tokens, l.timecreated, $userfields",
            '{block_aicourserecommender_ailog} l LEFT JOIN {user} u ON u.id = l.userid',
            'l.timecreated >= :from AND l.timecreated <= :to',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Date column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_timecreated($row) {
        return userdate($row->timecreated, get_string('strftimedatetimeshort', 'core_langconfig'));
    }

    /**
     * User column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_fullname($row) {
        if (empty($row->userid)) {
            return '-';
        }
        return fullname($row);
    }

    /**
     * Call type column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_calltype($row) {
        $key = 'calltype_' . $row->calltype;
        return get_string_manager()->string_exists($key, 'block_aicourserecommender') ?
            get_string($key, 'block_aicourserecommender') : s($row->calltype);
    }

    /**
     * Success column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_success($row) {
        return $row->success ? get_string('yes') : get_string('no');
    }

    /**
     * Error column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_error($row) {
        $error = (string) $row->error;
        return $this->is_downloading() ? $error : s(shorten_text($error, 120));
    }

    /**
     * Duration column (seconds).
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_duration($row) {
        return format_float($row->duration / 1000, 2);
    }

    /**
     * Tokens column.
     *
     * @param \stdClass $row Row.
     * @return string
     */
    public function col_tokens($row) {
        return $row->tokens ? (string) $row->tokens : '-';
    }
}
