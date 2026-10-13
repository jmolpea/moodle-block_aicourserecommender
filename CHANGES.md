# Changes

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
