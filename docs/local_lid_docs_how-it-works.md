# How local_lid works — data flow and pipeline

This document explains the complete journey from a student submitting a forum post to a rendered Learning Intelligence Dashboard. Understanding this flow helps administrators troubleshoot issues, instructors set expectations, and developers extend the plugin.

---

## The short version

1. A student posts in a LID-enabled forum.
2. The plugin queues the post for analysis.
3. Moodle's cron task sends the post content to your configured LLM API along with the session analyzer prompt.
4. The LLM returns a structured JSON object (LID Schema v1.1).
5. The JSON is validated and stored in Moodle's database.
6. Aggregate dashboards are computed mathematically from stored post analyses — no additional LLM calls.
7. When a teacher opens a dashboard, the stored JSON is read from the database and rendered as a visual panel.

**The LLM is only called once per post.** Everything else — aggregation, rendering, all three dashboard surfaces — works from data already in the database.

---

## Step-by-step pipeline

### 1. Post submission

When a student submits a post to a forum where LID analysis is enabled, Moodle fires the `\mod_forum\event\post_created` event. The plugin's event observer (`classes/observer.php`) catches this and:

- Creates a row in `local_lid_analysis` with `scope = 'post'` and `status = 'pending'`
- Creates a row in `local_lid_queue` with the appropriate priority and `timevisible` timestamp

The post itself is not copied anywhere — only a reference to the post ID is stored at this stage.

### 2. Queue

The `local_lid_queue` table acts as a job queue. Each row represents one pending analysis. Items have a priority:

| Priority | Trigger |
|---|---|
| 1 — Manual | Teacher clicked Re-analyse in the dashboard UI |
| 2 — Async | Post was submitted while async trigger is enabled |
| 3 — Cron | Post is scheduled for the next batch run |

The `timevisible` field controls when the item becomes eligible for processing. Async items are visible immediately (pick up at the next cron run). Cron batch items are set to a future time based on the configured interval.

### 3. Scheduled task

Moodle's cron calls `\local_lid\task\process_queue` at the configured interval (default: every 5 minutes). Each run:

1. Claims up to `cron_batchsize` unclaimed queue items, ordered by priority then age.
2. Instantiates `session_analyser.php` once — this validates LLM configuration.
3. Processes each claimed item.

Claiming uses `timeclaimed = NOW()` as an atomic lock, preventing two concurrent cron runs from processing the same item.

### 4. Prompt assembly

For each queue item, `classes/llm/prompt_builder.php` assembles the full prompt sent to the LLM:

```
[Forum post preamble]          ← prompts/forum-post-preamble.md
                                  Calibrates the LLM for shorter forum content
[Active prompt template]       ← Resolved via: forum override → course override → site default
                                  Contains the full LID v1.1 rubric instructions
---
[Post content]                 ← The actual text of the student's forum post,
                                  stripped of HTML, with discussion subject for context
```

The active prompt template is resolved in priority order: forum-level override → course-level override → site-level default. If the site administrator has locked the prompt, overrides are ignored and the site default is always used.

A SHA-256 hash of the active template is stored alongside the analysis result so the dashboard can detect analyses that were run with an older prompt version.

### 5. LLM API call

`classes/llm/client.php` sends the assembled prompt to the configured API endpoint via HTTP POST. The request body follows the standard chat completions format:

```json
{
  "model": "your-configured-model",
  "max_tokens": 4096,
  "messages": [
    { "role": "user", "content": "...assembled prompt..." }
  ]
}
```

The client sends both `x-api-key` and `Authorization: Bearer` headers, making it compatible with Anthropic's API and any OpenAI-compatible endpoint (Google Gemini, OpenRouter, Ollama, etc.).

On failure, the item is retried up to 3 times with exponential backoff (5 minutes × attempt number). Truncated responses (detected by `schema_validator.php`) trigger a retry with doubled `max_tokens`.

### 6. Validation and storage

The raw LLM response is passed to `classes/analysis/schema_validator.php`, which:

- Strips any markdown code fences the LLM may have added
- Detects truncated responses (JSON that starts with `{` but doesn't end with `}`)
- Validates all required fields against the LID Schema v1.1 structure
- Coerces numeric strings to proper types (LLMs sometimes return `"75"` instead of `75`)
- Validates the Cognitive Performance Index block if present

**Fatal errors** (invalid JSON, missing required fields, wrong schema version) mark the analysis as `error` and trigger a retry.

**Warnings** (scores out of range, mismatched Bloom's labels) are stored in `meta.notes` and surfaced as a notice on the dashboard, but do not prevent storage.

On success, the validated JSON is stored in `local_lid_analysis.analysis_json` — a TEXT column in Moodle's MariaDB database. The analysis record is updated to `status = 'complete'`.

### 7. Aggregate computation

Immediately after a post analysis completes, `classes/analysis/aggregator.php` recomputes three aggregate rows:

| Scope | What it represents |
|---|---|
| `student_forum` | All of this student's posts in this forum, merged |
| `forum` | All posts across all students in this forum |
| `course` | All posts across all LID-enabled forums in this course |

**No LLM calls are made for aggregation.** The aggregator reads the existing post-scope JSON rows from the database and merges them mathematically:

- Scores are weighted averages (weighted by `session_hours` so longer posts count more)
- Competencies are merged by name — scores averaged, highest `bloom_level` kept, frameworks and tags unioned
- Bloom's progression takes the maximum `dots_active` seen at each level
- ROI accumulation fields (`lms_equivalent_hours`, `session_hours`, `knowledge_value_usd`) are summed
- The Cognitive Performance Index component scores are averaged, then the CPI is recomputed from the formula
- Timeline and employer value entries are unioned and deduplicated

Aggregate rows are also stored in `local_lid_analysis` with their respective `scope` values. They are recomputed every time a new post analysis completes, so they always reflect the current state of the forum.

### 8. Dashboard rendering

When a teacher opens any of the three dashboard surfaces, the page:

1. Queries `local_lid_analysis` for the relevant scope rows
2. Passes the `analysis_json` blobs through `renderer.php`, which decodes the JSON and maps fields to Mustache template variables
3. Returns fully rendered HTML — all cards, charts, and panels are generated server-side in PHP

The AMD JavaScript module (`dashboard.js`) then animates the visual elements on page load:

- Draws the SVG radar chart from the `data-axes` JSON attribute on the canvas element
- Animates competency bars from 0 to their target width using `IntersectionObserver`
- Builds the engagement bar chart from the `data-score` attribute
- Handles tab switching, post accordions, and manual trigger buttons

**No LLM calls happen at view time.** The dashboard is a pure read-and-render operation on data already in the database.

---

## Database tables

| Table | Purpose |
|---|---|
| `local_lid_settings` | LLM config, prompt template, and trigger settings per site/course |
| `local_lid_forum_config` | Per-forum enable/disable and optional prompt override |
| `local_lid_analysis` | All analysis results — post, forum, student_forum, and course scope |
| `local_lid_queue` | Pending analysis jobs, consumed by the scheduled task |

The `local_lid_analysis` table is the central store. It holds both raw post analyses (from the LLM) and computed aggregates (from the aggregator). The `scope` column distinguishes them.

---

## What the LLM actually receives and returns

**Input — what the LLM sees:**

The assembled prompt is approximately 3,000–4,000 tokens for a typical forum post. It contains:
- A calibration preamble explaining this is a forum post, not a full session
- The full LID v1.1 session analyzer prompt with all 11 scoring rubrics
- The actual post content (HTML-stripped, with discussion subject)

**Output — what the LLM returns:**

A raw JSON object conforming to LID Schema v1.1. Example structure (abbreviated):

```json
{
  "schema_version": "1.1",
  "session": {
    "id": "20260320-FORUM-K2X9",
    "title": "Discussion on instructional design principles",
    "source": "Moodle Forum",
    "duration_minutes": 8
  },
  "scores": {
    "cognitive_depth_score": 62,
    "strategic_thinking_pct": 45,
    "engagement_score": 58,
    "meta_cognition_score": 30
  },
  "cognitive_performance_index": {
    "cpi_score": 107,
    "cpi_band": "Proficient",
    "cpi_band_description": "...",
    "calculation_note": "Session-specific behavioral composite..."
  },
  "competencies": [...],
  "blooms_progression": [...],
  "roi": {...},
  "meta": {
    "confidence": "MEDIUM",
    "notes": "Rubrics: LI Dashboard v1.2. Knowledge value: $200/hr × 2.0 LMS hrs × 1.0× = $400..."
  }
}
```

This JSON — typically 2,000–4,000 characters — is what gets stored verbatim in `local_lid_analysis.analysis_json`.

---

## Timing expectations

| Event | When it happens |
|---|---|
| Post submitted | Immediately |
| Queue item created | Within seconds of post submission (async mode) |
| LLM call made | At next cron run (default: within 5 minutes) |
| Analysis complete | 5–30 seconds after LLM call, depending on model speed |
| Aggregates updated | Immediately after analysis completes |
| Dashboard updated | On next page load after analysis completes |

In manual trigger mode, the teacher clicks Re-analyse in the UI and the analysis is prioritised in the queue. The dashboard polls for completion every 3 seconds and reloads automatically when the analysis is ready.

---

## Cost model

LLM costs depend entirely on your provider and model choice. As a rough guide:

| Provider | Model | Approx. cost per post analysis |
|---|---|---|
| Anthropic | claude-haiku-4-5 | ~$0.001–0.003 |
| Google | gemini-2.0-flash | Free tier (1M tokens/day) |
| Ollama (self-hosted) | qwen2.5:7b | $0 (hardware cost only) |

A typical 200-word forum post generates approximately 3,000–4,000 input tokens and 1,500–2,500 output tokens per analysis. Aggregate computations involve no LLM calls.

---

*For configuration options, see [configuration.md](configuration.md).*  
*For trigger mode details, see [trigger-modes.md](trigger-modes.md).*  
*For schema details, see [schema-compatibility.md](schema-compatibility.md).*
