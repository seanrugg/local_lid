# Schema compatibility

The Learning Intelligence Dashboard uses a versioned JSON schema to structure analysis output. This document describes the schema versions, what changed between them, and how the plugin handles data across versions.

---

## Schema versions

### v1.0 (plugin release 0.1.0)

The original schema. Produced by the LID Session Analyzer prompt v1.0.

Key fields: `schema_version`, `session`, `scores`, `competencies`, `radar`, `blooms_progression`, `roi`, `timeline`, `employer_value`, `portfolio`, `meta`.

Scoring was free-form — the LLM estimated all metrics without explicit rubrics.

### v1.1 (plugin release 0.2.0)

Adds the `cognitive_performance_index` block. All v1.0 fields are unchanged — v1.1 is purely additive.

New block:
```json
"cognitive_performance_index": {
  "cpi_score": 107,
  "cpi_band": "Proficient",
  "cpi_band_description": "...",
  "component_weights": {
    "cognitive_depth": 0.35,
    "meta_cognition": 0.25,
    "strategic_thinking": 0.20,
    "engagement": 0.15,
    "roi_awareness": 0.05
  },
  "component_scores": {
    "cognitive_depth": 62,
    "meta_cognition": 30,
    "strategic_thinking": 45,
    "engagement": 58,
    "roi_awareness": 40
  },
  "calculation_note": "Session-specific behavioral composite scored via LI Dashboard Prompt v1.2 rubrics. Not a measure of general intelligence or a psychometric IQ equivalent. Reflects observed cognitive performance within this session only."
}
```

Also introduced: explicit scoring rubrics (11 rubrics with formulas), required `meta.notes` format documenting scoring math. Produced by the LID Session Analyzer prompt v1.2.

---

## Backward compatibility

The plugin validator and renderer handle both schema versions transparently:

- **v1.0 data stored before the upgrade** continues to render correctly. The `cognitive_performance_index` block is optional — when absent, the CPI panel simply does not appear on the dashboard card.
- **New analyses** produced after upgrading to v0.2.0 use v1.1 schema and include the CPI panel.
- **Aggregate rows** computed from a mix of v1.0 and v1.1 post analyses will include a CPI block only if at least one v1.1 post analysis is present in the set.

No database migration is required when upgrading from v0.1.0 to v0.2.0. The `analysis_json` column stores JSON as text; the validator detects the version from `schema_version` at read time.

---

## The CPI — what it is and what it is not

The Cognitive Performance Index (CPI) is a **session-specific behavioral composite**, not a measure of general intelligence or a psychometric assessment.

It is scaled 70–145 to reflect the range of observable cognitive behavior in a forum post or learning session:
- 70 represents minimal observable cognitive engagement (short, surface-level post)
- 145 represents exceptional cognitive performance (sophisticated synthesis, original contribution, advanced metacognition)

The scale was chosen to be distinct from common percentage scores and to avoid confusion with IQ scales. The `calculation_note` field in every CPI block is required to contain the disclaimer: *"Not a measure of general intelligence or a psychometric IQ equivalent."*

**CPI bands:**

| Score | Band | Typical characteristics |
|---|---|---|
| 130–145 | Exceptional | Dominant Create/Evaluate Bloom's. Advanced metacognition. Deep strategic framing. Original artifact produced. |
| 115–129 | Advanced | Consistent upper Bloom's. Strong self-direction. Predominantly strategic. High application readiness. |
| 100–114 | Proficient | Solid Apply/Analyze. Good application orientation. Moderate strategic awareness. |
| 85–99 | Developing | Apply/Analyze range. Moderate engagement. Strategic awareness present but not dominant. |
| 70–84 | Foundational | Primarily Remember/Understand. Surface engagement. Limited strategic framing. |

**CPI formula:**
```
raw = (cognitive_depth × 0.35) + (meta_cognition × 0.25)
    + (strategic_thinking × 0.20) + (engagement × 0.15)
    + (roi_awareness × 0.05)

cpi_score = round(70 + (raw / 100) × 75)
cpi_score = clamp(cpi_score, 70, 145)
```

The component weights reflect the relative importance of each dimension in characterising cognitive performance in a learning context.

---

## Stale analysis detection

When the active prompt template changes (at site, course, or forum level), analyses produced with the old prompt are marked as potentially stale. The dashboard surfaces a notice when stale analyses are detected.

Stale detection works by comparing the SHA-256 hash of the prompt used for each analysis (`local_lid_analysis.prompt_hash`) against the current active prompt hash. A mismatch indicates the post was analysed with a different rubric.

Stale analyses are still valid and displayed normally — the notice is informational, prompting the instructor to consider re-running analysis if the prompt change was significant.

---

## Future schema versions

If a future schema version (v1.2+) is introduced:

1. The new version string will be added to `schema_validator::SUPPORTED_VERSIONS`
2. New fields will be treated as optional for backward compatibility
3. The aggregator will be updated to handle new fields in the merge logic
4. The renderer and templates will be updated to display new fields
5. `version.php` will be bumped and a new entry added to `db/upgrade.php`

The goal is to maintain full backward compatibility across schema versions — analyses stored in any supported schema version should always render correctly on the current dashboard.
