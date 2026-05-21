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
│   └── migrate_source_field/         Provenance pseudo-field, original source links on nodes
```

Submodules depend only on the parent (`migrate_suite:migrate_suite`), never on each other. Features degrade gracefully when submodules are absent.

## Key Files

| File | Purpose |
|---|---|
| `migrate_suite.info.yml` | Parent module definition |
| `migrate_suite.install` | Schema for `migrate_suite_run_log` table |
| `migrate_suite.services.yml` | Shared services: `map_query`, `message_query`, `run_logger` |
| `src/Service/MigrateMapQuery.php` | Queries `migrate_map_*` tables (dest ID lookup, imported items, status counts) |
| `src/Service/MigrateMessageQuery.php` | Queries `migrate_message_*` tables |
| `src/EventSubscriber/MigrateRunLogger.php` | Logs migration runs to `migrate_suite_run_log` |

### migrate_admin
- `src/Controller/MigrationDashboardController.php` — Main dashboard listing all migrations
- `src/Controller/MigrationDetailController.php` — Detail page with imported/messages/failed tabs
- `src/Form/MigrationRunConfirmForm.php` — Run confirmation with dependency warnings
- `src/Form/MigrationRollbackConfirmForm.php` — Rollback confirmation
- `src/Form/FailedItemsResetForm.php` — Bulk reset failed items for retry

### migrate_permissions
- `src/MigratePermissions.php` — Dynamic permission generation (view/run/rollback per migration)
- `src/MigrateAccessCheck.php` — Access checker service
- `src/Form/PermissionMatrixForm.php` — Roles x migrations permission matrix

### migrate_health
- `src/Service/MigrationHealthAnalyzer.php` — Computes health states (healthy/stale/failing)
- `src/Form/HealthSettingsForm.php` — Configurable thresholds

### migrate_source_field
- `src/Service/ProvenanceLookup.php` — Finds migration provenance for entities
- `src/Form/SourceLinkSettingsForm.php` — Source URL patterns with `[source_id]` token
- `migrate_source_field.module` — `hook_entity_extra_field_info()` registration

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

- **No tests yet** — needs kernel tests for services and functional tests for UI
- **No dev release on Drupal.org yet** — branch needs to be pushed to Drupal.org GitLab, then create release via the project page

## Admin Routes

| Path | Controller/Form |
|---|---|
| `/admin/structure/migrate-suite` | `MigrationDashboardController` |
| `/admin/structure/migrate-suite/{id}` | `MigrationDetailController` |
| `/admin/structure/migrate-suite/{id}/run` | `MigrationRunConfirmForm` |
| `/admin/structure/migrate-suite/{id}/rollback` | `MigrationRollbackConfirmForm` |
| `/admin/structure/migrate-suite/{id}/failed/reset` | `FailedItemsResetForm` |
| `/admin/structure/migrate-suite/permissions` | `PermissionMatrixForm` |
| `/admin/structure/migrate-suite/settings` | `HealthSettingsForm` |
| `/admin/structure/migrate-suite/settings/source-links` | `SourceLinkSettingsForm` |

## Design Principles

- Query `migrate_map_*` tables directly — no redundant storage
- Pseudo-fields over stored fields — computed at render time
- Dynamic permissions generated from migration plugin definitions
- Graceful degradation — dashboard works standalone, features appear per enabled submodule
- Batch API for run/rollback operations with dependency checking

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
