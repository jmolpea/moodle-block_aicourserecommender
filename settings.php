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

/**
 * Site settings of block_aicourserecommender.
 *
 * @package    block_aicourserecommender
 * @copyright  2026 Pluginia <https://pluginia.es>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_aicourserecommender\local\ai_client;
use block_aicourserecommender\local\prompt_builder;

defined('MOODLE_INTERNAL') || die();

$component = 'block_aicourserecommender';
$ADMIN->add('blocksettings', new admin_category(
    'block_aicourserecommender_category',
    new lang_string('pluginname', $component),
    $block->is_enabled() === false
));

$settings = new admin_settingpage(
    $section,
    new lang_string('settings', $component),
    'moodle/site:config',
    $block->is_enabled() === false
);

if ($ADMIN->fulltree) {
    // AI.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/aiheading',
        new lang_string('settings_ai', $component),
        ''
    ));

    $client = \core\di::get(ai_client::class);
    $providerurl = new moodle_url('/admin/settings.php', ['section' => 'aiprovider']);
    $status = html_writer::tag('p', get_string($client->is_text_available() ? 'aistatus_textyes' : 'aistatus_textno', $component));
    $status .= html_writer::tag('p', get_string(
        $client->is_image_available() ? 'aistatus_imageyes' : 'aistatus_imageno',
        $component
    ));
    $status .= html_writer::link($providerurl, get_string('aistatus_link', $component));
    $settings->add(new admin_setting_description(
        'block_aicourserecommender/aistatus',
        new lang_string('aistatus', $component),
        $status
    ));

    $baseprompt = prompt_builder::BASE_PROMPT . "\n\n[INSTITUTION INSTRUCTIONS, LEARNER DATA, CANDIDATES]\n\n" .
        prompt_builder::OUTPUT_FORMAT;
    $settings->add(new admin_setting_description(
        'block_aicourserecommender/baseprompt',
        new lang_string('baseprompt', $component),
        get_string('baseprompt_desc', $component, prompt_builder::PROMPT_VERSION) .
        html_writer::tag('pre', s($baseprompt), ['class' => 'bg-light border p-2 small', 'style' => 'white-space: pre-wrap;'])
    ));

    $settings->add(new admin_setting_configtextarea(
        'block_aicourserecommender/institutionprompt',
        new lang_string('institutionprompt', $component),
        new lang_string('institutionprompt_desc', $component),
        '',
        PARAM_RAW,
        60,
        6
    ));

    $settings->add(new admin_setting_configduration(
        'block_aicourserecommender/cachettl',
        new lang_string('cachettl', $component),
        new lang_string('cachettl_desc', $component),
        DAYSECS,
        HOURSECS
    ));

    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/dailylimit',
        new lang_string('dailylimit', $component),
        new lang_string('dailylimit_desc', $component),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/maxcoursesperprompt',
        new lang_string('maxcoursesperprompt', $component),
        new lang_string('maxcoursesperprompt_desc', $component),
        120,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/summariesperrun',
        new lang_string('summariesperrun', $component),
        new lang_string('summariesperrun_desc', $component),
        50,
        PARAM_INT
    ));

    // Questions.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/questionsheading',
        new lang_string('settings_questions', $component),
        ''
    ));
    $settings->add(new \block_aicourserecommender\admin\setting_questions());

    // Profile.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/profileheading',
        new lang_string('settings_profile', $component),
        new lang_string('settings_profile_desc', $component)
    ));
    $standard = [];
    foreach (\block_aicourserecommender\local\config::STANDARD_PROFILE_FIELDS as $field) {
        $standard[$field] = new lang_string('profilefield_' . $field, $component);
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'block_aicourserecommender/profilefields',
        new lang_string('profilefields', $component),
        new lang_string('profilefields_desc', $component),
        array_fill_keys(array_keys($standard), 1),
        $standard
    ));

    $customfields = [];
    foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
        $customfields[$field->shortname] = format_string($field->name, true, ['context' => context_system::instance()]);
    }
    if ($customfields) {
        $settings->add(new admin_setting_configmulticheckbox(
            'block_aicourserecommender/customprofilefields',
            new lang_string('customprofilefields', $component),
            new lang_string('customprofilefields_desc', $component),
            [],
            $customfields
        ));
    }

    // Catalogue.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/catalogheading',
        new lang_string('settings_catalog', $component),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_aicourserecommender/categoryfilter',
        new lang_string('categoryfilter', $component),
        new lang_string('categoryfilter_desc', $component),
        0
    ));
    $settings->add(new admin_setting_configmultiselect(
        'block_aicourserecommender/categories',
        new lang_string('categories', $component),
        new lang_string('categories_desc', $component),
        [],
        core_course_category::make_categories_list()
    ));
    $settings->hide_if('block_aicourserecommender/categories', 'block_aicourserecommender/categoryfilter');

    $enrolmethods = [];
    foreach (array_keys(core_component::get_plugin_list('enrol')) as $plugin) {
        if (
            in_array($plugin, ['manual', 'guest', 'meta', 'cohort', 'category', 'database', 'flatfile', 'imsenterprise',
                'ldap', 'lti', 'mnet'], true)
        ) {
            continue;
        }
        $enrolmethods[$plugin] = get_string('pluginname', 'enrol_' . $plugin);
    }
    $settings->add(new admin_setting_configmulticheckbox(
        'block_aicourserecommender/enrolmethods',
        new lang_string('enrolmethods', $component),
        new lang_string('enrolmethods_desc', $component),
        ['self' => 1],
        $enrolmethods
    ));

    $checkboxfields = [0 => get_string('none')];
    $sql = "SELECT f.id, f.name
              FROM {customfield_field} f
              JOIN {customfield_category} c ON c.id = f.categoryid
             WHERE c.component = :component AND c.area = :area AND f.type = :type
          ORDER BY c.sortorder, f.sortorder";
    foreach ($DB->get_records_sql($sql, ['component' => 'core_course', 'area' => 'course', 'type' => 'checkbox']) as $field) {
        $checkboxfields[$field->id] = format_string($field->name, true, ['context' => context_system::instance()]);
    }
    $settings->add(new admin_setting_configselect(
        'block_aicourserecommender/excludefield',
        new lang_string('excludefield', $component),
        new lang_string('excludefield_desc', $component),
        0,
        $checkboxfields
    ));

    $settings->add(new admin_setting_configcheckbox(
        'block_aicourserecommender/includehiddenfields',
        new lang_string('includehiddenfields', $component),
        new lang_string('includehiddenfields_desc', $component),
        0
    ));

    // Results.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/resultsheading',
        new lang_string('settings_results', $component),
        ''
    ));
    foreach (
        ['maxresults' => 4, 'maxranked' => 24, 'maxpathresults' => 2, 'maxpaths' => 6, 'notifythreshold' => 70,
            'negativedays' => 90] as $name => $default
    ) {
        $settings->add(new admin_setting_configtext(
            'block_aicourserecommender/' . $name,
            new lang_string($name, $component),
            new lang_string($name . '_desc', $component),
            $default,
            PARAM_INT
        ));
    }

    // Learning paths.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/pathsheading',
        new lang_string('settings_paths', $component),
        html_writer::link(
            new moodle_url('/blocks/aicourserecommender/managepaths.php'),
            get_string('managepaths', $component)
        )
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_aicourserecommender/showpaths',
        new lang_string('showpaths', $component),
        new lang_string('showpaths_desc', $component),
        1
    ));

    // Notifications.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/notifyheading',
        new lang_string('settings_notifications', $component),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_aicourserecommender/notifyenabled',
        new lang_string('notifyenabled', $component),
        new lang_string('notifyenabled_desc', $component),
        1
    ));
    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/notifyactivedays',
        new lang_string('notifyactivedays', $component),
        new lang_string('notifyactivedays_desc', $component),
        90,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/notifymaxusers',
        new lang_string('notifymaxusers', $component),
        new lang_string('notifymaxusers_desc', $component),
        200,
        PARAM_INT
    ));

    // Privacy.
    $settings->add(new admin_setting_heading(
        'block_aicourserecommender/privacyheading',
        new lang_string('settings_privacy', $component),
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'block_aicourserecommender/requireconsent',
        new lang_string('requireconsent', $component),
        new lang_string('requireconsent_desc', $component),
        1
    ));
    $settings->add(new admin_setting_confightmleditor(
        'block_aicourserecommender/consenttext',
        new lang_string('consenttext', $component),
        new lang_string('consenttext_desc', $component, get_string('consenttextdefault', $component)),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'block_aicourserecommender/retentiondays',
        new lang_string('retentiondays', $component),
        new lang_string('retentiondays_desc', $component),
        365,
        PARAM_INT
    ));
}

$ADMIN->add('block_aicourserecommender_category', $settings);
$ADMIN->add('block_aicourserecommender_category', new admin_externalpage(
    'block_aicourserecommender_managepaths',
    new lang_string('managepaths', $component),
    new moodle_url('/blocks/aicourserecommender/managepaths.php'),
    'block/aicourserecommender:managepaths',
    $block->is_enabled() === false
));
$ADMIN->add('block_aicourserecommender_category', new admin_externalpage(
    'block_aicourserecommender_report',
    new lang_string('report', $component),
    new moodle_url('/blocks/aicourserecommender/report.php'),
    'block/aicourserecommender:viewreports',
    $block->is_enabled() === false
));

// The settings page is added to the tree above, inside the plugin category.
$settings = null;
