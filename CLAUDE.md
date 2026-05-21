# Migrate Suite - Claude Code Context

## Project Overview

Drupal contributed module (`drupal/migrate_suite`) providing an admin dashboard for managing and monitoring Drupal migrations. Published at https://www.drupal.org/project/migrate_suite

**Drupal compatibility:** `^10.4 || ^11`
**Core dependency:** `drupal:migrate`
**Branch:** `1.0.x` (dev), main branch: `main`

## Module Architecture

```
migrate_suite/                        Parent module: shared services, event subscriber, run_log table
├── modules/
│   ├── migrate_admin/                Dashboard UI, detail pages, run/rollback batch actions
│   ├── migrate_permissions/          Per-migration dynamic permissions, permission matrix UI
│   ├── migrate_health/               Health badges, stale detection, failure rate monitoring
│   ├── migrate_source_field/         Provenance pseudo-field, original source links on nodes
│   ├── migrate_schedule/             Cron-based per-migration scheduling with QueueWorker
│   └── migrate_views/                Views integration for run_log table
```

Submodules depend only on the parent (`migrate_suite:migrate_suite`), never on each other. Features degrade gracefully when submodules are absent.

## Key Files

| File | Purpose |
|---|---|
| `migrate_suite.info.yml` | Parent module definition |
| `migrate_suite.install` | Schema for `migrate_suite_run_log` table + update hooks |
| `migrate_suite.services.yml` | Shared services: `map_query`, `message_query`, `run_logger`, `delta_detection` |
| `composer.json` | Composer/Drupal.org packaging metadata |
| `src/Service/MigrateMapQuery.php` | Queries `migrate_map_*` tables (dest ID lookup, imported items, status counts, rollback preview, partial delete) |
| `src/Service/MigrateMessageQuery.php` | Queries `migrate_message_*` tables (messages, severity counts, grouped messages, search, CSV export) |
| `src/Service/DeltaDetectionService.php` | SHA-256 fingerprinting of source data for change detection |
| `src/EventSubscriber/MigrateRunLogger.php` | Logs import and rollback runs to `migrate_suite_run_log` with delta data |

### migrate_admin
- `src/Controller/MigrationDashboardController.php` — Main dashboard listing all migrations
- `src/Controller/MigrationDetailController.php` — Detail page with imported/messages/failed/history tabs
- `src/Controller/MessageExportController.php` — CSV export of migration messages
- `src/Form/MigrationRunConfirmForm.php` — Run confirmation with dependency warnings + delta detection
- `src/Form/MigrationRollbackConfirmForm.php` — Rollback confirmation with dry-run preview
- `src/Form/FailedItemsResetForm.php` — Bulk reset failed items for retry
- `src/Form/RerunByErrorForm.php` — Re-run items matching a specific error message
- `src/Form/PartialRollbackForm.php` — Select items for partial rollback
- `src/Form/PartialRollbackConfirmForm.php` — Confirm and execute partial rollback

### migrate_permissions
- `src/MigratePermissions.php` — Dynamic permission generation (view/run/rollback per migration)
- `src/MigrateAccessCheck.php` — Access checker service
- `src/Form/PermissionMatrixForm.php` — Roles x migrations permission matrix

### migrate_health
- `src/Service/MigrationHealthAnalyzer.php` — Computes health states (healthy/stale/failing)
- `src/Form/HealthSettingsForm.php` — Configurable thresholds

### migrate_source_field
- `src/Service/ProvenanceLookup.php` — Finds migration provenance for entities
- `src/Controller/EntityMessagesController.php` — Per-entity migration message viewer
- `src/Form/SourceLinkSettingsForm.php` — Source URL patterns with `[source_id]` token
- `migrate_source_field.module` — `hook_entity_extra_field_info()` registration

### migrate_schedule
- `src/Service/ScheduleManager.php` — Per-migration schedule config (hourly/daily/weekly)
- `src/Plugin/QueueWorker/MigrationRunWorker.php` — Processes scheduled migrations with dependency ordering
- `src/Form/ScheduleSettingsForm.php` — Schedule configuration per migration
- `migrate_schedule.module` — `hook_cron()` enqueuing due migrations

### migrate_views
- `migrate_views.views.inc` — `hook_views_data()` exposing `migrate_suite_run_log`
- `src/Plugin/views/field/MigrationStatus.php` — Status badge field plugin
- `src/Plugin/views/filter/MigrationIdFilter.php` — Migration ID dropdown filter

## Coding Standards

- Follow **Drupal coding standards** (PSR-12 based with Drupal-specific conventions)
- Use **render arrays** for all UI — no custom Twig templates
- Use **typed PHP** (return types, parameter types) — Drupal 10.4+ supports PHP 8.2+
- Services defined in `*.services.yml`, injected via constructors
- Permissions defined in `*.permissions.yml` or via `permission_callbacks`
- Routes in `*.routing.yml`, menu links in `*.links.menu.yml`
- Database queries via Drupal's database abstraction layer (`\Drupal::database()` / injected `$database`)
- Hooks in `*.module` files, classes in `src/` following PSR-4 (`Drupal\{module_name}\...`)

## Known Issues / TODOs

- **No dev release on Drupal.org yet** — branch needs to be pushed to Drupal.org GitLab, then create release via the project page
- **Functional tests** — kernel/unit tests exist for core services; functional tests for UI not yet written
- **Views map table support** — `migrate_views` only exposes `run_log`; dynamic map table Views support deferred
- **Non-node provenance** — provenance pseudo-field currently only registered for nodes

## Admin Routes

| Path | Controller/Form |
|---|---|
| `/admin/structure/migrate-suite` | `MigrationDashboardController` |
| `/admin/structure/migrate-suite/{id}` | `MigrationDetailController` |
| `/admin/structure/migrate-suite/{id}/messages` | `MigrationDetailController::messages` |
| `/admin/structure/migrate-suite/{id}/failed` | `MigrationDetailController::failedItems` |
| `/admin/structure/migrate-suite/{id}/history` | `MigrationDetailController::history` |
| `/admin/structure/migrate-suite/{id}/run` | `MigrationRunConfirmForm` |
| `/admin/structure/migrate-suite/{id}/rollback` | `MigrationRollbackConfirmForm` |
| `/admin/structure/migrate-suite/{id}/partial-rollback` | `PartialRollbackForm` |
| `/admin/structure/migrate-suite/{id}/messages/export` | `MessageExportController` |
| `/admin/structure/migrate-suite/{id}/messages/rerun` | `RerunByErrorForm` |
| `/admin/structure/migrate-suite/{id}/failed/reset` | `FailedItemsResetForm` |
| `/admin/structure/migrate-suite/entity/{type}/{id}/messages` | `EntityMessagesController` |
| `/admin/structure/migrate-suite/permissions` | `PermissionMatrixForm` |
| `/admin/structure/migrate-suite/settings` | `HealthSettingsForm` |
| `/admin/structure/migrate-suite/settings/source-links` | `SourceLinkSettingsForm` |
| `/admin/structure/migrate-suite/settings/schedules` | `ScheduleSettingsForm` |

## Design Principles

- Query `migrate_map_*` tables directly — no redundant storage
- Pseudo-fields over stored fields — computed at render time
- Dynamic permissions generated from migration plugin definitions
- Graceful degradation — dashboard works standalone, features appear per enabled submodule
- Batch API for run/rollback operations with dependency checking
- Delta detection via source fingerprinting — avoid redundant re-imports

## Git Workflow

- Development branch: `1.0.x`
- Main branch: `main`
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):
  - `feat: Description` — new feature
  - `fix: Description` — bug fix
  - `docs: Description` — documentation only
  - `refactor: Description` — code change that neither fixes a bug nor adds a feature
  - `test: Description` — adding or updating tests
  - `chore: Description` — maintenance, dependencies, CI
  - Scope is optional: `feat(health): Description`
  - Body should explain "why", not "what"
- PRDs and planning docs in `.chief/prds/` and `docs/`
