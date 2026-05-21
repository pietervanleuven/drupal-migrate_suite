# Migrate Permissions

Granular per-migration permissions integrated with Drupal's permission system.

## Features

- Three permissions per migration:
  - `view migration {id}` — view on dashboard and detail page
  - `run migration {id}` — trigger import
  - `rollback migration {id}` — trigger rollback
- **Permission matrix** at `/admin/structure/migrate-suite/permissions` — roles as columns, migrations as rows, grouped by migration group
- Admin bypass for users with `administer migrations` or `administer site configuration`
- When disabled, all users with `view migrate suite dashboard` see all migrations

## Dependencies

- `migrate_suite:migrate_suite`
