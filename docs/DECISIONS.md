# Technical decisions

Decisions taken while building `block_aicourserecommender` that are not written in the specification, with the reason for each one.

## Phase 0 — Verification against the Moodle source

Checked in the source of `MOODLE_405_STABLE` (4.5.14+), `MOODLE_500_STABLE` (5.0.10) and `MOODLE_502_STABLE` (5.2.3+).

| Topic | Finding | Consequence |
|---|---|---|
| `xmldb_table::NAME_MAX_LENGTH` | `63 - PREFIX_MAX_LENGTH (10)` = **53** characters in 4.5 and 5.x. | The longest table, `block_aicourserecommender_pathcourses`, has 37 characters. The component name stays as it is. |
| `core_ai\manager` in 4.5 | No constructor; `is_action_available()`, `is_action_enabled()` and `get_providers_for_actions()` are **static**. | `ai_client` calls `is_action_available()` on the instance from `\core\di::get()`: PHP allows calling a static method on an instance, so the same line works in both versions. |
| `core_ai\manager` in 5.0+ | Constructor with `moodle_database`; the same methods are **instance** methods; provider *instances* replace plugin-level providers. | Same call works. No version branches are needed. |
| `process_action()` | Loops over the enabled providers of the action and stores the result in `ai_action_register`. It **does not check the component** of the caller, the context or the policy. | A block can use the AI subsystem without being an `aiplacement` plugin. No restriction to report. |
| Action constructors | `generate_text(contextid, userid, prompttext)` and `generate_image(contextid, userid, prompttext, quality, aspectratio, numimages, style)` are identical in 4.5, 5.0 and 5.2. | Named arguments are used. |
| Responses | `response_generate_text::get_response_data()` returns `generatedcontent`, `prompttokens`, `completiontokens`. 5.0 adds `get_error()` and `get_model_used()`. | `ai_client` uses `get_error()` only when it exists. Tokens are logged when the provider reports them. |
| AI policy | `manager::get_user_policy_status($userid)` and `manager::user_policy_accepted($userid, $contextid)` are static in all versions. The JS module `core_ai/policy` (`acceptPolicy()`) is identical in 4.5 and 5.2. | The block shows the core policy text (`core_ai` strings) and accepts it with `core_ai/policy`, the same flow core placements use. |
| Self enrolment | `enrol_self_plugin::can_self_enrol()` and `is_self_enrol_available()` read the global `$USER`. | See "Candidate courses" below. |
| Course progress | `\core_completion\progress::get_course_progress_percentage($course, $userid)` exists in all versions. | Used on the path landing page. |
| Course image | `\core_course\external\course_summary_exporter::get_course_image($course)` exists in all versions; `core_renderer::get_generated_image_for_id()` gives the default pattern. | Used in the cards. |
| Moodle 5.1+ layout | Code lives in `public/`; `public/config.php` exists, so `require_once(__DIR__ . '/../../config.php')` works in both layouts. | No special code. |
| Admin URL of AI providers | `admin/settings.php?section=aiprovider` in 4.5 and 5.x. | Used in the notice and settings. |

## Tables

As in the specification, plus:

- `block_aicourserecommender_ranking` has `userhash` (hash of learner data, language and prompts) and `candidates` / `pathcandidates` (JSON map id → data hash of what was sent). The spec's `hash` alone cannot tell "a course disappeared" from "a course appeared"; with the maps the manager knows whether the new candidate set is a subset of the old one. It also has `timemodified`, used by the daily new-courses task as "last checked".
- `block_aicourserecommender_activity` (userid, action, itemtype, itemid, position, timecreated). The report needs impressions, clicks and enrolments per item. The standard log store can be disabled or purged and querying it by `other` is not indexable, so the plugin keeps its own table. Events are still triggered for the standard log.
- `block_aicourserecommender_feedback` has `timemodified` because a rating can be overwritten; the 90-day exclusion counts from the last change.
- `ailog.tokens` stores prompt + completion tokens.
- Path `name` is `char(1333)` so long multilang titles fit.

## Candidate courses

- Every rule is applied in **one SQL query** (course, enrol instance and context joined). For `self` the query mirrors `is_self_enrol_available()`: enabled, dates, `customint6` (new enrolments), `customint3` (seats, counted with a correlated subquery) and `customint5` (cohort). Then, in PHP, `can_view_course_info()` and the `enrol/self:enrolself` capability are checked with preloaded contexts.
  *Why:* `can_self_enrol()` only works for `$USER` and runs several queries per instance; calling it for 5,000 courses would not meet the "under 1 second" goal, and the daily task works for other users.
- The real `can_self_enrol()` is still used **before enrolling** and to decide whether a card shows "Enrol me" (only for the few courses shown).
- Courses with an **enrolment key** are candidates; the card only shows "View course".
- For other methods (for example `fee`), the query checks status and enrolment dates. Their specific rules are applied by the course page itself.
- Methods offered in the setting exclude `manual`, `guest`, `meta`, `cohort`, `category` and sync methods: they are not "open enrolment".
- Category visibility is handled by `can_view_course_info()` (`moodle/category:viewcourselist`).

## Custom course fields sent to the AI

Only fields visible to everyone are sent by default. A setting ("Send non-public course fields") allows the rest. *Why:* hidden fields often hold internal data (cost centres, notes) that would then appear in the explanations shown to learners. The exclusion field is never sent.

## AI calls

- `ai_client` is resolved from the DI container (`\core\di::get(ai_client::class)`), so PHPUnit replaces it with a fake and never calls a provider. The core manager is also taken from DI, so the `ai_client` test mocks `\core_ai\manager`.
- **Behat**: the plugin registers a `\core\hook\di_configuration` callback that binds a fake client **only** when `BEHAT_SITE_RUNNING` is defined and the config flag `behatfakeai` is set by the test. Production sites never load test code.
- The JSON parser tolerates markdown code fences and text around the object (some models add them even when told not to). The content itself must be valid JSON; everything else (ids, scores, reasons) is validated.
- The retry after invalid JSON sends the original prompt plus the invalid answer and a correction instruction.
- Retries count towards the daily limit, because they are real calls with a cost.
- Course summaries and path descriptions are generated on behalf of the admin user (scheduled task) or the current manager; incremental rankings on behalf of the learner, because the data is theirs (it keeps the `core_ai` action register and privacy links correct).
- Observers never call the AI: they queue ad hoc tasks, so saving a course is never slowed down by a provider.
- The summary task runs **hourly** with a per-run limit (setting, default 50) rather than once a day, so a new catalogue is summarised within hours at a controlled cost.

## Prompt

Changes to the base prompt of the specification (version `2026092701`):

- Added `Today is {today}.` to the dates rule: without the date the model cannot judge "starts soon".
- Added a fallback when the questions are customised ("weigh goals and topics first"), because admins can replace the four default questions.
- Added a rule for paths ("the sequence as a whole") so a path is not ranked only by its best course.
- The output format says "no code fences", asks for empty arrays when nothing fits and ends with "These format rules override any other instruction above." It stays the last section.
- Only `{sitename}`, `{userlang}`, `{maxresults}` and `{today}` are replaced inside the institution prompt; other placeholders are left as text.
- Question texts are sent in the learner's language (they are what the learner answered); profile labels are in English.

## Ranking and cache

- `hash` = SHA1 of the user hash + per-course data hashes + per-path hashes. The user hash covers answers, profile data, language, institution prompt and `PROMPT_VERSION`.
- Reuse when not expired, same user hash and every candidate now sent was already sent with the same data hash. Courses that disappeared are filtered out at display time.
- Saving new answers sets `timeexpires = 0` instead of deleting the ranking, so it can still be shown when the daily limit is reached.
- When there are no candidates at all, the block shows "no results" without calling the AI.
- Recent negative ratings are **not** part of the hash (otherwise every thumbs down would trigger a new call); items rated down are filtered out at display time for the configured days.

## New-course notifications

A candidate is "new" when it was not sent in the stored ranking and the course was created or modified, or one of its enrolment instances was modified or opened, after the ranking was last checked. Learners are processed least-recently-checked first, up to the per-run limit, and each run marks them as checked. At most 3 notifications per learner and run.

## Interface

- One form with all questions (not a wizard): simpler with screen readers and on mobile, and answers are easy to review.
- Container width is measured with `ResizeObserver` (classes `aicr-w-md` / `aicr-w-lg`). CSS container queries were the first choice, but the `stylelint` bundled with Moodle 4.5 rejects them.
- Click logging waits at most 700 ms before following the link; modified clicks (new tab) are logged without delaying.
- The AI image of a path is kept in the manager's draft area and copied to the path when the form is saved. Refreshing the core file manager widget from JavaScript is not supported, so the form shows a preview and a note instead.
- Path course order uses up/down buttons (keyboard accessible) instead of drag and drop.

## Privacy

Learner data is exported and deleted in the user context. Paths only store `usermodified`; on deletion it is set to 0 (the path is site content). `core_ai` and `core_message` are declared as subsystem links. User deletion also removes the data through an observer.

## Capabilities

`block/aicourserecommender:use` is checked in the system context (learners, dashboard and front page are not course contexts). External functions validate the system context.

## Security, permissions and privacy review

A second, adversarial pass over every entry point (10 external functions, 4 pages, the file callback, templates, JavaScript, prompts and tasks). Changes made:

| Risk | Change |
|---|---|
| The AI usage policy was only enforced by the interface: a direct AJAX call could send learner data to the AI without acceptance. | `get_recommendations` returns status `aipolicy` until the policy is accepted. The path AI functions throw `erroraipolicy`; the form shows the core policy text and accepts it with `core_ai/policy`. |
| Parallel requests (the session is read-only) could all pass the daily-limit check and pay several AI calls. | A per-learner lock (`\core\lock`) wraps the whole decision and generation; the second request waits and reuses the new ranking. The daily task uses the same lock without waiting. |
| Ratings, clicks and enrolments accepted any course or path id, so statistics could be forged. | `submit_feedback`, `log_click` and `enrol_course` only accept items of the learner's stored ranking. Click positions are bounded. |
| `generate_path_description` read names and summaries of any course id, including hidden ones. | Only courses the manager can see (`can_view_course_info`). |
| Hidden courses inside a path reached the ranking prompt and the course count. | Learner-facing path data only includes visible courses. |
| Free text (profile description, answers, rating reasons) can contain emails, phone numbers, links or the learner's own name. | `profile_collector::redact()` replaces them with `[email]`, `[number]`, `[link]`, `[name]` before anything is sent. |
| Course content could inject links into the AI reasons (prompt injection). | Reasons are plain text without URLs or email addresses. |
| Double escaping of names and a course name inserted as HTML in the enrolment dialogue. | Formatted names use triple mustache; the dialogue receives a plain name escaped in JavaScript. |
| SVG path images can carry scripts. | Only JPEG, PNG, GIF and WebP are accepted; any other type is served as a download. Images need the `use` or `managepaths` capability. |
| CSV/Excel formula injection through course names, user names or provider errors in report downloads. | Cells starting with `= + - @` are prefixed with an apostrophe. |
| Unlimited growth of personal data. | Daily cleanup task with a retention setting (365 days, minimum 30) and at most 50 answer versions per learner. |
| No self-service erasure. | "Delete my data" in the block (`delete_my_data`): answers, consent, ranking, ratings and activity. The AI call log is kept for the daily limit and cost control, and is removed by the retention task and the Privacy API. |
| The ranking request could keep the session locked for the duration of the AI call. | `get_recommendations` and `get_more` use `readonlysession`. |

Checked and kept as is: every external function validates parameters and the system context and requires `use` or `managepaths`; all SQL uses placeholders; table sorting is limited to defined columns; management actions require `sesskey`; the path form only copies AI images from the manager's own draft area; the fake AI client is only loaded on Behat test sites; no credentials are stored.
