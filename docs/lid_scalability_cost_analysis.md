# LID Plugin — Scalability & Cost Analysis
**Version:** 1.0  
**Date:** 2026-03-21  
**Model:** Google Gemini 2.5 Flash (via Google AI Studio / Vertex AI)  
**Prepared by:** Learning Intelligence Dashboard Project

---

## 1. Observed Baseline Data

The following figures are drawn from live production usage of the LID plugin during alpha and early beta testing at lms.cucorn.com.

| Metric | Value | Notes |
|---|---|---|
| Model | Gemini 2.5 Flash | Google AI Studio |
| Total tokens consumed | ~3.03M | Across all testing to date |
| Total cost observed | $6.70 USD | Against $300 signup credit |
| Posts analyzed (alpha) | 200 posts | 67 + 67 + 66, per-post sequential model |
| Requests per model (Google AI Studio) | 574 | Billable inference calls |
| Total API requests (March 20) | 581 | Includes all HTTP calls |
| Total API requests (March 21, partial) | 273 | Day not complete at time of capture |

### 1.1 Derived Token Metrics

```
Average tokens per post (alpha model):    3,030,000 ÷ 200 ≈ 15,150 tokens/post
Average cost per post (alpha model):      $6.70 ÷ 200 ≈ $0.0335/post
Average cost per 1,000 tokens:            $6.70 ÷ 3,030 ≈ $0.00221/1K tokens
```

These figures reflect the **alpha per-post model** — one LLM call per forum post, called sequentially. This is the worst-case consumption pattern and represents the ceiling for cost estimation purposes.

### 1.2 Request Count Discrepancy Explained

The difference between "requests per model" (574) and "total API requests" (581+) reflects how Google AI Studio counts differently at different layers:

- **Requests per model** = billable inference completions — calls that reached the model and returned output
- **Total API requests** = all HTTP calls to the endpoint, including authentication handshakes, quota checks, rate-limit polls, retried requests, and calls that failed before reaching the model

The gap is small (~1.2%) and consistent with normal API overhead. For cost modelling, **requests per model** is the correct figure to use. For capacity planning and rate-limit design, **total API requests** is the more conservative and appropriate figure.

---

## 2. Architecture Models

Three architecture models are compared across all scale scenarios. Cost and token projections differ significantly between them.

### Model A — Alpha (Per-Post Sequential)
One LLM call per forum post. Every post is analyzed individually regardless of which student wrote it or how many posts that student has. This was the initial implementation used to validate the scoring rubrics.

**Characteristics:**
- Highest token consumption — full prompt overhead per post
- No batching efficiency
- Simplest implementation
- Cost scales linearly with post count, not learner count

### Model B — Beta (Student-Forum Batch)
One LLM call per learner per forum. All posts by a given learner across all threads in a forum are batched into a single analysis call. Thread and forum aggregate views are computed mathematically from student_forum results — no additional LLM calls.

**Characteristics:**
- Significantly reduced call count — scales with learner count, not post count
- Prompt overhead paid once per learner regardless of post volume
- Thread and course aggregates are free (math, not LLM)
- Stale detection avoids re-analyzing unchanged content
- Cost scales with learners × forums, not total post count

### Model C — Projected Optimized
Building on Model B with additional efficiency measures that are architecturally feasible in future releases.

**Projected optimizations:**
- Prompt compression — reduce system prompt token overhead via a stripped production prompt variant
- Response schema enforcement — structured JSON output reduces verbose reasoning tokens
- Learner delta analysis — on re-run, only analyze posts submitted since last analysis rather than full history
- Tiered analysis — short posts (under 40 words) contribute to engagement score only; no LLM call generated for engagement-only learners
- Caching of identical content — hash-based detection of unchanged post sets skips LLM call entirely

**Estimated efficiency gain over Model B:** 35–50% token reduction

---

## 3. Cost Projections by Scale

Gemini 2.5 Flash pricing used for all projections:
- **Input tokens:** $0.075 per 1M tokens (prompts under 200K tokens)
- **Output tokens:** $0.30 per 1M tokens

These are the current public rates as of March 2026. Verify current pricing at [ai.google.dev/pricing](https://ai.google.dev/pricing) before production deployment decisions.

For all models, the following assumptions apply unless noted:
- Average posts per learner per forum: **6 posts**
- Average tokens per student_forum call (Model B): **~8,000 tokens** (estimated — includes full post content + system prompt; lower than per-post because prompt overhead is amortized)
- Average tokens per student_forum call (Model C): **~5,000 tokens** (compressed prompt + delta analysis)
- Forums per course: **4** (typical for this use case)
- Analysis frequency: **once per forum close** (retrospective model)

---

### 3.1 Single Course (1 Forum, ~30 Learners)

| Metric | Model A (Alpha) | Model B (Beta) | Model C (Optimized) |
|---|---|---|---|
| LLM calls | 180 (30 × 6 posts) | 30 | ~20 (engagement-only learners excluded) |
| Est. tokens | 2,727,000 | 240,000 | 100,000 |
| Est. cost (USD) | $0.82 | $0.07 | $0.03 |
| Cost per learner | $0.027 | $0.002 | $0.001 |

**Notes:** At single-course scale, all three models are inexpensive. Model B represents a 10× cost reduction over Model A. This scale is suitable for proof-of-concept and pilot deployments with negligible cost risk.

---

### 3.2 Program Scale (10 Courses, ~300 Learners, 4 Forums Each)

| Metric | Model A (Alpha) | Model B (Beta) | Model C (Optimized) |
|---|---|---|---|
| LLM calls | 7,200 (300 × 6 × 4) | 1,200 (300 × 4) | ~800 |
| Est. tokens | 109,080,000 | 9,600,000 | 4,000,000 |
| Est. cost (USD) | $32.72 | $2.88 | $1.20 |
| Cost per learner | $0.109 | $0.010 | $0.004 |
| Annual (3 terms) | $98.16 | $8.64 | $3.60 |

**Notes:** Model A begins to feel meaningful at program scale, particularly annualized. Model B keeps per-learner cost well under $0.01 per forum — essentially negligible even for institutions with modest budgets. Annual figures assume 3 terms with fresh cohorts.

---

### 3.3 Institution Scale (50 Courses, ~1,500 Learners, 4 Forums Each)

| Metric | Model A (Alpha) | Model B (Beta) | Model C (Optimized) |
|---|---|---|---|
| LLM calls | 36,000 | 6,000 | ~4,000 |
| Est. tokens | 545,400,000 | 48,000,000 | 20,000,000 |
| Est. cost (USD) | $163.62 | $14.40 | $6.00 |
| Cost per learner | $0.109 | $0.010 | $0.004 |
| Annual (3 terms) | $490.86 | $43.20 | $18.00 |

**Notes:** Model A at institution scale produces real cost that would need budget allocation. Model B remains highly affordable at ~$43/year for 1,500 learners across 50 courses — well within incidental software budget for most HE institutions. Rate limiting and queue management become important operational considerations at this scale, not cost.

**Rate limit consideration:** 6,000 LLM calls per term. If distributed across a 14-week term with end-of-unit forum closes clustered in weeks 4, 8, 12, and 14, peak demand could be ~1,500 calls within a narrow window (24–48hrs). Gemini 2.5 Flash supports high RPM limits under standard quota, but queue depth management in `process_queue` becomes important. Current implementation handles this via sequential drain — a parallel batch approach would be worth evaluating at this scale.

---

### 3.4 Enterprise Scale (200+ Courses, 5,000+ Learners, 4 Forums Each)

| Metric | Model A (Alpha) | Model B (Beta) | Model C (Optimized) |
|---|---|---|---|
| LLM calls | 120,000 | 20,000 | ~13,000 |
| Est. tokens | 1,818,000,000 | 160,000,000 | 65,000,000 |
| Est. cost (USD) | $545.40 | $48.00 | $19.50 |
| Cost per learner | $0.109 | $0.010 | $0.004 |
| Annual (3 terms) | $1,636.20 | $144.00 | $58.50 |

**Notes:** Even at enterprise scale, Model B costs under $150/year — a cost point that is trivially justifiable against the value proposition of the LID system. Model A at enterprise scale would require a meaningful budget line but remains affordable in absolute terms compared to equivalent commercial learning analytics platforms.

At enterprise scale, the primary concerns shift from cost to:
- **Queue throughput** — 20,000 calls may need parallel processing lanes
- **API quota** — Verify Vertex AI / Google AI Studio quota limits for your project tier
- **Latency** — Sequential queue drain at 20K calls could take hours; batch parallelism becomes a feature requirement
- **Error handling and retry logic** — At volume, LLM API failures become statistically certain; robust retry with exponential backoff is essential

---

## 4. Cost Comparison Summary

| Scale | Model A Annual | Model B Annual | Model C Annual | B vs A Savings |
|---|---|---|---|---|
| Single course | $2.46 | $0.21 | $0.09 | 91% |
| Program (10 courses) | $98.16 | $8.64 | $3.60 | 91% |
| Institution (50 courses) | $490.86 | $43.20 | $18.00 | 91% |
| Enterprise (200 courses) | $1,636.20 | $144.00 | $58.50 | 91% |

The 91% cost reduction from Model A to Model B is consistent across scales because the savings come from the architectural shift (batch vs per-post), not from scale itself. The per-learner cost is constant within each model regardless of deployment size.

---

## 5. Benchmark: Cost Per Learning Intelligence Data Point

To contextualise these figures against comparable commercial products:

| Reference Point | Cost Per Learner/Year |
|---|---|
| LID Model A (per-post) | $0.33 |
| LID Model B (batch) | $0.03 |
| LID Model C (optimized) | $0.012 |
| Typical LRS / xAPI platform (e.g. SCORM Cloud) | $1.00–$5.00 |
| Commercial learning analytics add-on (e.g. D2L Brightspace Insights) | $5.00–$20.00 |
| Human expert portfolio assessment (1hr/learner @ $75/hr) | $75.00 |

LID Model B delivers competency evidence, CPI scoring, Bloom's progression mapping, and employer value framing at approximately **1–3 cents per learner per year** — roughly 100–500× cheaper than commercial analytics platforms and orders of magnitude cheaper than human assessment.

---

## 6. Operational Recommendations by Scale

### Under 500 learners
- Google AI Studio with standard quota is sufficient
- No queue optimization needed — sequential drain works fine
- Monitor token usage monthly; stay well within free tier or minimal billing

### 500–2,000 learners
- Move to Vertex AI for production SLA guarantees and higher quota
- Implement queue depth monitoring and alerting
- Consider staggered forum close dates to smooth API call distribution
- Budget ~$20–$100/year depending on model choice

### 2,000–5,000 learners
- Vertex AI required
- Evaluate parallel queue processing (multiple concurrent workers)
- Implement per-forum rate limiting to avoid burst quota exhaustion
- Budget ~$50–$200/year depending on model choice

### 5,000+ learners
- Vertex AI with dedicated quota allocation
- Parallel processing lanes essential
- Consider a dedicated LLM microservice layer rather than direct Moodle plugin calls
- Implement comprehensive error handling, retry logic, and dead-letter queue for failed analyses
- Budget ~$150–$500/year depending on model choice — still very low relative to platform value

---

## 7. Key Uncertainties and Assumptions

The following assumptions should be validated as the system moves toward production:

| Assumption | Basis | Risk if Wrong |
|---|---|---|
| 8,000 tokens/call (Model B) | Estimated from alpha 15,150 tokens/post ÷ ~2 (batching efficiency) | Could be higher if learner post volume is large; monitor actual token counts in beta |
| 6 posts/learner/forum average | Observed in test data | Higher post volumes increase cost; low-participation learners reduce it |
| 4 forums/course | Test course structure | Variable by institution; parameterize this in cost estimates |
| Gemini 2.5 Flash pricing stable | Current public pricing | Google may change pricing; pin to a verified date |
| 3 terms/year | Standard academic calendar | Quarter systems would be 4× |
| No re-analysis cost | Replace model on re-run | Frequent re-runs (e.g. instructor-triggered) multiply costs; consider throttling |

---

## 8. Monitoring Recommendations

To track actual vs projected costs in production:

1. **Log token counts per LLM call** in `local_lid_analysis` — add `tokens_used` column to the analysis table
2. **Expose a cost estimate in the Course LID dashboard** — show estimated tokens consumed and projected term cost to admins
3. **Alert on anomalous call volumes** — if a single forum generates more than 2× expected calls, flag for review
4. **Track requests per model vs total API requests ratio** — a growing gap indicates retry storms or auth issues
5. **Monthly cost review** — compare actual Google AI Studio billing against projections; adjust estimates if observed token counts differ from model assumptions

---

*Document generated end of session 2026-03-21. Baseline figures drawn from live alpha/beta testing at lms.cucorn.com. Projections are estimates based on observed token consumption patterns and should be validated against actual beta usage data as it accumulates.*
