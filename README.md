# Migrate Suite

A Drupal 10.4+ / 11.x module that provides an administration dashboard for managing and monitoring migrations. It fills a major gap in the Drupal migration ecosystem: once migrations are configured, there is no editor-friendly way to monitor their status, inspect migrated content, or control access per migration.

The core Migrate module and Migrate Tools provide Drush commands and a minimal admin page, but site managers, content leads, and non-developer admins are left without visibility. Migrate Suite changes that.

## Architecture

Migrate Suite is a parent module with six independently installable submodules. Each submodule depends only on the parent `migrate_suite` — no inter-submodule dependencies. Features degrade gracefully: the dashboard works standalone, and capabilities appear as submodules are enabled.

```
migrate_suite/
├── modules/
│   ├── migrate_admin/            Dashboard UI, detail pages, run/rollback actions
│   ├── migrate_permissions/      Per-migration permissions, permission matrix
│   ├── migrate_health/           Health badges, stale detection, failure rate monitoring
│   ├── migrate_source_field/     Provenance pseudo-field, original source links
│   ├── migrate_schedule/         Cron-based scheduling with dependency ordering
│   └── migrate_views/            Views integration for run history
```

## Requirements

- Drupal `^10.4 || ^11`
- Core `migrate` module enabled
- Optional: `migrate_tools` (its `administer migrations` permission is recognized as an admin bypass)

### Migration compatibility

Migrate Suite reads the `migrate_map_*` and `migrate_message_*` tables that core
creates, so it works retroactively against content that was migrated before the
module was installed — no re-run required.

Derived migrations are supported. Migrations whose ID contains a colon
(`d7_node:article`, and therefore effectively all of `migrate_drupal`), whose ID
contains uppercase characters, or whose ID is long enough that core truncates
the table name, all resolve correctly.

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

Full administration dashboard at `/admin/structure/migrate-suite`.

- Lists all migrations with status, group, counts, and last run time
- Color-coded status badges, grouped by migration group, with filtering
- Detail page per migration with tabs: Imported Items, Messages, Failed Items, Run History
- Messages tab with severity summary, grouping, full-text search, CSV export, and re-run by error
- Run and rollback with dependency warnings and source change detection
- Rollback dry-run preview and partial rollback (select specific items)

**Permission:** `view migrate suite dashboard`

### migrate_permissions — Granular Access Control

Per-migration permissions: `view`, `run`, and `rollback` per migration.

- Permission matrix UI at `/admin/structure/migrate-suite/permissions`
- Dashboard and detail pages respect per-migration access when enabled
- Admin bypass for `administer migrations` and `administer site configuration`

### migrate_health — Health Monitoring

Health indicators per migration: **Healthy**, **Stale**, or **Failing**.

- Stale: last run exceeds configurable threshold (default: 7 days)
- Failing: failure rate exceeds configurable threshold (default: 5%)
- Health badge column and aggregate summary on the dashboard
- Configure at `/admin/structure/migrate-suite/settings`

### migrate_source_field — Content Provenance

Migration provenance pseudo-field on migrated nodes.

- Shows migration name, source IDs, import date, original source link
- Enable per content type via Manage Display
- Configurable source URL patterns with `[source_id]` token at `/admin/structure/migrate-suite/settings/source-links`
- Per-entity message viewer at `/admin/structure/migrate-suite/entity/{type}/{id}/messages`

### migrate_schedule — Cron-Based Scheduling

Per-migration scheduling: disabled, hourly, daily, or weekly.

- Respects migration dependencies
- Optional skip when source data is unchanged
- Configure at `/admin/structure/migrate-suite/settings/schedules`

### migrate_views — Views Integration

Exposes migration run history to Views.

- **Migration Run Log** available as a Views base table
- Fields: migration ID, operation, status, timestamps, item counts
- Status badge field plugin, migration ID dropdown filter

## Admin Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite` | Dashboard | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}` | Migration detail | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages` | Messages | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/failed` | Failed items | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/failed/reset` | Reset failed items for retry | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/history` | Run history | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/run` | Run confirm | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/rollback` | Rollback confirm | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/partial-rollback` | Partial rollback | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages/export` | CSV export | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/{id}/messages/rerun` | Re-run by error | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/entity/{type}/{id}/messages` | Entity messages | `view migrate suite dashboard` |
| `/admin/structure/migrate-suite/permissions` | Permission matrix | `administer permissions` |
| `/admin/structure/migrate-suite/settings` | Health settings | `administer site configuration` |
| `/admin/structure/migrate-suite/settings/source-links` | Source links | `administer site configuration` |
| `/admin/structure/migrate-suite/settings/schedules` | Schedules | `administer site configuration` |

## Development

Tests live under `tests/` in the parent module and `modules/*/tests/` in each
submodule, following the standard Drupal layout.

```bash
# From your Drupal root, with the module in modules/contrib/migrate_suite
vendor/bin/phpunit -c core --group migrate_suite
```

Unit and kernel tests cover the shared services, health analysis and run
logging. Functional tests for the admin UI are not written yet.

### Coding standards

The module is checked against the `Drupal` and `DrupalPractice` sniffs
(`phpcs.xml.dist`) and PHPStan level 2 (`phpstan.neon`). Both run on every push
through `.gitlab-ci.yml`, which inherits drupal.org's shared pipeline.

PHPCS needs no Drupal codebase, so it can run straight from the module
directory:

```bash
composer global require drupal/coder
export PATH="$PATH:$HOME/.composer/vendor/bin"

phpcs          # or: composer lint
phpcbf         # or: composer lint:fix
```

PHPStan resolves Drupal classes against the surrounding site, so run it from a
Drupal root with the module installed under `modules/contrib/migrate_suite`:

```bash
vendor/bin/phpstan analyse modules/contrib/migrate_suite
```

The tree is currently free of PHPCS errors and warnings; keep it that way
rather than adding exclusions.

When adding code that touches a migration's map or message table, resolve the
table name through the `migrate_suite.table_name_resolver` service. Do not
concatenate `'migrate_map_' . $migration_id` — core applies transformations to
that name, and skipping them silently returns empty results for derived
migrations.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later.
