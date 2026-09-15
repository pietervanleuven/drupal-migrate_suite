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

- **Running a migration from the UI died on anything large.**
  `MigrationRunConfirmForm::batchImport()` called `$executable->import()`
  once for the entire migration inside a single batch operation, and the
  rollback form did the same. There was no item limit and no progress: the
  batch bar jumped straight from 0% to 100%, and any migration big enough to
  exceed `max_execution_time` or the memory limit killed the request
  mid-import, leaving a half-finished migration behind.

  Both forms now drive a sandbox-backed batch loop through a new
  `MigrateBatchExecutable`, which counts rows via core's `POST_ROW_SAVE` /
  `POST_ROW_DELETE` events and calls `interruptMigration()` once the
  per-chunk limit is reached — the same lever `migrate_tools` uses, and the
  only one core offers. Progress is reported per chunk against the source
  count, falling back to status-driven completion when a source reports an
  unknown row count. Chunks that make no progress terminate the batch rather
  than repeating.

- **Run-log counters were fiction.** Create-versus-update was decided from
  `Row::getDestination()`, which holds the row's *processed* destination
  values and is always populated by the time `POST_ROW_SAVE` fires — so
  every row counted as an update and `items_created` was permanently 0. The
  reliable signal is `Row::getIdMap()`, which `SourcePluginBase::next()`
  fills from the map's *pre-run* state.

  `items_failed` was worse: it was read from `POST_ROW_SAVE`, an event core
  never dispatches for a failed row. Every failure path in
  `MigrateExecutable::import()` instead converges on
  `saveIdMapping(..., STATUS_FAILED)`, which dispatches
  `MigrateEvents::MAP_SAVE`. Failures are now counted there, so
  `items_failed` is real and a run's status is no longer always `completed`.

- **One user-initiated run now logs exactly one row.** Because core fires
  `PRE_IMPORT`/`POST_IMPORT` inside *every* `import()` call, chunking would
  otherwise have written one run-log row per chunk, with counters resetting
  each time — Drupal batches span multiple HTTP requests, so the
  subscriber's in-memory counters do not survive between chunks at all.
  `MigrateRunLogger` gained a state-backed run session: the batch forms open
  one before the first chunk and close it from the batch `finished`
  callback, which runs on the success, failure and abort paths alike.
  Non-batch callers — Drush, and `migrate_schedule`'s queue worker — never
  open a session and are unaffected.

- **Interrupted runs stayed `running` forever.** Nothing ever reconciled a
  row whose `POST_IMPORT` never fired, so a killed request left a migration
  looking permanently in progress on the dashboard and to health checks —
  and left `ScheduleManager::isDue()` re-enqueueing it on every cron. A new
  `StaleRunReaper`, run from `hook_cron()`, marks rows that have been
  `running` for more than six hours as `failed` and clears any lingering run
  session pointing at them.

- **Dashboard and detail pages served stale and cross-user content.** Both
  tagged their render arrays with `migration_plugins`, which invalidates
  only when migration *configuration* changes — never when a migration
  *runs* — so every count went stale after the first import. They also
  varied output by the viewer's permissions without declaring the
  `user.permissions` cache context, letting the dynamic page cache serve one
  user's variant to another. Pages now carry `user.permissions` and the new
  `migrate_suite:runs` / `migrate_suite:run:{id}` tags, which the run logger
  and the reaper invalidate.

- **The dashboard could not render with `migrate_drupal` enabled.** It
  instantiated every migration plugin and then issued roughly seven queries
  plus a source-plugin `count()` per migration — with hundreds of
  migrations, hundreds of potentially remote source queries on page load.
  Enumeration now uses `getDefinitions()`, the listing is paginated, and
  only the migrations on the current page are instantiated and queried. The
  per-row source count is gone from the listing entirely and is shown on the
  detail page instead, where it costs one query for one migration.

- **`migrate_source_field` owned the wrong config object.** It provided
  `migrate_suite.settings`, but a config prefix must match the module that
  supplies it. Renamed to `migrate_source_field.settings`, with an update
  hook that migrates existing data. Doing this before the first release
  avoids a breaking rename afterwards.

- **Provenance lookups ran uncached on every node view and edit.**
  `ProvenanceLookup::lookupProvenance()` instantiated every migration plugin
  and then queried once per candidate migration, on every entity view and
  every entity form. The existing `migrate_source_field:provenance` cache
  tag was invalidated by the run logger but nothing ever cached against it.
  Results — including the common "no provenance" result — are now cached and
  tagged with both that tag and the entity's own.

- **Message CSV export was unvalidated and unguarded.**
  `MessageExportController` accepted any `{migration_id}` from the URL
  without checking it resolved to a real migration, enforced only the
  suite-wide dashboard permission rather than the per-migration view
  permission the messages page itself applies, interpolated the raw
  migration ID into the `Content-Disposition` header, and wrote message text
  to CSV unescaped — so a message beginning with `=`, `+`, `-`, `@`, tab or
  carriage return was treated as a formula by Excel, LibreOffice and Sheets.
  All four are fixed; the header now goes through Symfony's
  `makeDisposition()`.

- **A failing scheduled migration retried forever.**
  `MigrationRunWorker::processItem()` left `$executable->import()` outside
  its `try`, so an exception escaped the queue worker and Drupal released
  the item back to the queue to be retried on the next cron, indefinitely.
  It is now wrapped in `catch (\Throwable)` — catching `Error` as well as
  `Exception`, the same hazard `DeltaDetectionService` was hardened against
  — and logs the failure so the item is consumed rather than retried.

- **Migration IDs collided in settings form keys.** Both the schedule and
  source-link forms derived a form key with
  `str_replace('.', '__', $migrationId)`, which is not injective (`a.b` and
  `a__b` produce the same key) and ignores `:`, the separator in every
  derived migration ID. Both forms now generate an opaque key per migration
  and keep the mapping, so the round trip is exact.

- **"Failed items" meant "ignored items" everywhere.** Core's
  `MigrateIdMapInterface::STATUS_FAILED` is `3`; `2` is `STATUS_IGNORED`. Seven
  call sites hard-coded `2` as the failed status, so the dashboard's failed
  count, the detail page's failed-items tab, the health badge's failure rate
  and the failed-items reset form all operated on ignored rows and never saw a
  real failure. The reset form was the worst of these: it rewrote ignored rows
  to `STATUS_IMPORTED` and left genuinely failed rows untouched. All status
  comparisons now use the core constants, and the row status label gained the
  missing "Ignored" entry.

- **Admin forms and the scheduled-run queue worker held readonly promoted
  properties.** `FormBase` uses
  `DependencySerializationTrait`, whose `__wakeup()` cannot reinitialize a
  readonly property declared in a subclass on PHP below 8.4 — and `PluginBase`
  does the same for queue workers. Every form in the suite, plus
  `MigrationRunWorker`, declared its injected services that way, so restoring a
  cached form or a serialized worker would fail. The `readonly` modifier is gone
  from all 31 of them.

- **`migrate_source_field_form_node_form_alter()` assumed an entity form.**
  `FormStateInterface::getFormObject()` returns a `FormInterface`, which has no
  `getEntity()`; the hook now checks for `EntityFormInterface` first.

- **Delta detection could abort an import.** `DeltaDetectionService` caught
  `\Exception`, so a PHP `Error` raised by a source plugin escaped the
  `PRE_IMPORT` subscriber and took the migration run down with it. It now
  catches `\Throwable`, degrading to "no fingerprint" as intended.

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

- `Drupal\migrate_admin\MigrateBatchExecutable` — a `MigrateExecutable` that
  stops itself after a bounded number of rows so a batch operation can run a
  migration in chunks instead of one unbounded call.
- `migrate_suite.stale_run_reaper` (`StaleRunReaper`) and a `hook_cron()`
  implementation that reconciles run-log rows abandoned in the `running`
  state.
- Run sessions on `migrate_suite.run_logger`: `startRunSession()`,
  `endRunSession()` and `clearRunSession()`, which let a multi-request batch
  record one logical run across many chunks.
- The `migrate_suite:runs` and `migrate_suite:run:{migration_id}` cache
  tags, invalidated whenever a run or rollback starts, finishes or is
  reaped. Any code caching migration run data should tag against these.
- A `configure:` key in every submodule that has a settings form, so each
  gets a "Configure" link on the Extend page.

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

- Drupal 12 compatibility
  ([#3600350](https://www.drupal.org/project/migrate_suite/issues/3600350)):
  `core_version_requirement` allows `^12`, and every procedural hook moved
  to an object-oriented `#[Hook]` class (`MigrateSuiteHooks`,
  `MigrateScheduleHooks`, `MigrateSourceFieldHooks`), with the `.module`
  functions kept as `#[LegacyHook]` delegates so Drupal 10.4 keeps working.
- The migration dashboard is paginated (50 per page) and no longer shows a
  "Source count" column; that figure moved to the migration detail page.
  Counting a migration's source can mean an arbitrary query against a remote
  database, which is not something a listing should do once per row.
- The `@QueueWorker`, `@ViewsField` and `@ViewsFilter` annotations became PHP
  attributes. Plugin IDs are unchanged.

- The whole tree now passes `Drupal` and `DrupalPractice` coding standards with
  zero errors and zero warnings. Constructor docblocks document every promoted
  parameter; `PartialRollbackForm`, `ScheduleManager` and `PermissionMatrixForm`
  inject the private tempstore, time and entity type manager services instead of
  calling `\Drupal::` and `Role::load()` statically; `MigrationDetailController`
  uses `$this->entityTypeManager()`; and dead local variables are gone. No
  behaviour changes.
- `MigrationRunWorker` injects the database connection and logger factory
  rather than calling `\Drupal::database()` and `\Drupal::logger()`; the suite
  now has no `\Drupal::` calls left inside classes.
- `PartialRollbackConfirmForm` injects the private tempstore and
  `MigrationIdFilter` injects the migration plugin manager, instead of calling
  `\Drupal::service()`. `MigrationDashboardController` stops re-declaring
  `ControllerBase::$moduleHandler` as a promoted property.
- `MigrateMapQuery` and `MigrateMessageQuery` take the resolver as a
  constructor argument, as do `MigrationHealthAnalyzer`, `ProvenanceLookup`,
  `MigrationRunWorker`, and the `migrate_admin` controllers and forms.

### Removed

- `migrate_schedule`'s custom cron expression. It was stored by
  `setSchedule()` and declared in the config schema, but `isDue()` never
  read it and no form ever offered it — config promising behaviour that did
  not exist. Only the `hourly`, `daily` and `weekly` intervals that
  `isDue()` actually implements remain.
- Bootstrapping scaffolding (`.chief/`, `docs/PLAN.md`) that predated the
  current suite architecture.

[Unreleased]: https://git.drupalcode.org/project/migrate_suite/-/commits/1.0.x
