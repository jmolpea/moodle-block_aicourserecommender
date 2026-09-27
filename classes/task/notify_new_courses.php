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

namespace block_aicourserecommender\task;

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\answers_manager;
use block_aicourserecommender\local\candidate_finder;
use block_aicourserecommender\local\config;
use block_aicourserecommender\local\ranking_manager;

/**
 * Daily task: ranks courses that became candidates since the last ranking of each active learner, merges them into
 * the stored ranking and notifies the learner about the relevant ones.
 *
 * AI calls made here do not count towards the daily limit of the learner.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify_new_courses extends \core\task\scheduled_task {
    /** @var int Maximum notifications per learner and run. */
    public const MAX_NOTIFICATIONS = 3;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('tasknotifynewcourses', 'block_aicourserecommender');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        if (!\core\di::get(ai_client::class)->is_text_available()) {
            mtrace('AI text generation is not available. Nothing to do.');
            return;
        }
        $processed = 0;
        foreach ($this->get_users() as $user) {
            $this->process_user($user);
            $processed++;
        }
        mtrace("Learners processed: {$processed}.");
    }

    /**
     * Learners with saved answers, a stored ranking and recent activity, least recently checked first.
     *
     * @return \stdClass[]
     */
    public function get_users(): array {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        $sql = "SELECT u.*, r.timemodified AS rankingchecked
                  FROM {block_aicourserecommender_ranking} r
                  JOIN {block_aicourserecommender_answers} a ON a.userid = r.userid
                  JOIN {user} u ON u.id = r.userid
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND u.lastaccess >= :activesince
                   AND r.timemodified < :checkedbefore
                   AND a.answers IS NOT NULL
              ORDER BY r.timemodified ASC, r.id ASC";
        return $DB->get_records_sql($sql, [
            'activesince' => $now - config::get_int('notifyactivedays') * DAYSECS,
            'checkedbefore' => $now - 12 * HOURSECS,
        ], 0, max(1, config::get_int('notifymaxusers')));
    }

    /**
     * Ranks the new candidates of a learner and sends the notifications.
     *
     * @param \stdClass $user User record.
     * @return void
     */
    public function process_user(\stdClass $user): void {
        global $DB;
        $userid = (int) $user->id;
        $system = \context_system::instance();
        if (
            !has_capability('block/aicourserecommender:use', $system, $userid) || answers_manager::needs_consent($userid)
                || !\core_ai\manager::get_user_policy_status($userid)
        ) {
            $this->touch($userid);
            return;
        }

        $previouslang = force_current_language($user->lang ?: get_config('core', 'lang'));
        try {
            $stored = ranking_manager::get_stored($userid);
            $newids = $stored ? $this->find_new_candidates($userid, $stored) : [];
            if (!$newids) {
                $this->touch($userid);
                return;
            }
            $manager = new ranking_manager();
            $ranked = $manager->rank_new_courses($userid, $newids);
            if (!$ranked) {
                return;
            }
            mtrace("Learner {$userid}: " . count($ranked) . ' new course(s) ranked.');
            if (!config::get_int('notifyenabled')) {
                return;
            }
            $sent = 0;
            foreach ($ranked as $item) {
                if ($item['score'] < config::get_int('notifythreshold') || $sent >= self::MAX_NOTIFICATIONS) {
                    continue;
                }
                $course = $DB->get_record('course', ['id' => $item['id']], 'id, fullname');
                if ($course) {
                    $this->send_notification($user, $course, $item['reason']);
                    $sent++;
                }
            }
        } finally {
            force_current_language($previouslang);
        }
    }

    /**
     * Candidate courses that were not candidates at the time of the stored ranking.
     *
     * A candidate is new when it was not sent in the last ranking and it was created, changed, or had an enrolment
     * method opened or changed after the ranking was last checked.
     *
     * @param int $userid User id.
     * @param \stdClass $stored Stored ranking.
     * @return int[]
     */
    public function find_new_candidates(int $userid, \stdClass $stored): array {
        global $DB;
        $known = json_decode((string) $stored->candidates, true) ?: [];
        $candidates = array_keys((new candidate_finder())->get_candidates($userid));
        $unknown = array_values(array_diff($candidates, array_map('intval', array_keys($known))));
        if (!$unknown) {
            return [];
        }
        $since = (int) $stored->timemodified;
        $now = \core\di::get(\core\clock::class)->time();
        $new = [];
        foreach (array_chunk($unknown, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $params += ['since1' => $since, 'since2' => $since, 'since3' => $since, 'since4' => $since, 'now' => $now];
            $sql = "SELECT c.id
                      FROM {course} c
                     WHERE c.id $insql
                       AND (c.timecreated > :since1 OR c.timemodified > :since2
                            OR EXISTS (SELECT 1 FROM {enrol} e
                                        WHERE e.courseid = c.id
                                          AND (e.timemodified > :since3
                                               OR (e.enrolstartdate > :since4 AND e.enrolstartdate <= :now))))";
            $new = array_merge($new, array_map('intval', $DB->get_fieldset_sql($sql, $params)));
        }
        return array_slice($new, 0, config::get_int('maxcoursesperprompt'));
    }

    /**
     * Marks a learner as checked.
     *
     * @param int $userid User id.
     * @return void
     */
    protected function touch(int $userid): void {
        global $DB;
        $DB->set_field(ranking_manager::TABLE, 'timemodified', \core\di::get(\core\clock::class)->time(), ['userid' => $userid]);
    }

    /**
     * Sends the "new course for you" notification.
     *
     * @param \stdClass $user Recipient.
     * @param \stdClass $course Course (id, fullname).
     * @param string $reason Why it fits.
     * @return void
     */
    protected function send_notification(\stdClass $user, \stdClass $course, string $reason): void {
        $context = \context_course::instance($course->id);
        $name = format_string($course->fullname, true, ['context' => $context]);
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        $message = new \core\message\message();
        $message->component = 'block_aicourserecommender';
        $message->name = 'newcourse';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('notificationsubject', 'block_aicourserecommender', $name);
        $message->fullmessage = get_string(
            'notificationbody',
            'block_aicourserecommender',
            ['name' => $name, 'reason' => $reason, 'url' => $url->out(false)]
        );
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string(
            'notificationbodyhtml',
            'block_aicourserecommender',
            ['name' => s($name), 'reason' => s($reason), 'url' => $url->out()]
        );
        $message->smallmessage = get_string('notificationsubject', 'block_aicourserecommender', $name);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $name;
        $message->courseid = SITEID;
        message_send($message);
    }
}
