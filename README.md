# local_lid — Learning Intelligence Dashboard

A Moodle local plugin that brings the [Learning Intelligence Dashboard](https://github.com/your-org/learning-intelligence-dashboard) into Moodle forum activities, surfacing structured competency evidence, Bloom's Taxonomy progression, and ROI metrics from student discussion contributions.

**Moodle compatibility:** 4.5 LTS, 5.0, 5.1+  
**LID Schema:** v1.0  
**License:** GPL v3  
**Status:** Alpha — active development

---

## What it does

`local_lid` connects Moodle forum activity to an LLM API endpoint and applies the Learning Intelligence Session Analyzer prompt to student posts. It produces structured JSON conforming to the LID Schema v1.0, then renders that data as visual dashboards at three levels:

| Dashboard | Where it appears | Who sees it |
|---|---|---|
| **Course LID** | Course → Reports → Learning Intelligence | Teacher, Manager, Course Creator |
| **Forum LID** | Forum activity → Learning Intelligence tab | Teacher, Manager, Course Creator |
| **Student LID** | Course participants → [Student] → Learning Intelligence | Teacher, Manager, Course Creator |

Each dashboard shows a competency radar, Bloom's Taxonomy progression grid, ROI panel, and employer value indicators — aggregated from individual post analyses.

---

## How analysis works

1. A student submits a post to a LID-enabled forum
2. The post is queued for analysis (immediately, on a cron schedule, or on teacher request — configurable)
3. The active prompt template is combined with the post content and sent to the configured LLM API
4. The LLM returns a LID Schema v1.0 JSON object
5. The JSON is validated, stored, and used to update aggregate dashboards for that student, forum, and course

Aggregate dashboards (forum-level, student-level, course-level) are computed by mathematically merging per-post JSON scores — no additional LLM calls are needed for aggregation.

---

## Requirements

- Moodle 4.5 LTS or higher (tested on 5.1)
- PHP 8.1+
- Access to an LLM API endpoint that accepts the session analyzer prompt and returns LID Schema v1.0 JSON (tested with Claude via the Anthropic API; compatible with any OpenAI-compatible endpoint)
- `curl` enabled in PHP
- Moodle cron running (required for scheduled analysis mode)

---

## Installation

1. Download or clone this repository into `{moodleroot}/local/lid/`

```bash
cd /path/to/moodle/local
git clone https://github.com/your-org/local_lid.git lid
```

2. Log in to Moodle as Site Administrator
3. Navigate to **Site Administration → Notifications** — Moodle will detect the new plugin and run the installer
4. Navigate to **Site Administration → Plugins → Local plugins → Learning Intelligence Dashboard**
5. Enter your LLM API endpoint URL and API key
6. Review and optionally customise the default session analyzer prompt
7. Configure the analysis trigger mode and cron interval

For full installation instructions, see [`docs/installation.md`](docs/installation.md).

---

## Configuration overview

### Site administrator

All settings are at **Site Administration → Plugins → Local plugins → Learning Intelligence Dashboard**.

| Setting | Description |
|---|---|
| LLM API endpoint | URL of the LLM API (e.g. `https://api.anthropic.com/v1/messages`) |
| LLM API key | Stored encrypted in the Moodle database |
| LLM model string | Model identifier passed in API requests |
| Prompt template | The session analyzer prompt sent to the LLM. Editable via textarea or `.md` file upload. Pre-populated with the LID v1.0 default prompt on install. |
| Lock prompt | When enabled, teachers can view but not edit the prompt at course or forum level |
| Analysis trigger | `async` (on post submission), `cron` (scheduled), `manual` (teacher-initiated), or any combination |
| Cron interval | Minimum minutes between queue drain runs (1–1440) |
| Max items per cron run | Limits LLM API calls per scheduled task execution |

### Teacher / Course Creator / Manager

At the course level (**Course → Settings → Learning Intelligence**) and per forum (**Forum → Settings → Learning Intelligence**):

- Enable or disable LID analysis for individual forum activities
- View the active prompt (and edit it if the site administrator has not locked it)
- Upload a `.md` file to replace the prompt, or paste directly into the editor
- Manually trigger re-analysis of a forum or individual post

---

## Prompt customisation

The plugin ships with the LID Session Analyzer prompt v1.0 as its default template. This prompt instructs the LLM to analyze content and return a LID Schema v1.0 JSON object.

The prompt editor supports:
- **Direct editing** in a full-height textarea
- **Markdown file upload** — drag and drop or browse for a `.md` file; the content replaces the textarea value
- **Reset to site default** — restores the course or forum prompt to the site-level template
- **Reset to plugin default** — (admin only) restores the site-level prompt to the version shipped with the plugin

When the site administrator enables **Lock prompt**, the editor becomes read-only at course and forum level. The prompt is displayed for transparency but cannot be modified.

See [`docs/prompt-customisation.md`](docs/prompt-customisation.md) for guidance on writing effective custom prompts.

---

## Privacy and GDPR

This plugin sends forum post content to an external LLM API. Institutions are responsible for ensuring their LLM provider's data processing terms are compatible with their obligations under GDPR or equivalent regulations.

The plugin implements the Moodle Privacy API (`\local_lid\privacy\provider`):
- All stored analysis JSON is exportable per user data request
- Analysis records for a user can be deleted in response to a right-to-erasure request
- No post content is stored by the plugin — only the derived LID JSON output

See [`docs/gdpr-and-privacy.md`](docs/gdpr-and-privacy.md) for a full data flow description.

---

## LID Schema v1.0

Analysis output conforms to the Learning Intelligence Dashboard Schema v1.0. Key root-level fields:

```
schema_version, session, scores, competencies, radar,
blooms_progression, roi, timeline, employer_value, portfolio, meta
```

The full schema reference is at [`schema/lid-schema-v1.0.json`](schema/lid-schema-v1.0.json).  
Schema version history is at [`schema/SCHEMA_CHANGELOG.md`](schema/SCHEMA_CHANGELOG.md).

---

## Development

### Prerequisites

```bash
npm install -g grunt-cli
composer install
npm install
```

### Build AMD modules

```bash
grunt amd
```

### Run PHPUnit tests

```bash
vendor/bin/phpunit --testsuite local_lid
```

### Run Behat tests

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config behat.yml --suite local_lid
```

See [`docs/development.md`](docs/development.md) for full dev environment setup.

---

## Roadmap

### Phase 1 — Foundation (current)
- [x] Repository structure
- [ ] Database schema (`db/install.xml`)
- [ ] Capabilities (`db/access.php`)
- [ ] Admin settings page
- [ ] LLM client and prompt builder
- [ ] Schema validator
- [ ] Analysis queue and scheduled task

### Phase 2 — Dashboard surfaces
- [ ] Course LID (Reports tab)
- [ ] Forum LID (activity tab)
- [ ] Student LID (profile tab)
- [ ] AMD chart rendering (radar, Bloom's, competency bars)

### Phase 3 — Polish and testing
- [ ] PHPUnit test suite
- [ ] Behat scenarios
- [ ] Moodle 4.5 / 5.x compatibility verification
- [ ] Moodle Plugin Directory submission

---

## Related projects

- [learning-intelligence-dashboard](https://github.com/your-org/learning-intelligence-dashboard) — The standalone browser-based LID application this plugin extends into Moodle

---

## License

This plugin is released under the [GNU General Public License v3.0](LICENSE).

Copyright © 2026 — Learning Intelligence Dashboard Project Contributors
