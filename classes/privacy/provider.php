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

namespace block_aicourserecommender\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider of block_aicourserecommender.
 *
 * Learner data (answers, rankings, ratings, activity and AI call log) is stored in the user context.
 * Learning paths are site content: only the "last modified by" user id is personal data, stored in the system context.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string[] Tables with learner data, all with a userid column. */
    public const USER_TABLES = [
        'block_aicourserecommender_answers',
        'block_aicourserecommender_answerhist',
        'block_aicourserecommender_ranking',
        'block_aicourserecommender_feedback',
        'block_aicourserecommender_activity',
        'block_aicourserecommender_ailog',
    ];

    /**
     * Describes the stored personal data.
     *
     * @param collection $collection Collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_aicourserecommender_answers', [
            'userid' => 'privacy:metadata:answers:userid',
            'answers' => 'privacy:metadata:answers:answers',
            'consent' => 'privacy:metadata:answers:consent',
            'timeconsent' => 'privacy:metadata:answers:timeconsent',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:answers');
        $collection->add_database_table('block_aicourserecommender_answerhist', [
            'userid' => 'privacy:metadata:answers:userid',
            'answers' => 'privacy:metadata:answers:answers',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:answerhist');
        $collection->add_database_table('block_aicourserecommender_ranking', [
            'userid' => 'privacy:metadata:answers:userid',
            'lang' => 'privacy:metadata:ranking:lang',
            'courses' => 'privacy:metadata:ranking:courses',
            'paths' => 'privacy:metadata:ranking:paths',
            'timecreated' => 'privacy:metadata:timecreated',
            'timeexpires' => 'privacy:metadata:ranking:timeexpires',
        ], 'privacy:metadata:ranking');
        $collection->add_database_table('block_aicourserecommender_feedback', [
            'userid' => 'privacy:metadata:answers:userid',
            'itemtype' => 'privacy:metadata:itemtype',
            'itemid' => 'privacy:metadata:itemid',
            'rating' => 'privacy:metadata:feedback:rating',
            'reason' => 'privacy:metadata:feedback:reason',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:feedback');
        $collection->add_database_table('block_aicourserecommender_activity', [
            'userid' => 'privacy:metadata:answers:userid',
            'action' => 'privacy:metadata:activity:action',
            'itemtype' => 'privacy:metadata:itemtype',
            'itemid' => 'privacy:metadata:itemid',
            'position' => 'privacy:metadata:activity:position',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:activity');
        $collection->add_database_table('block_aicourserecommender_ailog', [
            'userid' => 'privacy:metadata:answers:userid',
            'calltype' => 'privacy:metadata:ailog:calltype',
            'success' => 'privacy:metadata:ailog:success',
            'duration' => 'privacy:metadata:ailog:duration',
            'tokens' => 'privacy:metadata:ailog:tokens',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:ailog');
        $collection->add_database_table('block_aicourserecommender_paths', [
            'usermodified' => 'privacy:metadata:paths:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:paths');
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:core_ai');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * Contexts with data of a user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        $contextlist->add_from_sql(
            "SELECT ctx.id
                                      FROM {context} ctx
                                     WHERE ctx.contextlevel = :contextlevel
                                       AND EXISTS (SELECT 1 FROM {block_aicourserecommender_paths} p
                                                    WHERE p.usermodified = :userid)",
            ['contextlevel' => CONTEXT_SYSTEM, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            if (self::user_has_data((int) $context->instanceid)) {
                $userlist->add_user((int) $context->instanceid);
            }
        } else if ($context instanceof \context_system) {
            $userlist->add_from_sql('usermodified', 'SELECT usermodified FROM {block_aicourserecommender_paths}', []);
        }
    }

    /**
     * Exports the data of a user.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        $subcontext = [get_string('pluginname', 'block_aicourserecommender')];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $paths = $DB->get_records(
                    'block_aicourserecommender_paths',
                    ['usermodified' => $userid],
                    'id',
                    'id, name, timemodified'
                );
                if ($paths) {
                    $data = array_map(static fn($p) => [
                        'name' => format_string($p->name, true, ['context' => $context]),
                        'timemodified' => transform::datetime($p->timemodified),
                    ], array_values($paths));
                    writer::with_context($context)->export_data(array_merge($subcontext, [get_string(
                        'paths',
                        'block_aicourserecommender'
                    )]), (object) ['paths' => $data]);
                }
                continue;
            }
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $writer = writer::with_context($context);

            $answers = $DB->get_record('block_aicourserecommender_answers', ['userid' => $userid]);
            if ($answers) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:answers', 'block_aicourserecommender')]),
                    (object) [
                        'answers' => json_decode((string) $answers->answers, true),
                        'consent' => transform::yesno($answers->consent),
                        'timeconsent' => $answers->timeconsent ? transform::datetime($answers->timeconsent) : '-',
                        'timemodified' => transform::datetime($answers->timemodified),
                    ]
                );
            }
            $history = $DB->get_records('block_aicourserecommender_answerhist', ['userid' => $userid], 'timecreated');
            if ($history) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:history', 'block_aicourserecommender')]),
                    (object) ['history' => array_map(
                        static fn($h) => [
                        'answers' => json_decode((string) $h->answers, true),
                        'timecreated' => transform::datetime($h->timecreated),
                        ],
                        array_values($history)
                    )]
                );
            }
            $ranking = $DB->get_record('block_aicourserecommender_ranking', ['userid' => $userid]);
            if ($ranking) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:ranking', 'block_aicourserecommender')]),
                    (object) [
                        'lang' => $ranking->lang,
                        'courses' => json_decode((string) $ranking->courses, true),
                        'paths' => json_decode((string) $ranking->paths, true),
                        'timecreated' => transform::datetime($ranking->timecreated),
                        'timeexpires' => transform::datetime($ranking->timeexpires),
                    ]
                );
            }
            $feedback = $DB->get_records('block_aicourserecommender_feedback', ['userid' => $userid], 'timemodified');
            if ($feedback) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:feedback', 'block_aicourserecommender')]),
                    (object) ['ratings' => array_map(
                        static fn($f) => [
                        'itemtype' => $f->itemtype,
                        'itemid' => $f->itemid,
                        'rating' => $f->rating,
                        'reason' => $f->reason,
                        'timemodified' => transform::datetime($f->timemodified),
                        ],
                        array_values($feedback)
                    )]
                );
            }
            $activity = $DB->get_records('block_aicourserecommender_activity', ['userid' => $userid], 'timecreated');
            if ($activity) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:activity', 'block_aicourserecommender')]),
                    (object) ['activity' => array_map(
                        static fn($a) => [
                        'action' => $a->action,
                        'itemtype' => $a->itemtype,
                        'itemid' => $a->itemid,
                        'position' => $a->position,
                        'timecreated' => transform::datetime($a->timecreated),
                        ],
                        array_values($activity)
                    )]
                );
            }
            $log = $DB->get_records('block_aicourserecommender_ailog', ['userid' => $userid], 'timecreated');
            if ($log) {
                $writer->export_data(
                    array_merge($subcontext, [get_string('privacy:ailog', 'block_aicourserecommender')]),
                    (object) ['calls' => array_map(
                        static fn($l) => [
                        'calltype' => $l->calltype,
                        'success' => transform::yesno($l->success),
                        'duration' => $l->duration,
                        'tokens' => $l->tokens,
                        'timecreated' => transform::datetime($l->timecreated),
                        ],
                        array_values($log)
                    )]
                );
            }
        }
    }

    /**
     * Deletes all data in a context.
     *
     * @param \context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_user) {
            self::delete_user_data((int) $context->instanceid);
        } else if ($context instanceof \context_system) {
            $DB->set_field('block_aicourserecommender_paths', 'usermodified', 0, []);
        }
    }

    /**
     * Deletes the data of a user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::delete_user_data($userid);
            } else if ($context instanceof \context_system) {
                $DB->set_field('block_aicourserecommender_paths', 'usermodified', 0, ['usermodified' => $userid]);
            }
        }
    }

    /**
     * Deletes the data of several users in a context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context instanceof \context_user) {
            if (in_array((int) $context->instanceid, array_map('intval', $userlist->get_userids()), true)) {
                self::delete_user_data((int) $context->instanceid);
            }
        } else if ($context instanceof \context_system) {
            $userids = $userlist->get_userids();
            if ($userids) {
                [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                $DB->set_field_select('block_aicourserecommender_paths', 'usermodified', 0, "usermodified $insql", $params);
            }
        }
    }

    /**
     * Deletes every learner record of a user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function delete_user_data(int $userid): void {
        global $DB;
        foreach (self::USER_TABLES as $table) {
            $DB->delete_records($table, ['userid' => $userid]);
        }
    }

    /**
     * Whether a user has learner data.
     *
     * @param int $userid User id.
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;
        foreach (self::USER_TABLES as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }
        return false;
    }
}
