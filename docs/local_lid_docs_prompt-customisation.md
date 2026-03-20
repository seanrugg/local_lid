# Prompt customisation

The plugin ships with a default session analyzer prompt (LID v1.1) that produces consistent, rubric-based analysis. This document explains how to view, edit, upload, and reset the prompt at each level.

---

## Prompt resolution order

When a forum post is analysed, the plugin resolves the active prompt using this priority chain (most specific wins):

```
Forum-level override (if set and prompt not locked)
        ↓
Course-level override (if set and prompt not locked)
        ↓
Site-level default (always present)
        ↓
Plugin default file (fallback if site row is missing)
```

If the site administrator has enabled **Lock prompt**, the resolution skips forum and course overrides entirely and always uses the site-level prompt.

---

## Editing at site level (administrators)

Navigate to **Site Administration → Plugins → Local plugins → Learning Intelligence Dashboard → Prompt template**.

The textarea contains the full active prompt. You can:

- **Edit directly** — modify any part of the prompt in the textarea
- **Upload a `.md` file** — click *Upload .md* or drag a Markdown file onto the textarea; the file content replaces the current text
- **Reset to plugin default** — restores the shipped LID v1.1 prompt; the form must still be submitted to save

Click **Save changes** at the bottom of the settings page to persist any edits.

---

## Editing at course level (teachers)

Navigate to your course → **[Course settings] → Learning Intelligence** (if your theme exposes this, or via the dedicated settings page linked from the Course LID dashboard).

The prompt editor shows the currently active prompt for this course. An inheritance notice indicates whether you are viewing the site default or a course override.

- **Edit and save** — creates a course-level override that applies to all forums in this course (unless a forum-level override exists)
- **Reset to site default** — removes the course override; the course reverts to the site prompt

Not available when the site administrator has enabled **Lock prompt**.

---

## Editing at forum level (teachers)

Navigate to the forum activity → **Learning Intelligence** settings tab → **Forum-level prompt override**.

This is the most specific level. A forum override applies only to that forum and overrides both course and site prompts.

Use forum-level prompts when:
- A specific forum has a different assessment focus (e.g. a debate forum vs a reflective journal forum)
- You want different competency frameworks mapped for different activities
- A forum requires domain-specific calibration (e.g. a clinical practice forum vs a general discussion)

---

## Writing effective prompts

The LID v1.1 default prompt contains detailed scoring rubrics that produce consistent, defensible scores. If you customise the prompt, keep these principles in mind:

### Keep the schema instruction intact

The prompt must instruct the LLM to produce a JSON object conforming to LID Schema v1.1. The schema block at the bottom of the default prompt is required — if you remove or modify the field names, the validator will reject the output.

### Keep the rubric structure

The 11 scoring rubrics (knowledge value formula, engagement dimensions, retention factors, etc.) are what distinguish v1.1 from free-form scoring. If you simplify or remove rubrics, scores become less consistent and less defensible. The `meta.notes` field will also become less meaningful.

### What you can safely customise

- **The introduction paragraph** — change the role description or analytical focus
- **Framework references** — add domain-specific frameworks (e.g. NICE framework for cybersecurity, ACGME competencies for clinical)
- **The forum context calibration** — adjust the preamble that tells the LLM this is a forum post
- **Competency emphasis** — add instructions to weight certain competency domains more heavily for your discipline

### What to avoid

- Removing required JSON fields from the schema block
- Changing field names or data types in the schema
- Removing the `calculation_note` requirement from the CPI block
- Instructing the LLM to wrap the output in markdown fences (the validator strips them, but it adds unnecessary processing)

### Testing a custom prompt

Before deploying a custom prompt across a course:

1. Copy the prompt text
2. Paste it into a direct conversation with your LLM
3. Follow it with a sample forum post
4. Verify the output is valid JSON with all required fields
5. Check that `schema_version` is `"1.1"` and `cognitive_performance_index` is present

If the output validates, upload it via the settings page.

---

## The forum post preamble

Separate from the main prompt template, the plugin prepends a short calibration preamble to every forum post analysis:

> *"CONTEXT FOR ANALYSIS: The content below is a forum discussion post from a Moodle course activity, not a full AI conversation session. Apply the following calibrations before scoring: set source to Moodle Forum, estimate duration from reading/writing time, set confidence to LOW for short posts..."*

This preamble lives in `prompts/forum-post-preamble.md` in the plugin directory. It is not user-editable through the UI — it is a fixed calibration layer applied regardless of which prompt template is active. Its purpose is to prevent the LLM from treating a short forum post as a full multi-hour session and producing inflated scores.

---

## Prompt locking

When **Lock prompt** is enabled in site settings:

- The prompt editor at course and forum level renders as read-only text
- File upload controls are hidden
- Save buttons are hidden
- The prompt content is still visible (transparency for instructors)
- Only Site Administrators can edit the prompt

Use prompt locking when:
- Your institution has a standardised rubric that must apply consistently across all courses
- You are running a research study and need scoring consistency across participants
- You want to prevent instructors from inadvertently breaking the JSON schema
