# Migrate Permissions

Granular per-migration permissions integrated with Drupal's permission system.

## Features

- Dynamically generates three permissions per migration:
  - `view migration {id}` — view on dashboard and detail page
  - `run migration {id}` — trigger import
  - `rollback migration {id}` — trigger rollback
- **Permission matrix UI** at `/admin/structure/migrate-suite/permissions` — roles as columns, migrations as rows, grouped by migration group
- Admin bypass for `administer migrations` and `administer site configuration`
- Dashboard and detail pages filter content based on per-migration permissions
- Falls back to generic `view migrate suite dashboard` when this module is disabled

## How It Works

Permissions are generated via `permission_callbacks` in `migrate_permissions.permissions.yml`. The `MigratePermissions` class iterates all migration plugins and creates three permission entries per migration.

The `MigrateAccessCheck` service provides `canViewMigration()`, `canRunMigration()`, and `canRollbackMigration()` methods used by `migrate_admin` controllers and forms.

Migration IDs are normalized to safe permission names: lowercased and non-alphanumeric characters replaced with underscores.

## Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite/permissions` | Permission matrix | `administer permissions` |

## Dependencies

- `migrate_suite:migrate_suite`
