# Development

This document covers setting up a local development environment, the project architecture, and conventions for contributing to `local_lid`.

---

## Development environment

### Requirements

- Moodle 4.5+ development instance (local or Docker)
- PHP 8.1+
- Node.js 18+ (for AMD compilation)
- Composer (for PHPUnit)

### Setup

```bash
# Clone into your Moodle local/ directory
cd /path/to/moodle/local
git clone https://github.com/your-org/local_lid.git lid

# Install Moodle dev dependencies (from Moodle root)
composer install
npm install

# Disable JS cache so AMD source files are served directly
# Add to config.php:
$CFG->cachejs = false;
$CFG->debug = DEBUG_DEVELOPER;
$CFG->debugdisplay = 1;
```

With `cachejs = false`, Moodle serves `amd/src/*.js` directly — no grunt compilation needed during development.

### AMD compilation (for production)

```bash
# From Moodle root
grunt amd
```

Or manually using [jscompress.com](https://jscompress.com):
1. Paste `amd/src/dashboard.js` content
2. Compress
3. Verify the output starts with `define([`
4. Save as `amd/build/dashboard.min.js`
5. Repeat for `prompt_editor.js` and `forum_config.js`

---

## Project structure

```
local/lid/
├── version.php              Plugin version and Moodle compatibility
├── lib.php                  Navigation hooks, config callbacks, install seed
├── settings.php             Admin settings page
├── ajax.php                 AJAX endpoint (trigger, status, config, save_prompt)
├── report.php               Course LID entry point
├── forum_view.php           Forum LID entry point
├── student_view.php         Student LID entry point
│
├── classes/
│   ├── llm/
│   │   ├── client.php       HTTP client for LLM API
│   │   └── prompt_builder.php  Assembles prompt + post content
│   ├── analysis/
│   │   ├── schema_validator.php   Validates LID JSON output
│   │   ├── session_analyser.php   Orchestrates the analysis pipeline
│   │   └── aggregator.php         Mathematical merge of post analyses
│   ├── output/
│   │   ├── renderer.php           Moodle renderer, card data preparation
│   │   ├── course_lid_page.php    Renderable: course dashboard
│   │   ├── forum_lid_page.php     Renderable: forum dashboard
│   │   └── student_lid_page.php   Renderable: student dashboard
│   ├── privacy/
│   │   └── provider.php           GDPR export/deletion
│   ├── task/
│   │   └── process_queue.php      Scheduled task
│   ├── exception/
│   │   ├── llm_config_exception.php
│   │   ├── llm_request_exception.php
│   │   └── llm_response_exception.php
│   └── observer.php               Forum event handlers
│
├── db/
│   ├── install.xml          XMLDB table definitions
│   ├── install.php          Post-install hook (seeds default prompt)
│   ├── upgrade.php          Schema migration handler
│   ├── access.php           Capability definitions
│   ├── tasks.php            Scheduled task registration
│   └── events.php           Event observer registration
│
├── templates/               Mustache templates
├── amd/src/                 AMD JavaScript source
├── amd/build/               Compiled AMD modules (gitignored or force-added)
├── prompts/                 Default prompt files
├── lang/en/                 Language strings
├── pix/                     Plugin icons
├── docs/                    This documentation
└── tests/                   PHPUnit and Behat tests
```

---

## Key architectural decisions

### Pre-rendered analysis cards

Moodle's Mustache engine does not support passing a specific variable as the context to a partial — partials always receive the full page context. To work around this, all analysis cards are pre-rendered in PHP by `renderer::render_analysis_card()` and passed to page templates as HTML strings. Templates output these with `{{{ }}}` (unescaped triple braces).

### Mathematical aggregation (no LLM calls for aggregates)

Aggregate dashboards (forum, student_forum, course scope) are computed by `aggregator.php` using weighted averages and union operations on existing post-scope JSON. This keeps LLM costs bounded: exactly one API call per forum post, regardless of how many aggregate views are generated.

### Queue-based LLM calls

All LLM calls go through the queue table (`local_lid_queue`) rather than being called synchronously. This prevents post submission from blocking on an external API call and provides retry logic, priority ordering, and batch rate limiting.

### Schema version tolerance

`schema_validator.php` accepts all versions in `SUPPORTED_VERSIONS`. New schema fields are treated as optional for backward compatibility. The `schema_version` field in each JSON blob is the source of truth for the renderer.

---

## Adding a new LLM provider

The `client.php` HTTP client supports any provider with an OpenAI-compatible chat completions endpoint. For providers with a non-standard response shape, extend `client::extract_text()`:

```php
// Add a new response shape check before the final throw:
if (isset($decoded['your_provider_field'])) {
    return trim($decoded['your_provider_field']);
}
```

---

## Adding a new schema field

When extending the schema (e.g. for a hypothetical v1.2):

1. Update `schema_validator.php`:
   - Add to `SUPPORTED_VERSIONS`
   - Add validation method for the new field
   - Add to `coerce_numerics()` if numeric
   - Call the validation method in `validate()` conditionally (treat as optional for older versions)

2. Update `aggregator.php`:
   - Add merge logic for the new field in `merge()`
   - Handle the field in `stamp_aggregate_meta()` if needed

3. Update `renderer.php`:
   - Add field mapping in `prepare_card_data()`
   - Return safe empty defaults for older schema versions

4. Update `analysis_card.mustache`:
   - Add the new panel or field display
   - Use `{{#has_new_field}}` guards so v1.0/v1.1 data renders without errors

5. Update `lid_styles.mustache` with any new CSS

6. Bump `version.php` and add a note to `db/upgrade.php`

---

## Running tests

### PHPUnit

```bash
# From Moodle root
vendor/bin/phpunit --testsuite local_lid

# Run a specific test file
vendor/bin/phpunit local/lid/tests/phpunit/schema_validator_test.php
```

### Behat

```bash
php admin/tool/behat/cli/init.php
vendor/bin/behat --config behat.yml --suite local_lid
```

---

## Versioning convention

`version.php` uses Moodle's YYYYMMDDNN format:
- `2026032000` — initial release (0.1.0)
- `2026032001` — same-day patch (0.2.0 — schema v1.1 support)
- `2026040100` — next release on a different day

`$plugin->release` uses semantic versioning (MAJOR.MINOR.PATCH):
- MAJOR: breaking change to DB schema or plugin API
- MINOR: new features, new schema version support, significant capability additions
- PATCH: bug fixes, documentation, minor corrections

---

## Contribution workflow

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make changes following the coding style below
4. Run PHPUnit tests
5. Submit a pull request against `develop`

### Coding style

- PHP: follows Moodle coding style (PSR-2 with Moodle conventions)
- Namespacing: `\local_lid\` prefix for all autoloaded classes
- Strings: all user-facing text through `get_string()` / `lang/en/local_lid.php`
- No hardcoded SQL — use `$DB->get_records()`, `$DB->get_record_sql()` etc.
- All new DB queries use `{table_name}` Moodle table prefix syntax
- JavaScript: ES5-compatible (Moodle's RequireJS AMD format, no ES modules in `amd/src/`)
