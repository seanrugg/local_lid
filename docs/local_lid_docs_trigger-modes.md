# Trigger modes

The plugin supports three trigger modes that control when LLM analysis runs. They can be used individually or in combination.

---

## How triggering works

Every trigger mode writes to the same `local_lid_queue` table. The scheduled task (`process_queue`) drains the queue at the configured interval regardless of which trigger created the queue item. The difference between trigger modes is **when** the queue item is created and **what priority** it receives.

```
Post submitted / teacher clicks Re-analyse
            ↓
    local_lid_queue row created
    (priority + timevisible set by trigger mode)
            ↓
    Scheduled task runs (at cron interval)
            ↓
    Claims eligible items (timevisible ≤ now, not claimed)
            ↓
    Sends to LLM, stores result
```

---

## Immediate (async)

**Setting:** Trigger → Immediate (async) = enabled

When a student submits a post, a queue item is created immediately with `timevisible = now`. It will be picked up at the next scheduled task execution.

**Priority:** 2 (medium)

**Typical latency:** Post → analysis complete within 5–10 minutes (assuming default 5-minute cron interval and a responsive LLM endpoint).

**Best for:**
- Courses where instructors check the dashboard frequently
- Assessments where prompt feedback timing matters
- Low-to-medium volume forums

**Consideration:** If many students post simultaneously (e.g. at the end of a deadline), posts queue up and are processed in batches. With `cron_batchsize = 10` and 30 students posting at once, all posts are processed within 3 cron runs (~15 minutes at default interval).

---

## Scheduled (cron)

**Setting:** Trigger → Scheduled (cron) = enabled

Queue items are created with `timevisible` set to the next scheduled batch time (current time + cron interval). This deliberately delays processing until the next batch window.

**Priority:** 3 (lowest)

**Typical latency:** Post → analysis complete at next batch window, then within one cron run.

**Best for:**
- High-volume courses (hundreds of posts per day)
- Institutions on API free tiers with rate limits
- Instructors who grade asynchronously and don't need real-time analysis

**Example:** With a 60-minute interval and `cron_batchsize = 20`, up to 480 posts per day can be processed (24 runs × 20 items). For a course with 30 students posting once per week, this is more than sufficient.

---

## Manual (teacher request)

**Setting:** Trigger → Manual (teacher request) = enabled

No automatic queue item is created on post submission. The teacher explicitly triggers analysis by clicking:
- **Re-analyse** on an individual post row in the Forum LID dashboard
- **Re-analyse all posts** on the Forum LID or Course LID header

**Priority:** 1 (highest — processed before async and cron items)

**Typical latency:** Teacher clicks → analysis complete within one cron run (usually under 5 minutes).

**Best for:**
- Instructors who want to review all posts before triggering analysis
- Discussion forums where analysis is part of the grading workflow, run after the discussion closes
- Situations where API costs need to be controlled — only analyse posts the instructor explicitly selects

**Note:** When manual-only mode is active (async and cron both disabled), posts accumulate in `local_lid_analysis` with `status = pending` indefinitely until a teacher triggers them. The pending count is visible on the Forum LID tab.

---

## Combining trigger modes

All three modes can be enabled simultaneously. This is the default configuration and suits most deployments:

- New posts are queued immediately (async)
- The cron processes them on schedule
- Teachers can force-process any post immediately (manual) if they don't want to wait

A common pattern for assessment-heavy courses:
- Enable async and manual
- Disable scheduled (cron)
- Posts queue up automatically, but the instructor triggers the batch manually after the discussion closes to ensure all posts are captured before analysis runs

---

## Cron interval vs. batch size

These two settings work together to control throughput:

```
Posts processed per day = (1440 minutes ÷ cron_interval) × cron_batchsize
```

Examples:

| Interval | Batch size | Posts/day | Use case |
|---|---|---|---|
| 1 min | 5 | 7,200 | High-volume institutional deployment |
| 5 min | 10 | 2,880 | Default — suitable for most courses |
| 15 min | 20 | 1,920 | Moderate volume |
| 60 min | 20 | 480 | Low volume, API rate limit conscious |
| 1440 min | 50 | 50 | Overnight batch only |

**API rate limits:** If your LLM provider has a requests-per-minute limit (e.g. Google Gemini free tier: 15 RPM), set `cron_batchsize` to no more than `limit × (interval_minutes)`. For Gemini free tier with a 5-minute interval: `15 × 5 = 75` max batch size (though 10–20 is more realistic given response times).

---

## Retry behaviour

If a LLM call fails, the queue item is retried automatically:

| Attempt | Wait before retry |
|---|---|
| 1st failure | 5 minutes |
| 2nd failure | 10 minutes |
| 3rd failure (final) | Marked as permanent error |

After 3 failed attempts, the analysis record is set to `status = error` with the error message stored in `error_message`. The teacher can manually re-trigger the analysis from the dashboard UI, which resets the attempt counter.

Common failure reasons:
- API key invalid or expired
- Model name incorrect
- LLM returned truncated JSON (increase max_tokens)
- Network timeout (increase request timeout setting)
- Rate limit exceeded (reduce batch size or increase interval)
