# Migrate Schedule

Cron-based per-migration scheduling with dependency ordering.

## Features

- Configure schedule per migration: **disabled**, **hourly**, **daily**, or **weekly**
- Optional **skip if unchanged** — uses delta detection to avoid redundant re-imports
- Cron-driven queue processing via Drupal's QueueWorker system
- Dependency checking — skips migrations whose required dependencies haven't run
- Schedule column on the dashboard (when `migrate_admin` is also enabled)

## How It Works

1. `hook_cron()` checks all configured schedules via `ScheduleManager::isDue()`
2. Due migrations are enqueued to the `migrate_schedule_run` queue
3. `MigrationRunWorker` (QueueWorker plugin, 5-minute time limit) processes items:
   - Optionally checks delta detection (skips if no source changes)
   - Verifies required migration dependencies have imported items
   - Runs the migration import via `MigrateExecutable`

## Configuration

Settings form at `/admin/structure/migrate-suite/settings/schedules`.

Each migration can be set to:
- `disabled` — no scheduled runs
- `hourly` — run if last completed import was 1+ hours ago
- `daily` — run if last completed import was 24+ hours ago
- `weekly` — run if last completed import was 7+ days ago

Config stored in `migrate_schedule.settings`:
```yaml
schedules:
  my_migration:
    interval: daily
    cron_expression: ''
    skip_if_no_changes: true
```

## Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite/settings/schedules` | Schedule settings | `administer site configuration` |

## Dependencies

- `migrate_suite:migrate_suite`
