# Changes

## 1.0.0-beta5 (2026111500)

- Fix: filtering the report by dates failed. The date selectors of the filter had the same names (`from`, `to`) as the URL parameters of the report, so the page received arrays where it expected timestamps (a coding exception on current Moodle versions). The form fields are now `filterfrom` and `filterto`; links with `from` and `to` keep working.
- Fix: on the page of a learning path, a course with open self enrolment that is left out by the catalogue settings (category filter, exclusion field or enrolment methods) said "This course does not allow self enrolment". It now says that the learner cannot be enrolled from the recommender and should open the course.
- The numeric settings only accept values that make sense. Requests per learner and day, maximum courses per request, courses and paths shown and ranked, learner activity days and learners per run need 1 or more; the notification threshold accepts 0 to 100; summaries per run, hidden days and retention days accept 0 or more. Before, 0 in "AI requests per user and day" blocked every new ranking without any warning. Values already stored are not changed: an out-of-range value is reported the next time the settings page is saved.
- The daily new-courses task stops when the AI provider rate limit is reached and continues in the next run, instead of sending the remaining learners to a provider that rejects them.
- The description of "Notify new courses" now says that, when it is off, the daily task still ranks new courses (AI requests) and that the scheduled task must be disabled to avoid them. No change in behaviour.
- New link to the online configuration manual in the settings page and in "Manage learning paths".
- New strings: `errorintmin`, `errorintrange`, `manuallink`, `manuallink_desc`, `reason_opencourse` (English, Spanish and Portuguese).

## 1.0.0-beta4 (2026100600)

- Moodle 5.3 support: the supported range is now Moodle 4.5 to 5.3. PHPUnit, Behat and the moodle-plugin-ci checks pass on Moodle 5.3 (PHP 8.3, PostgreSQL 17). No change to the plugin code was needed.
- Fix (plugins directory review, issue #1): the report sets the page context and URL before it builds the date filter form, and the form labels use the plugin's own `report_from` and `report_to` strings instead of core strings that no longer exist. The report showed `[[from]]` and `[[to]]` as labels and raised three developer debugging messages. A PHPUnit test covers the form.
- The path form and the questions setting no longer use Bootstrap 4 class names (a spacing class and the select box), which Moodle 5.x reports as deprecated; the select box takes the class of the Bootstrap version in use.
- CI: Moodle 5.3 added to the matrix (PostgreSQL 17 and MySQL 8.4).
- Tests declare `CoversClass` as a PHPUnit attribute as well as a doc-comment (PHPUnit 11 deprecates the doc-comment form).

## 1.0.0-beta3 (2026092901)

- Same code as 1.0.0-beta2. New version number for the Moodle plugins directory submission.

## 1.0.0-beta2 (2026092900)

- First-use wizard: visual steps, one question per card, single acceptance of the notice and the AI policy, summary to edit answers.
- "Enrol me in the whole path" explains, course by course, why a course could not be enrolled in.
- Corporate image style setting for AI generated path images.
- Path AI actions show the error returned by the AI provider.
- Fix: the summary task failed in cron (file library not loaded).
- The summary task stops when the provider rate limit is reached and continues in the next run.

## 1.0.0-beta (2026092800)

First release.

- Block for the site front page and the Dashboard, only for logged-in users.
- Consent notice and Moodle AI usage policy before the first request.
- Configurable questionnaire (up to six questions, four defaults in English, Spanish and Portuguese) with voice dictation.
- Candidate courses: visible, not finished, open enrolment, not enrolled, category filter, exclusion field, course visibility.
- AI ranking through `core_ai` with strict JSON validation, one retry, cache per learner, daily limit and "See more" without new calls.
- Course and learning path cards with explanation, direct self enrolment and ratings.
- Learning paths: management page, AI description in every installed language, AI image, landing page with progress, enrolment in the whole path.
- AI course summaries generated once per course change.
- Daily ranking of new courses with notifications.
- Events, admin report with CSV/Excel download and AI call log.
- Privacy API provider, "Delete my data", data retention task and redaction of personal identifiers.
- Security hardening: server-side AI policy, per-learner lock, forged-rating protection, safe downloads.
- Supports Moodle 4.5, 5.0, 5.1 and 5.2.
