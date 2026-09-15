# Changelog

All notable changes to Migrate Suite are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Drupal.org release notes are authored on the release node; this file is the
source to write them from.

## [Unreleased]

Nothing has been released yet. `1.0.0` will be the initial release — see
[README.md](README.md) for the full feature set it will ship with.

### Fixed

- **"Failed items" meant "ignored items" everywhere.** Core's
  `MigrateIdMapInterface::STATUS_FAILED` is `3`; `2` is `STATUS_IGNORED`. Seven
  call sites hard-coded `2` as the failed status, so the dashboard's failed
  count, the detail page's failed-items tab, the health badge's failure rate
  and the failed-items reset form all operated on ignored rows and never saw a
  real failure. The reset form was the worst of these: it rewrote ignored rows
  to `STATUS_IMPORTED` and left genuinely failed rows untouched. All status
  comparisons now use the core constants, and the row status label gained the
  missing "Ignored" entry.

- **Status and severity counts crashed with a fatal error.** `addExpression()`
  returns the expression alias, not the query object, so the chained
  `->execute()` in `MigrateMapQuery::countItemsByStatus()`,
  `MigrateMessageQuery::getSeverityCounts()` and the dashboard's item counts
  called a method on a string.

- **Derived migrations are no longer invisible to the entire suite.** Map and
  message table names were built by concatenating the raw migration ID onto
  `migrate_map_` / `migrate_message_`. Drupal core does not name those tables
  that way: it replaces `:` with `__`, lowercases the result, and truncates to
  `63 - strlen($db_prefix)` characters (see
  `Drupal\migrate\Plugin\migrate\id_map\Sql::__construct()`).

  As a result, every derived migration — `d7_node:article` and effectively all
  of `migrate_drupal` — resolved to a table that does not exist. Because each
  query was guarded by `tableExists()`, this failed silently rather than
  erroring: dashboards showed zero imported items, no messages, no failed
  items, "Never" as the last run, and health permanently `stale`. Migrations
  with uppercase characters or IDs longer than 63 characters were affected the
  same way.

  All 18 call sites across 11 files now resolve through a single new service.

### Added

- `migrate_suite.table_name_resolver` (`MigrateTableNameResolver`) — the
  canonical way to derive a migration's map and message table names. Any new
  code that needs one of those tables must go through this service rather than
  building the name itself.
- `.cspell-project-words.txt`, the project dictionary the drupal.org cspell job
  reads, holding the map-table column names `sourceid` and `destid`.
- Linting configuration: `phpcs.xml.dist` (Drupal + DrupalPractice),
  `phpstan.neon` (level 2) and `.gitlab-ci.yml`, which inherits drupal.org's
  shared pipeline and runs PHPUnit, PHPCS and PHPStan against both supported
  core majors. `composer lint` / `composer lint:fix` run PHPCS locally.
- Unit coverage for the resolver (plain, single-colon, multi-colon, mixed-case
  and over-length IDs, with and without a database table prefix), plus
  derived-ID regression tests for `MigrateMapQuery` and
  `MigrationHealthAnalyzer`.

### Changed

- The whole tree now passes `Drupal` and `DrupalPractice` coding standards with
  zero errors and zero warnings. Constructor docblocks document every promoted
  parameter; `PartialRollbackForm`, `ScheduleManager` and `PermissionMatrixForm`
  inject the private tempstore, time and entity type manager services instead of
  calling `\Drupal::` and `Role::load()` statically; `MigrationDetailController`
  uses `$this->entityTypeManager()`; and dead local variables are gone. No
  behaviour changes.
- `MigrateMapQuery` and `MigrateMessageQuery` take the resolver as a
  constructor argument, as do `MigrationHealthAnalyzer`, `ProvenanceLookup`,
  `MigrationRunWorker`, and the `migrate_admin` controllers and forms.

### Removed

- Bootstrapping scaffolding (`.chief/`, `docs/PLAN.md`) that predated the
  current suite architecture.

[Unreleased]: https://git.drupalcode.org/project/migrate_suite/-/commits/1.0.x
