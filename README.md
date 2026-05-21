# Migrate Suite

A Drupal 10.4+ / 11.x module that provides an administration dashboard for managing and monitoring migrations. It fills a major gap in the Drupal migration ecosystem: once migrations are configured, there is no editor-friendly way to monitor their status, inspect migrated content, or control access per migration.

The core Migrate module and Migrate Tools provide Drush commands and a minimal admin page, but site managers, content leads, and non-developer admins are left without visibility. Migrate Suite changes that.

## Architecture

Migrate Suite is a parent module with four independently installable submodules. Each submodule depends only on the parent `migrate_suite` and core `migrate` — no inter-submodule dependencies.

```
migrate_suite/                    (parent: shared services, event subscriber, base API)
├── modules/
│   ├── migrate_admin/            (dashboard UI, detail pages, run/rollback actions)
│   ├── migrate_permissions/      (per-migration permissions, permission matrix)
│   ├── migrate_health/           (health badges, stale detection, failure rate monitoring)
│   └── migrate_source_field/     (provenance pseudo-field, original source links)
```

## Requirements

- Drupal `^10.4 || ^11`
- Core `migrate` module enabled
- Optional: `migrate_tools` (its `administer migrations` permission is recognized as an admin bypass)

## Installation

Place or clone the module into your Drupal modules directory (e.g., `modules/contrib/migrate_suite`) and enable:

```bash
drush en migrate_suite
```

Then enable whichever submodules you need:

```bash
drush en migrate_admin migrate_permissions migrate_health migrate_source_field
```

## Submodules

### migrate_admin — Migration Dashboard

Provides a full administration dashboard at `/admin/structure/migrate-suite`.

**Features:**
- Lists all migrations with status, group, source/imported/failed counts, and last run time
- Color-coded status badges (green = idle/completed, blue = importing, yellow = rolling back, red = failed)
- Migrations grouped by `migration_group` in collapsible sections
- Filter by text search, status, and group
- Detail page per migration (`/admin/structure/migrate-suite/{migration_id}`) with three tabs:
  - **Imported Items** — source IDs, destination entity (linked), row status, import date
  - **Messages** — severity-coded migration messages with source ID correlation
  - **Failed Items** — failed rows with error messages, bulk reset for retry
- Run and rollback actions via Drupal Batch API with dependency warnings
- Pagination (50 items/page) on all list views

**Permission:** `view migrate suite dashboard`

### migrate_permissions — Granular Access Control

Dynamically generates per-migration permissions integrated with Drupal's permission system.

**Features:**
- Three permissions per migration: `view migration {id}`, `run migration {id}`, `rollback migration {id}`
- Permission matrix UI at `/admin/structure/migrate-suite/permissions` — roles as columns, migrations as rows, grouped by migration group
- Dashboard and detail pages respect per-migration permissions when this module is enabled
- Falls back to generic `view migrate suite dashboard` permission when disabled
- Admin bypass for `administer migrations` and `administer site configuration`

### migrate_health — Health Monitoring

Computes health indicators for each migration based on configurable thresholds.

**Features:**
- Three health states: **Healthy**, **Stale**, **Failing**
- Stale detection: migration never run or last run exceeds threshold (default: 7 days)
- Failure rate monitoring: failed items exceed threshold percentage (default: 5%)
- Aggregate health summary on the dashboard ("X healthy / Y stale / Z failing")
- Per-migration health badge column on the dashboard
- Configurable thresholds at `/admin/structure/migrate-suite/settings`

### migrate_source_field — Content Provenance

Surfaces migration metadata on migrated entities so editors can trace content origin.

**Features:**
- Registers a `migrate_provenance` pseudo-field on all node bundles via `hook_entity_extra_field_info()`
- Displays on node view/edit: migration name (linked to detail page), source IDs, import date
- Hidden by default — enable per bundle via "Manage Display"
- Configurable source URL patterns per migration at `/admin/structure/migrate-suite/settings/source-links`
  - Supports `[source_id]` token replacement (e.g., `https://old-site.com/node/[source_id]`)
  - Renders "Original source" link in the provenance fieldset
- Cached with tag-based invalidation on post-import events
- No output rendered if entity isn't found in any migration map table

## Parent Module Services

The parent `migrate_suite` module provides shared infrastructure:

| Service | Description |
|---|---|
| `migrate_suite.map_query` | Queries `migrate_map_*` tables — destination ID lookup, imported items listing, status counts |
| `migrate_suite.message_query` | Queries `migrate_message_*` tables — messages per migration or per source ID |
| `MigrateRunLogger` (event subscriber) | Listens to migrate events and logs run history to `migrate_suite_run_log` |

The `migrate_suite_run_log` table tracks every migration run with status, timing, and item counts (processed, created, updated, failed).

## Admin Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite` | Migration dashboard | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}` | Migration detail (imported items) | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages` | Migration messages | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/failed` | Failed items | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/run` | Run confirmation | `view migrate suite dashboard` + run permission |
| `/admin/structure/migrate-suite/{id}/rollback` | Rollback confirmation | `view migrate suite dashboard` + rollback permission |
| `/admin/structure/migrate-suite/permissions` | Permission matrix | `administer permissions` |
| `/admin/structure/migrate-suite/settings` | Health thresholds | `administer site configuration` |
| `/admin/structure/migrate-suite/settings/source-links` | Source link patterns | `administer site configuration` |

## Design Decisions

- **Queries `migrate_map_*` tables directly** — no redundant data storage, works retroactively with already-migrated content
- **Pseudo-fields over stored fields** — provenance data is computed at render time from map tables, avoiding data sync issues
- **Dynamic permissions** — generated from migration plugin definitions via `permission_callbacks`
- **Graceful degradation** — dashboard works without any submodule; features appear/disappear based on which submodules are enabled
- **Render arrays only** — all UI is built via Drupal render arrays, no custom Twig templates
- **Batch API for operations** — run/rollback use Drupal's Batch API with dependency checking

## To Do

### Testing
- [ ] Kernel tests for `MigrateMapQuery` and `MigrateMessageQuery` services
- [ ] Kernel tests for `MigrateRunLogger` event subscriber
- [ ] Kernel tests for `ProvenanceLookup` service
- [ ] Kernel tests for `MigrationHealthAnalyzer` service
- [ ] Functional tests for dashboard page (listing, filtering, grouping)
- [ ] Functional tests for detail page tabs (imported items, messages, failed items)
- [ ] Functional tests for run/rollback confirm forms and batch operations
- [ ] Functional tests for permission matrix form
- [ ] Functional tests for per-migration access control (view/run/rollback)
- [ ] Functional tests for provenance pseudo-field rendering on node view/edit
- [ ] Functional tests for source link settings and token replacement

### Configuration Schema
- [ ] Add `config/schema/migrate_health.schema.yml` for health settings
- [ ] Add `config/schema/migrate_suite.schema.yml` for source link settings
- [ ] Add `config/install/` YAML files with default configuration values

### Packaging
- [ ] Add `composer.json` for Composer-based installation and Drupal.org packaging
- [ ] Fix package name inconsistency (`Migration` vs `Migrate Suite` in `migrate_admin.info.yml`)

### Future Phases
- [ ] **Phase 2:** Re-sync / re-import button on individual entities; diff/summary after re-sync
- [ ] **Phase 3:** Rollback visibility / dry-run preview; re-run scheduling (cron-based); per-entity message viewer linked from entity edit form
- [ ] **Phase 4:** Views integration for provenance data; bulk QA tools; partial rollback of specific items; delta detection for smart re-runs
- [ ] Support provenance field on non-node entity types (media, taxonomy terms, etc.)
