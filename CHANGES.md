# Changes

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
