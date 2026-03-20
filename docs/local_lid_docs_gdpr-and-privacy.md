# GDPR and privacy

This document describes the personal data that `local_lid` processes, how it is stored and protected, and the obligations institutions must meet before deploying the plugin.

---

## Data this plugin processes

### What is sent to the LLM API

When a forum post is analysed, the following is sent to your configured LLM API endpoint:

- **The text content of the student's forum post** (HTML-stripped)
- **The discussion subject line** (for context)
- **The session analyzer prompt** (no personal data)

No usernames, email addresses, student IDs, or other personally identifiable information are included in the API request. Student authorship is not disclosed to the LLM.

### What is stored in Moodle's database

The `local_lid_analysis` table stores:

| Column | Content |
|---|---|
| `userid` | The Moodle user ID of the post author |
| `analysis_json` | The LID JSON output produced by the LLM from the student's post |
| `postid` | The ID of the analysed forum post |
| `forumid`, `courseid`, `discussionid` | Context identifiers |
| `timecreated`, `timemodified` | Timestamps |

The `analysis_json` column contains derived data (scores, competencies, Bloom's levels) — it does not contain the original post content. Post content remains in Moodle's standard `forum_posts` table and is not duplicated by this plugin.

The `local_lid_queue` table stores transient job references. Queue rows contain only `analysisid` (a foreign key) and processing metadata. They are deleted when processing completes or permanently fails. No personal data is stored in the queue table.

---

## Data controller obligations

Institutions deploying `local_lid` are acting as **data controllers** for the following processing activities:

### 1. Sending student content to a third-party LLM API

Forum post content (student-authored text) is transmitted to your configured LLM provider. This is a data processing activity that must be covered by your institution's data processing agreements.

**Required steps:**
- Review your LLM provider's data processing agreement (DPA)
- Confirm the provider is GDPR-compliant if you serve EU/EEA students
- Document this processing in your institution's Record of Processing Activities (ROPA)
- Notify students through your privacy notice that their forum posts may be analysed by an AI system

**Provider DPA links:**
- Anthropic: [anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)
- Google: [cloud.google.com/terms/data-processing-addendum](https://cloud.google.com/terms/data-processing-addendum)
- OpenRouter: Review their current privacy policy before use

### 2. Storing AI-derived assessments

The LID JSON stored in the database constitutes derived personal data — it is data about an identifiable student (linked via `userid`) produced by processing their content. This data:
- Must be accessible to the student on request (Subject Access Request)
- Must be deletable on request (Right to Erasure)
- Should be retained only as long as necessary for the stated purpose

---

## Moodle Privacy API

The plugin implements the Moodle Privacy API (`\local_lid\privacy\provider`). This means:

### Data export

When a Site Administrator processes a user data export request (**Site Administration → Privacy and policies → Data requests**), the plugin exports all post-scope analysis records for that user in their course contexts. The export includes:
- The LID JSON for each analysed post
- Post ID, forum ID, status, timestamps
- The schema version used

The export is included automatically in Moodle's standard data export ZIP file.

### Data deletion

When a right-to-erasure request is processed:
- All post-scope analysis records for the user are deleted
- The user's `student_forum` aggregate records are deleted
- Forum and course aggregate records are recomputed to exclude the deleted user's contributions
- Queue items for the deleted analyses are removed

**Important:** The original forum posts remain in Moodle's own tables — this plugin only deletes the derived analysis data it has created. If the student's posts themselves must be deleted, that is handled by Moodle's core forum privacy provider.

---

## Student transparency

We recommend informing students that their forum posts are being analysed. Suggested language for your course or site privacy notice:

> *"Forum posts in this course may be analysed by an AI system to generate learning intelligence data, including competency scores, cognitive depth indicators, and learning ROI metrics. This analysis is used to provide instructors with insight into student learning and engagement. Post content is sent to [your LLM provider] for analysis and is not retained by the AI provider beyond the processing of each request [verify this with your provider]. Analysis results are stored in the course learning management system and are accessible to your instructors."*

---

## Recommendations

- **Pseudonymisation:** The plugin does not send student names to the LLM. This is intentional and should be preserved if you customise the prompt.
- **Data minimisation:** Disable LID analysis on forums where the content is particularly sensitive (e.g. personal reflection journals, welfare check-ins).
- **Retention periods:** Consider using Moodle's data retention policies or periodic manual deletion of old analysis records for completed courses.
- **Access control:** The dashboard capabilities (`local/lid:viewstudentdashboard` etc.) should be granted only to roles with a legitimate educational interest in student performance data.
