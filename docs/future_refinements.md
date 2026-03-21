# LID Plugin — Future Refinements Register
**Version:** 1.0  
**Date:** 2026-03-21  
**Project:** local_lid Moodle Plugin — Learning Intelligence Dashboard  
**Status:** Active — append new refinements as identified

---

## Purpose

This document captures identified future refinements to the LID plugin that are out of scope for the current release but should be addressed in subsequent development. Each entry includes the problem statement, proposed solution, design considerations, and priority. Entries are not ordered by priority unless explicitly noted.

---

## Refinement 001 — Instructor Forum Analysis as a Separate Scope

### Problem Statement

The current `student_forum` queuing logic identifies participants by post presence in a forum, without filtering by Moodle role. In forums where instructors actively participate — including Socratic seminar formats where instructor facilitation is a core pedagogical design — instructor posts are queued alongside learner posts and analyzed under the same `student_forum` scope.

This produces two compounding problems:

1. **Contaminated forum aggregate** — Instructor posts naturally exhibit high cognitive depth, strategic thinking, and meta-cognition as a function of their professional role. Including them in the forum aggregate inflates the aggregate CPI and competency scores, misrepresenting the collective learner performance baseline.

2. **Misapplied rubrics** — The LI Dashboard Prompt v1.2 rubrics are calibrated to assess learner competency development. Applying them to instructor facilitation moves (Socratic questioning, redirecting, synthesizing, challenging assumptions) produces scores that are technically high but analytically meaningless in the learner assessment context.

### Proposed Solution

**Phase 1 — Role-based exclusion from student_forum queue**
Add a capability or role check to the queuing logic in `process_queue.php`. When building the list of users to queue for `student_forum` analysis, exclude users who hold a non-student role in the course context by checking `mdl_role_assignments` against the course context id. The precise capability to check should be confirmed against the Moodle role framework — likely `mod/forum:viewhiddentimedposts` or a custom `local/lid:excludedfromlearneranalysis` capability.

**Phase 2 — Instructor forum scope**
Introduce a new analysis scope: `instructor_forum`. Route instructor participants to this scope using a prompt variant calibrated to facilitation quality rather than learner competency. Relevant assessment dimensions for this scope include:

- Quality and depth of Socratic questioning
- Effectiveness of cognitive scaffolding and redirecting
- Balance between challenge and support
- Evidence of differentiated facilitation (responding differently to different learners)
- Meta-pedagogical awareness (does the instructor reflect on facilitation strategy?)

**Phase 3 — Forum aggregate toggle**
Add an instructor inclusion toggle to the forum LID aggregate view. This allows the instructor to switch between:

- **Learner-only aggregate** (default) — forum-level CPI and competency scores reflect enrolled student cohort only
- **Full participant aggregate** — includes instructor contributions, useful for research or instructional quality review contexts

The toggle state should be stored per-forum in `local_lid_forum_config` so it persists across sessions and is consistent for all viewers of that forum's LID.

### Implementation Notes

- Role check must use Moodle context — `context_course::instance($courseid)` — not a flat role name comparison
- The `instructor_forum` scope requires a new prompt file: `prompts/instructor-forum-analyzer.md`
- The forum aggregate renderer will need a conditional branch based on the toggle state
- The student_lid view should never surface instructor analysis cards — scope filtering must be enforced at the query level, not just the UI level

### Priority
**Medium** — Does not block current functionality. Becomes important before any multi-institution deployment where data integrity of aggregate scores matters for reporting or accreditation purposes.

---

## Refinement 002 — Military Hierarchy and Social Deference Bias in Discourse Scoring

### Problem Statement

The LI Dashboard Prompt v1.2 Critical Discourse Index (CDI) rubric rewards behaviors including peer challenge, constructive disagreement, counterargument, and position defence. These behaviors are weighted positively as evidence of higher-order thinking and intellectual engagement.

In military professional education contexts — and more broadly in any hierarchical organizational culture — these same behaviors are socially constrained by rank, authority, and institutional norms. A student who defers to an instructor's position rather than challenging it may be:

1. Demonstrating culturally appropriate professional behavior, not intellectual passivity
2. Strategically managing their professional reputation in a context where being seen as competent by a superior directly affects career outcomes
3. Responding to an instructor who has not explicitly signaled that challenge is welcomed or expected

This creates a **structural scoring bias**: learners in military contexts will systematically score lower on Critical Discourse dimensions not because they lack the capability for critical thinking, but because the social context suppresses its visible expression.

The inverse is also true and equally important: when a learner does challenge an instructor or take a dissenting position in a military context, the cognitive and social cost of that act is significantly higher than in a civilian academic setting. The act itself is evidence of stronger conviction, greater intellectual confidence, and higher meta-cognitive awareness than the same act would signal in a low-hierarchy context. The current rubric does not account for this asymmetry.

### Additional Dynamic — Instructor-Directed Challenge

In well-designed Socratic seminars, instructors deliberately introduce provocative or deliberately incomplete positions to invite learner challenge. When an instructor signals (explicitly or implicitly) that disagreement is welcome, learner responses to that signal carry different analytical meaning than unsolicited peer challenge. The current rubric cannot distinguish between:

- A learner challenging a peer in a low-stakes horizontal exchange
- A learner accepting an instructor's implicit invitation to challenge in a vertical, rank-differentiated exchange
- A learner spontaneously challenging an instructor without any such invitation

All three may produce superficially similar discourse moves but represent very different levels of intellectual agency and risk-taking.

### Proposed Solution

**Phase 1 — Context-aware discourse model variant**
Extend the `discussion_model` field in `local_lid_forum_config` to include a `hierarchical_seminar` variant (or add a separate `organizational_context` field). When this context is set, the forum discussion analyzer prompt applies a modified Critical Discourse rubric that:

- Weights deference-with-reasoning differently from deference-without-reasoning
- Awards additional score weight to dissenting positions in hierarchical contexts
- Recognizes instructor-facilitated challenge as a distinct discourse move
- Explicitly notes in `instructor_notes` when a learner's discourse pattern is consistent with hierarchical deference rather than intellectual disengagement

**Phase 2 — Prompt annotation of hierarchy signals**
When instructor posts are present in the thread content passed to the LLM (thread scope analysis), annotate instructor posts with their role so the model can reason about the discourse dynamic with full context. Currently all posts are pseudonymised as Participant A, B, etc. A refinement would be to mark one participant as `Facilitator` rather than a lettered participant, allowing the model to assess how learners respond to facilitation moves.

**Phase 3 — Meta-note generation**
Ensure that `instructor_notes` in v1.2 schema output explicitly surfaces when a learner's discourse pattern shows evidence of hierarchical deference — not as a negative finding, but as contextual framing. For example: *"Learner consistently agrees with facilitator positions without explicit challenge. In the context of a military professional seminar, this pattern may reflect cultural norm compliance rather than limited critical engagement. See post 3 for evidence of reasoned agreement that suggests underlying analytical capability."*

### Design Principle

The goal is not to artificially inflate scores for learners in hierarchical contexts, but to ensure the assessment instrument is **context-sensitive** rather than context-blind. A rubric designed for civilian higher education applied without modification to military professional education will systematically undervalue a population whose intellectual capability is expressed through different cultural norms.

This is consistent with the broader LID design principle that scoring is descriptive and calibrated, not credentialing.

### Priority
**Medium-High** — This is a validity concern, not a feature request. If the LID system is to be used for talent evaluation, professional development planning, or any consequential assessment in military or paramilitary organizational contexts, this bias must be addressed before scores are used to make decisions about people.

### Related Work
- LI Dashboard Prompt v1.2 — Critical Discourse Index (Rubric 2) is the primary affected rubric
- `discussion_model` field in `local_lid_forum_config` — extension point for context variant
- `instructor_notes` field in v1.2 schema — primary output location for contextual framing

---

## Refinement 003 — Production Token Optimization

### Problem Statement

The current implementation sets `max_tokens` at 60,000 for LLM output during beta testing. This was appropriate for initial testing to avoid truncation risk but is wasteful in production — fully populated v1.2 JSON responses are estimated at 1,500–3,000 output tokens.

### Proposed Solution

- Set production `max_tokens` to **8,192** — provides comfortable headroom over observed output sizes without unnecessary token allocation
- Add `tokens_used` column to `mdl_local_lid_analysis` to log actual token consumption per call
- Surface estimated token cost in the Course LID admin view for budget visibility
- Revisit after 100+ production analyses to validate the 8,192 ceiling against real output distributions

### Priority
**Low** — Not a correctness issue. Becomes relevant at institution or enterprise scale where token costs are tracked against a budget.

---

## Refinement 004 — Role-Based Queue Filtering Implementation Detail

### Problem Statement

When the manual trigger or cron-based detection queues learners for `student_forum` analysis, it currently queries all users with posts in the forum regardless of their course role. There is no mechanism to exclude non-student participants.

### Proposed Solution

In `process_queue.php`, when building the user list for queuing, add a role-context filter:

```php
$context = context_course::instance($courseid);
$students = get_role_users(/* student role id */, $context);
```

Only queue users whose `userid` appears in both the forum posts and the enrolled student list. Users with posts who are not in the student list are candidates for the future `instructor_forum` scope (Refinement 001).

### Notes
- Role ID for 'student' is not fixed across Moodle installations — use `get_archetype_roles('student')` rather than a hardcoded integer
- Consider making the excluded roles configurable in `local_lid_forum_config` or site settings to support non-standard role architectures

### Priority
**Medium** — Implement alongside Refinement 001 Phase 1.

---

*This document should be updated whenever a new refinement is identified. Each entry should include enough context that a developer picking up the work in a future session can understand the problem without needing to re-read session history.*
