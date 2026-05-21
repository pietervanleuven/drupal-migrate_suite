# Migrate Admin

Migration dashboard with status overview, detail pages, and batch operations.

## Features

- **Dashboard** at `/admin/structure/migrate-suite` listing all migrations grouped by `migration_group`
- Color-coded status badges, source/imported/failed counts, last run time
- Filter by text search, status, and group
- **Detail page** per migration with four tabs:
  - **Imported Items** — source IDs, linked destination entity, row status, import date, search/filter
  - **Messages** — severity summary badges, individual or grouped view, full-text search, CSV export, re-run by error
  - **Failed Items** — failed rows with error messages, bulk reset for retry
  - **Run History** — chronological import/rollback timeline with duration and item counts
- **Run/Rollback** via Drupal Batch API with dependency warnings and delta detection
- **Rollback dry-run preview** showing affected entities before execution
- **Partial rollback** — select specific items via tableselect

## Routes

| Path | Description |
|---|---|
| `/admin/structure/migrate-suite` | Dashboard |
| `/admin/structure/migrate-suite/{id}` | Detail (imported items) |
| `/admin/structure/migrate-suite/{id}/messages` | Messages |
| `/admin/structure/migrate-suite/{id}/messages/export` | CSV export |
| `/admin/structure/migrate-suite/{id}/messages/rerun` | Re-run by error |
| `/admin/structure/migrate-suite/{id}/failed` | Failed items |
| `/admin/structure/migrate-suite/{id}/failed/reset` | Reset failed items |
| `/admin/structure/migrate-suite/{id}/history` | Run history |
| `/admin/structure/migrate-suite/{id}/run` | Run confirm |
| `/admin/structure/migrate-suite/{id}/rollback` | Rollback confirm |
| `/admin/structure/migrate-suite/{id}/partial-rollback` | Partial rollback |

## Permission

`view migrate suite dashboard` — required for all routes. Granular run/rollback access is enforced at form level when `migrate_permissions` is enabled.

## Dependencies

- `migrate_suite:migrate_suite`
