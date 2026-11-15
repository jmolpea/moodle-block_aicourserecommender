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

namespace block_aicourserecommender\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Integer admin setting that only accepts values inside a range, so a value such as 0 cannot silently
 * switch a feature off.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_int_range extends \admin_setting_configtext {
    /** @var int Lowest accepted value. */
    protected int $min;

    /** @var int|null Highest accepted value, null for no upper limit. */
    protected ?int $max;

    /**
     * Constructor.
     *
     * @param string $name Unique setting name, 'component/name'.
     * @param string|\lang_string $visiblename Localised name.
     * @param string|\lang_string $description Localised description.
     * @param int $defaultsetting Default value.
     * @param int $min Lowest accepted value.
     * @param int|null $max Highest accepted value, null for no upper limit.
     */
    public function __construct($name, $visiblename, $description, int $defaultsetting, int $min, ?int $max = null) {
        $this->min = $min;
        $this->max = $max;
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
    }

    /**
     * Validates the submitted value.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, otherwise the error message.
     */
    public function validate($data) {
        $result = parent::validate($data);
        if ($result !== true) {
            return $result;
        }
        $value = (int) $data;
        if ($value < $this->min || ($this->max !== null && $value > $this->max)) {
            if ($this->max === null) {
                return get_string('errorintmin', 'block_aicourserecommender', $this->min);
            }
            return get_string('errorintrange', 'block_aicourserecommender', ['min' => $this->min, 'max' => $this->max]);
        }
        return true;
    }
}
