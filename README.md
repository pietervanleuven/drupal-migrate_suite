# Migrate Suite

A Drupal 10.4+ / 11.x module that provides an administration dashboard for managing and monitoring migrations. It fills a major gap in the Drupal migration ecosystem: once migrations are configured, there is no editor-friendly way to monitor their status, inspect migrated content, or control access per migration.

The core Migrate module and Migrate Tools provide Drush commands and a minimal admin page, but site managers, content leads, and non-developer admins are left without visibility. Migrate Suite changes that.

## Architecture

Migrate Suite is a parent module with six independently installable submodules. Each submodule depends only on the parent `migrate_suite` and (optionally) core modules — no inter-submodule dependencies.

```
migrate_suite/                    (parent: shared services, event subscriber, base API)
├── modules/
│   ├── migrate_admin/            (dashboard UI, detail pages, run/rollback actions)
│   ├── migrate_permissions/      (per-migration permissions, permission matrix)
│   ├── migrate_health/           (health badges, stale detection, failure rate monitoring)
│   ├── migrate_source_field/     (provenance pseudo-field, original source links)
│   ├── migrate_schedule/         (cron-based scheduling with QueueWorker)
│   └── migrate_views/            (Views integration for run history)
```

## Requirements

- Drupal `^10.4 || ^11`
- Core `migrate` module enabled
- Optional: `migrate_tools` (its `administer migrations` permission is recognized as an admin bypass)

## Installation

```bash
composer require drupal/migrate_suite
drush en migrate_suite
```

Then enable whichever submodules you need:

```bash
drush en migrate_admin migrate_permissions migrate_health migrate_source_field migrate_schedule migrate_views
```

## Submodules

### migrate_admin — Migration Dashboard

Provides a full administration dashboard at `/admin/structure/migrate-suite`.

**Features:**
- Lists all migrations with status, group, source/imported/failed counts, and last run time
- Color-coded status badges (green = idle/completed, blue = importing, yellow = rolling back, red = failed)
- Migrations grouped by `migration_group` in collapsible sections
- Filter by text search, status, and group
- Detail page per migration with four tabs:
  - **Imported Items** — source IDs, destination entity (linked), row status, import date
  - **Messages** — severity summary badges, message grouping/deduplication, full-text search, CSV export, re-run items by error
  - **Failed Items** — failed rows with error messages, bulk reset for retry
  - **Run History** — import and rollback run timeline with duration and item counts
- Run and rollback actions via Drupal Batch API with dependency warnings
- Rollback dry-run preview showing affected entities before execution
- Partial rollback — select specific items to roll back via tableselect
- Delta detection — shows source change info on the run confirm page

**Permission:** `view migrate suite dashboard`

### migrate_permissions — Granular Access Control

Dynamically generates per-migration permissions integrated with Drupal's permission system.

**Features:**
- Three permissions per migration: `view migration {id}`, `run migration {id}`, `rollback migration {id}`
- Permission matrix UI at `/admin/structure/migrate-suite/permissions` — roles as columns, migrations as rows
- Dashboard and detail pages respect per-migration permissions when this module is enabled
- Falls back to generic `view migrate suite dashboard` permission when disabled
- Admin bypass for `administer migrations` and `administer site configuration`

### migrate_health — Health Monitoring

Computes health indicators for each migration based on configurable thresholds.

**Features:**
- Three health states: **Healthy**, **Stale**, **Failing**
- Stale detection: migration never run or last run exceeds threshold (default: 7 days)
- Failure rate monitoring: failed items exceed threshold percentage (default: 5%)
- Aggregate health summary on the dashboard
- Configurable thresholds at `/admin/structure/migrate-suite/settings`

### migrate_source_field — Content Provenance

Surfaces migration metadata on migrated entities so editors can trace content origin.

**Features:**
- Registers a `migrate_provenance` pseudo-field on all node bundles
- Displays on node view/edit: migration name (linked to detail page), source IDs, import date
- Hidden by default — enable per bundle via "Manage Display"
- Configurable source URL patterns per migration with `[source_id]` token
- Per-entity migration message viewer at `/admin/structure/migrate-suite/entity/{type}/{id}/messages`
- Cached with tag-based invalidation on post-import events

### migrate_schedule — Cron-Based Scheduling

Per-migration scheduling with dependency-aware execution.

**Features:**
- Configure schedule per migration: disabled, hourly, daily, or weekly
- Cron-driven queue processing via Drupal's QueueWorker system
- Dependency checking — skips migrations whose required dependencies haven't run
- Optional delta detection skip — skip re-import if source data hasn't changed
- Settings form at `/admin/structure/migrate-suite/settings/schedules`
- Schedule column on dashboard (when enabled)

### migrate_views — Views Integration

Exposes migration run history to Drupal Views for custom reports and dashboards.

**Features:**
- `hook_views_data()` for the `migrate_suite_run_log` table
- All run log fields available: migration ID, operation, status, timestamps, item counts, source hash
- `MigrationStatus` field plugin for badge rendering
- `MigrationIdFilter` filter plugin with dropdown of known migrations
- Requires `drupal:views` module

## Parent Module Services

The parent `migrate_suite` module provides shared infrastructure:

| Service | Description |
|---|---|
| `migrate_suite.map_query` | Queries `migrate_map_*` tables — destination ID lookup, imported items listing, status counts, rollback preview, partial rollback |
| `migrate_suite.message_query` | Queries `migrate_message_*` tables — messages, severity counts, grouped messages, search, source ID lookup |
| `migrate_suite.delta_detection` | SHA-256 fingerprinting of source data for change detection between runs |
| `MigrateRunLogger` (event subscriber) | Listens to migrate import and rollback events, logs run history with item counts and source fingerprints |

The `migrate_suite_run_log` table tracks every migration run with:
- Operation type (import/rollback), status, timing
- Item counts (processed, created, updated, failed, deleted)
- Source fingerprint data (count, hash) for delta detection

## Admin Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite` | Migration dashboard | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}` | Migration detail (imported items) | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages` | Migration messages | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/failed` | Failed items | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/history` | Run history | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/run` | Run confirmation | `view migrate suite dashboard` + run permission |
| `/admin/structure/migrate-suite/{id}/rollback` | Rollback confirmation | `view migrate suite dashboard` + rollback permission |
| `/admin/structure/migrate-suite/{id}/partial-rollback` | Partial rollback selection | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages/export` | CSV message export | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages/rerun` | Re-run by error | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/entity/{type}/{id}/messages` | Entity messages | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/permissions` | Permission matrix | `administer permissions` |
| `/admin/structure/migrate-suite/settings` | Health thresholds | `administer site configuration` |
| `/admin/structure/migrate-suite/settings/source-links` | Source link patterns | `administer site configuration` |
| `/admin/structure/migrate-suite/settings/schedules` | Migration schedules | `administer site configuration` |

## Design Decisions

- **Queries `migrate_map_*` tables directly** — no redundant data storage, works retroactively with already-migrated content
- **Pseudo-fields over stored fields** — provenance data is computed at render time from map tables, avoiding data sync issues
- **Dynamic permissions** — generated from migration plugin definitions via `permission_callbacks`
- **Graceful degradation** — dashboard works without any submodule; features appear/disappear based on which submodules are enabled
- **Render arrays only** — all UI is built via Drupal render arrays, no custom Twig templates
- **Batch API for operations** — run/rollback use Drupal's Batch API with dependency checking
- **Delta detection** — SHA-256 fingerprints of source row IDs stored per run to detect changes

## Testing

The module includes unit and kernel tests:

- **Unit tests:** `MigrateAccessCheckTest`, `MigratePermissionsTest` — permission checking, ID normalization, dynamic generation
- **Kernel tests:** `MigrateMapQueryTest`, `MigrationHealthAnalyzerTest`, `MigrateRunLoggerTest` — database queries against real tables

Run tests with:

```bash
phpunit -c core modules/contrib/migrate_suite
```
