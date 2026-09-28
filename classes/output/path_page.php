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

use block_aicourserecommender\local\path_manager;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;

/**
 * Landing page of a learning path.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class path_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param \stdClass $path Path record.
     * @param int $userid Current user id.
     */
    public function __construct(
        /** @var \stdClass Path record. */
        protected \stdClass $path,
        /** @var int Current user id. */
        protected int $userid,
    ) {
    }

    /**
     * Template data.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $dateformat = get_string('strftimedate', 'core_langconfig');
        $items = path_manager::get_progress((int) $this->path->id, $this->userid);
        $courses = [];
        $completed = 0;
        $direct = 0;
        $number = 0;
        foreach ($items as $item) {
            $course = $item['course'];
            $context = \context_course::instance($course->id);
            $number++;
            if ($item['status'] === 'completed') {
                $completed++;
            }
            if ($item['directenrol']) {
                $direct++;
            }
            $summary = format_text($course->summary, $course->summaryformat, ['context' => $context]);
            $summary = shorten_text(html_to_text($summary, 0, false), 300);
            $image = \core_course\external\course_summary_exporter::get_course_image($course);
            $futurestart = $item['status'] === 'unavailable' && $course->startdate > time();
            $courses[] = [
                'number' => $number,
                'id' => (int) $course->id,
                'name' => format_string($course->fullname, true, ['context' => $context]),
                'image' => $image ?: $output->get_generated_image_for_id($course->id),
                'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'startdate' => $course->startdate ? userdate($course->startdate, $dateformat) : '',
                'enddate' => $course->enddate ? userdate($course->enddate, $dateformat) : '',
                'summary' => $summary,
                'status' => $item['status'],
                'statuslabel' => get_string('status_' . $item['status'], 'block_aicourserecommender'),
                'iscompleted' => $item['status'] === 'completed',
                'isinprogress' => $item['status'] === 'inprogress',
                'isavailable' => $item['status'] === 'available',
                'isunavailable' => $item['status'] === 'unavailable',
                'hasprogress' => $item['progress'] !== null && $item['status'] === 'inprogress',
                'progress' => (int) $item['progress'],
                'futurestart' => $futurestart,
                'futurestartdate' => $futurestart ? userdate($course->startdate, $dateformat) : '',
                'directenrol' => $item['directenrol'],
                'reason' => $item['reason'],
                'hasreason' => $item['reason'] !== '',
            ];
        }
        $total = count($courses);
        $blocked = array_values(array_filter($courses, static fn($c) => $c['hasreason']));
        return [
            'id' => (int) $this->path->id,
            'uniqid' => \html_writer::random_id('aicrpath'),
            'name' => path_manager::format_name($this->path),
            'description' => path_manager::format_description($this->path),
            'image' => path_manager::get_image_url((int) $this->path->id),
            'courses' => $courses,
            'hascourses' => $total > 0,
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total ? (int) round($completed * 100 / $total) : 0,
            'progresstext' => get_string(
                'pathprogress',
                'block_aicourserecommender',
                ['completed' => $completed, 'total' => $total]
            ),
            'canenrolall' => $direct > 0,
            'noneavailable' => $direct === 0 && count($blocked) > 0,
            'enrolsummary' => get_string('enrolpathsummary', 'block_aicourserecommender', $direct),
            'blocked' => array_map(static fn($c) => ['name' => $c['name'], 'reason' => $c['reason']], $blocked),
            'hasblocked' => count($blocked) > 0,
        ];
    }
}
