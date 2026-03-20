# Configuration

All settings are at **Site Administration → Plugins → Local plugins → Learning Intelligence Dashboard**.

---

## LLM API connection

### LLM API endpoint

The full URL of the LLM API. The plugin supports any endpoint that accepts a POST request with a JSON body in the standard chat completions format:

```json
{
  "model": "model-name",
  "max_tokens": 4096,
  "messages": [{ "role": "user", "content": "..." }]
}
```

**Provider examples:**

| Provider | Endpoint URL |
|---|---|
| Anthropic (Claude) | `https://api.anthropic.com/v1/messages` |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai/chat/completions` |
| OpenRouter | `https://openrouter.ai/api/v1/chat/completions` |
| Ollama (local) | `http://localhost:11434/v1/chat/completions` |
| Any OpenAI-compatible | `https://your-endpoint/v1/chat/completions` |

### API key

Your provider's API key. Stored in Moodle's `config_plugins` table. Leave blank to keep an existing key — the field will appear empty on re-visit even when a key is saved.

For Ollama (which has no authentication), enter any non-empty string such as `ollama`.

**Getting a free API key:**
- **Google Gemini free tier:** Sign in at [aistudio.google.com](https://aistudio.google.com), click *Get API key*. Free tier: 15 requests/minute, 1M tokens/day. No credit card required.
- **Anthropic:** Sign up at [console.anthropic.com](https://console.anthropic.com). New accounts receive $5 free credit.
- **OpenRouter:** Sign up at [openrouter.ai](https://openrouter.ai). Some models are free.

### Model

The model identifier string passed in every API request. Must exactly match your provider's model name.

**Recommended models by provider:**

| Provider | Model string | Notes |
|---|---|---|
| Anthropic | `claude-haiku-4-5-20251001` | Fast, cheap, excellent JSON output |
| Anthropic | `claude-sonnet-4-6` | Higher quality scoring, higher cost |
| Google Gemini | `gemini-2.0-flash` | Free tier, good instruction following |
| OpenRouter | `anthropic/claude-haiku-4-5` | Via proxy |
| Ollama | `qwen2.5:7b` | Best small model for structured JSON |

**Model requirements:** The LID v1.1 prompt contains detailed scoring rubrics (~3,000 tokens). The model must be capable of following complex instructions and producing valid JSON. Models smaller than 7B parameters tend to produce malformed output or ignore rubric instructions.

### Max tokens

Maximum tokens to request in each API response. Default: `4096`. 

The LID v1.1 JSON output for a single forum post is typically 1,500–2,500 tokens. If analyses are returning with truncated JSON (visible as `error` status with "truncated" in the error message), increase this value. The `schema_validator.php` automatically detects truncation and retries with doubled `max_tokens` on the first retry.

### Request timeout

HTTP timeout in seconds. Default: `60`. Increase for slow endpoints or large forum threads. Self-hosted models (Ollama) may need 90–120 seconds on slower hardware.

---

## Prompt template

The session analyzer prompt sent to the LLM along with each forum post. Pre-populated with the LID v1.1 default prompt on installation.

### Editing the prompt

The prompt can be edited directly in the textarea, or replaced by uploading a `.md` file using the **Upload .md** button. The file content replaces the textarea value — you still need to save the settings form to persist it.

### Locking the prompt

When **Lock prompt** is enabled, teachers, course creators, and managers can view the active prompt at course or forum level but cannot edit it. The prompt editor renders as read-only text. Only Site Administrators can edit the prompt when it is locked.

Use this when you want consistent, standardised analysis across all courses in your institution.

### Resetting to plugin default

The **Reset to plugin default** button (admin only) reloads the prompt text from `prompts/default-session-analyzer.md` in the plugin directory. The form must still be submitted to save. This does not affect any analyses already stored in the database.

---

## Analysis triggers

Three trigger modes control when the LLM is called. All three can be enabled simultaneously.

### Immediate (async)

When enabled, a queue item is created as soon as a post is submitted. The item is picked up at the next scheduled task execution (within minutes if cron is running frequently).

**Best for:** Courses where instructors want near-real-time analysis. Students see their analysis soon after posting.

### Scheduled (cron)

When enabled, posts are batched and processed on the configured schedule. Queue items are held until `timevisible` is reached.

**Best for:** High-volume courses where processing all posts immediately would be expensive or slow. Also useful when instructors want to review all posts before triggering analysis.

### Manual (teacher request)

When enabled, teachers can click **Re-analyse** buttons in the dashboard UI to trigger analysis of specific posts or entire forums. Manual triggers receive priority 1 in the queue (processed before async and cron items).

**Best for:** Instructors who want control over when analysis runs, e.g. after all students have posted to a discussion.

### Cron interval

How often the scheduled task runs, in minutes. Range: 1–1440.

- **1 minute:** Maximum frequency. Suitable for high-volume environments where near-real-time analysis is needed.
- **5 minutes (default):** Good balance of responsiveness and server load.
- **60 minutes:** Hourly batch. Lower API call frequency.
- **1440 minutes:** Once per day (midnight). Lowest API usage, analysis available next day.

Changing this setting updates the scheduled task's cron expression immediately — no manual intervention in the Moodle task scheduler is required.

### Max items per cron run

Maximum posts analysed per scheduled task execution. Default: `10`. Limits LLM API call volume per run.

Calculate your maximum: if your LLM provider limits you to 15 requests/minute (Google Gemini free tier) and your cron runs every 5 minutes, set this to 15 or fewer.

---

## Course and forum level settings

### Course-level prompt override

Teachers with `local/lid:editprompt` can set a course-specific prompt at **Course → [Course Settings] → Learning Intelligence**. This overrides the site default for all forums in the course (unless a forum-level override also exists).

Not available when the site administrator has enabled **Lock prompt**.

### Forum-level enable/disable

Teachers with `local/lid:configureforum` can enable or disable LID analysis per forum. When disabled:
- No new analyses are queued for posts in that forum
- The Learning Intelligence tab does not appear on the forum
- Existing analysis data is preserved and remains accessible

### Forum-level prompt override

When the prompt is not locked, teachers can set a forum-specific prompt that overrides both the course and site prompts for that forum only. Useful for forums with a specific assessment focus that benefits from a customised rubric.

---

## Capabilities

| Capability | Default roles | Description |
|---|---|---|
| `local/lid:managesitesettings` | Manager | Configure API, prompt, locks |
| `local/lid:viewcoursedashboard` | Teacher, Manager | Course LID in Reports tab |
| `local/lid:viewforumdashboard` | Teacher, Manager | Forum LID tab |
| `local/lid:viewstudentdashboard` | Teacher, Manager | Student LID on profile |
| `local/lid:configureforum` | Editing Teacher, Manager | Enable/disable per forum |
| `local/lid:editprompt` | Editing Teacher, Manager | Edit prompt (when not locked) |
| `local/lid:triggeranalysis` | Teacher, Manager | Manual re-analyse button |

Capabilities can be overridden per role at **Site Administration → Users → Permissions → Define roles**.
