# Installation

## Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| Moodle | 4.5 LTS | 5.1+ |
| PHP | 8.1 | 8.2+ |
| MariaDB / MySQL | 10.6 | 10.11+ |
| PHP extensions | curl, json, mbstring | All Moodle-required extensions |
| LLM API | Any OpenAI-compatible endpoint | See [configuration.md](configuration.md) |

## Installation steps

### 1. Copy files to Moodle

Clone or download the repository into your Moodle `local/` directory:

```bash
cd /path/to/moodle/local
git clone https://github.com/your-org/local_lid.git lid
```

The directory must be named `lid` — Moodle derives the component name `local_lid` from the path `local/lid/`.

### 2. Run the Moodle installer

Log in as a Site Administrator and navigate to **Site Administration → Notifications**. Moodle will detect the new plugin and display an upgrade screen. Click through to complete the installation.

During installation, Moodle will:
- Create four database tables: `local_lid_settings`, `local_lid_forum_config`, `local_lid_analysis`, `local_lid_queue`
- Seed the site-level settings row with the default LID v1.1 prompt
- Register the `\local_lid\task\process_queue` scheduled task

### 3. Configure the LLM connection

Navigate to **Site Administration → Plugins → Local plugins → Learning Intelligence Dashboard** and enter:

- **LLM API endpoint** — the URL of your LLM provider
- **API key** — your provider's API key
- **Model** — the model identifier string

See [configuration.md](configuration.md) for provider-specific settings and [how-it-works.md](how-it-works.md) for an explanation of how the LLM fits into the pipeline.

### 4. Enable LID on a forum

Navigate to any forum activity in a course. Users with the `local/lid:configureforum` capability will see a **Learning Intelligence** settings section. Toggle **Enable LID analysis** on.

### 5. Verify cron is running

The analysis queue is drained by Moodle's scheduled task runner. Verify cron is configured on your server:

```bash
# Check the Moodle cron job
crontab -l | grep moodle

# Typical setup (runs every minute)
* * * * * /usr/bin/php /path/to/moodle/admin/cli/cron.php > /dev/null 2>&1
```

Navigate to **Site Administration → Server → Scheduled tasks** and confirm `Process Learning Intelligence analysis queue` is listed and not disabled.

### 6. Test the installation

1. Submit a post to a LID-enabled forum
2. Navigate to **Forum → Learning Intelligence tab**
3. If async trigger is enabled, wait up to 5 minutes for the cron to run, or click **Re-analyse** to trigger immediately
4. The post should appear with `Complete` status and a rendered dashboard panel

## AMD build files

The plugin's JavaScript is pre-compiled in `amd/build/`. If you are installing from source and the `amd/build/` directory is not present:

- **Development:** Add `$CFG->cachejs = false;` to your `config.php` — Moodle will serve files from `amd/src/` directly.
- **Production:** Run `grunt amd` from the Moodle root, or use [jscompress.com](https://jscompress.com) to minify each `amd/src/*.js` file and save the output to `amd/build/*.min.js`.

## Upgrading

When upgrading from a previous version, run **Site Administration → Notifications** after replacing the plugin files. The `db/upgrade.php` script handles any required database changes.

If upgrading from v0.1.0 (Schema v1.0) to v0.2.0 (Schema v1.1): no database migration is required. The `analysis_json` column stores JSON as text, and the updated validator and renderer handle both schema versions transparently.
