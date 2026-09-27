# AI course recommender (block_aicourserecommender)

A Moodle block that recommends courses and learning paths from your catalogue using the **Moodle AI subsystem**. Each learner answers a few questions (typed or dictated). The block combines those answers with some profile data and asks your AI provider for a ranked list of **courses** and **learning paths**, each with a short explanation in the learner's language.

Free and open source (GPL v3 or later). Made by [Pluginia](https://pluginia.es).

## Features

- Block for the **site front page** and the **Dashboard** only. Only logged-in users see it (never guests).
- Configurable questionnaire: four default questions translated to English, Spanish and Portuguese, up to six in total. Free-text answers with **voice dictation** (Web Speech API).
- Only **valid candidate courses** are recommended: visible, not finished, open enrolment, not enrolled yet, optional category filter, optional "Do not recommend" course field, and the learner can see the course.
- Recommendations come in two sections, **Courses** and **Learning paths**, with an explanation for each. "See more" pages through the stored ranking **without new AI calls**.
- **Direct enrolment** from the card (self enrolment without key), with confirmation.
- 👍 / 👎 ratings with an optional reason. Items rated down are hidden for 90 days and sent to the AI as "not relevant".
- **Learning paths**: ordered sequences of courses with a landing page, progress, and "Enrol me in the whole path". The AI can write the description in every installed language (multilang) and generate the image.
- Ranking cache per learner (24 h by default) and a **daily limit** of AI requests per learner.
- Daily task that ranks **new courses** for active learners and **notifies** the relevant ones.
- AI course summaries (about 80 words, in English) are generated once per course and refreshed only when the course changes, to keep prompts small.
- Admin **report**: learners, AI requests and errors, impressions, clicks, CTR, enrolments, ratings, top courses and paths, AI call log. CSV and Excel download.
- Privacy API (export and deletion), standard log events, accessibility (keyboard, screen readers, WCAG 2.1 AA).

## Requirements

- Moodle **4.5** or later (tested on 4.5, 5.0, 5.1 and 5.2), PHP 8.1+ (as required by your Moodle version), MySQL/MariaDB or PostgreSQL.
- An **AI provider** configured in *Site administration > General > AI > AI providers* with the **Generate text** action enabled (for example OpenAI or Azure AI).
- Optional: a provider with **Generate image** enabled, for learning path images.
- The **Multi-language content** filter if you want multilang titles, questions and path descriptions.

The plugin does not store any API key and does not call any external service directly: every AI request goes through `core_ai`, so your provider settings, rate limits and the AI usage policy of your site apply.

## Installation

1. Download the ZIP from the Moodle plugins directory, or clone this repository into `blocks/aicourserecommender` (in Moodle 5.1+: `public/blocks/aicourserecommender`).
2. Go to *Site administration > Notifications* and complete the installation.
3. Configure an AI provider with *Generate text* enabled if you have not done it yet.

## Configuration step by step

*Site administration > Plugins > Blocks > AI course recommender > Settings*

1. **Artificial intelligence**: check the provider status. Optionally write the *institution prompt* (see below). Adjust the cache lifetime (24 h), the daily limit per learner (5) and the maximum courses per request (120).
2. **Questions**: keep the four default questions (translated automatically) or write your own. Leave a text empty to use the default. Up to six questions; at least one active.
3. **Profile data**: choose the standard fields sent to the AI (city, country, institution, department, interests, description) and any custom profile field. Names, email, username, id number, phone, address, IP and ids are never sent.
4. **Catalogue**: optional category filter, enrolment methods that make a course a candidate (self by default), and a course custom field of type checkbox that means "Do not recommend".
5. **Results**: courses shown (4), courses ranked (24), paths shown (2), paths ranked (6), notification threshold (70) and days a thumbs-down item stays hidden (90).
6. **Learning paths**: enable or disable path recommendations and open *Manage learning paths*.
7. **Notifications**: new-course notifications, learner activity window (90 days) and learners per run (200).
8. **Privacy**: require consent (on by default) and the notice text.

Then add the block: turn editing on in the front page (in Moodle 5.2+ the site home is only available to logged-in users when *Enable site home* is on; otherwise use the Dashboard) or in *Site administration > Appearance > Default Dashboard page*, add *AI course recommender*, and reset the Dashboard for all users if needed. Learners can also add it to their own Dashboard.

The course summaries are generated by the scheduled task *Generate AI course summaries* (hourly, 50 courses per run by default). Until a course has a summary, its description (cut to 600 characters) is sent instead.

## Writing the institution prompt

The institution prompt changes tone, style and priorities. It cannot change the rules, the candidate list or the output format: the format instructions are always sent last. Available variables: `{sitename}`, `{userlang}`, `{maxresults}`, `{today}`.

**Neutral**

```
Use a clear, neutral and respectful tone. Keep each reason short and concrete.
When two items fit equally well, prefer the one that starts sooner.
```

**Commercial**

```
Use a warm and motivating tone. Highlight certified courses and practical outcomes
that help the learner in their job. You may mention when a course starts soon.
Do not exaggerate and never promise results.
```

**Academic**

```
Use a formal, academic tone. Prioritise courses with assessment and certification,
and sequences that build knowledge progressively. Mention the required prior level
when it is relevant for {sitename} learners.
```

## Limitations

- **Large catalogues**: at most *Maximum courses per request* (120) candidates are sent in one request. When there are more, a local word match between the learner data and the course names, tags, summaries and categories keeps the best ones before calling the AI. Very generic answers may leave out relevant courses; more specific answers or the category filter help.
- **Voice dictation** uses the browser's Web Speech API. It is available in Chrome, Edge and Safari; the microphone button is hidden in browsers without support (for example Firefox). Some browsers send the audio to their manufacturer to transcribe it; the default privacy notice says so.
- Only **self enrolment without an enrolment key** allows direct enrolment from the block. Courses with a key or other methods (for example payment) are recommended with a link to the course page.
- AI answers depend on your provider and model. Invalid answers are retried once; if they still fail, the learner sees an error and can try again.

## Privacy

With consent enabled (default), learners see a notice before the first request. Then the Moodle AI usage policy must be accepted, as for any other AI feature.

Sent to the AI provider through `core_ai`: the selected profile fields, the answers, the learner's language, the names and reasons of the last 10 items rated down, and course and path data (name, category, dates, tags, public custom fields, summary). Never names, email, username or identifiers.

Before anything is sent, email addresses, phone-like numbers, links and the learner's own names are removed from the free text (profile description, answers and rating reasons).

Stored by the plugin: answers and their history (last 50 versions), consent date, the last ranking, ratings, impressions and clicks, and a log of AI requests. A daily task deletes records older than the retention period (365 days by default). Learners can delete their answers, consent, ranking, ratings and activity at any time with **Delete my data** in the block. Everything is included in the Privacy API export and deletion, and removed when a user is deleted.

Security: the AI usage policy is enforced on the server, one ranking runs at a time per learner, ratings and clicks are only accepted for items actually recommended, hidden courses never reach the prompts, AI explanations are plain text without links, and report downloads are protected against spreadsheet formula injection. Details in [docs/DECISIONS.md](docs/DECISIONS.md).

## Approximate cost per request

Estimates with the default settings, counting about 150 tokens per candidate course (name, category, dates, tags, public fields and an 80-word summary), about 900 tokens of fixed instructions and learner data, and about 70 tokens per ranked item in the answer:

| Request | Input tokens | Output tokens |
|---|---|---|
| Ranking with 20 candidate courses | ~4,000 | ~1,500 |
| Ranking with 120 candidate courses (maximum) | ~19,000 | ~2,200 |
| Course summary (once per course and change) | ~500–1,500 | ~150 |
| Learning path description (3 languages) | ~800 | ~600 |

With a small model priced at USD 0.15 per million input tokens and USD 0.60 per million output tokens, a ranking with 120 courses costs about **USD 0.004** and one with 20 courses about USD 0.0015. Prices change; check your provider. The cache (24 h), "See more" without new calls and the daily limit keep the number of requests low. Check the *AI requests* table of the report for the real token usage of your provider.

## Performance

Candidate courses are computed with one SQL query plus preloaded contexts. With 5,000 courses the calculation takes well under one second (see `tests/local/candidate_finder_performance_test.php`; run it with `BLOCK_AICOURSERECOMMENDER_PERF=5000`). The block never waits for the AI while the page is rendered: results are loaded by AJAX.

## Development

- Tests: PHPUnit (`vendor/bin/phpunit --testsuite block_aicourserecommender_testsuite`) and Behat (`--tags @block_aicourserecommender`). Tests never call a real provider: PHPUnit replaces the AI client through the DI container, and Behat uses a fake client that is only enabled on Behat test sites.
- CI: GitHub Actions with moodle-plugin-ci on Moodle 4.5, 5.0, 5.1 and 5.2, PHP 8.1 to 8.4, PostgreSQL and MySQL.
- Technical decisions: [docs/DECISIONS.md](docs/DECISIONS.md). Changes: [CHANGES.md](CHANGES.md).

## License

2026 Pluginia. GNU GPL v3 or later: <https://www.gnu.org/copyleft/gpl.html>.
