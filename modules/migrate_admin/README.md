# Migrate Admin

Migration dashboard with status overview, detail pages, and batch operations.

## Features

- **Dashboard** at `/admin/structure/migrate-suite` listing all migrations grouped by migration group
- Color-coded status badges, source/imported/failed counts, last run time
- Filter by text search, status, and group
- **Detail page** per migration with four tabs:
  - **Imported Items** — source IDs, linked destination entity, row status, import date, search/filter
  - **Messages** — severity summary, individual or grouped view, full-text search, CSV export, re-run by error
  - **Failed Items** — failed rows with error messages, bulk reset for retry
  - **Run History** — chronological import/rollback timeline with duration and item counts
- **Run and Rollback** with dependency warnings and source change detection
- **Rollback preview** showing affected entities before execution
- **Partial rollback** — select specific items to roll back

## Permission

`view migrate suite dashboard` — required for all pages. When `migrate_permissions` is enabled, run/rollback access is further restricted per migration.

## Dependencies

- `migrate_suite:migrate_suite`
