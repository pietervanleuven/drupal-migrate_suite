# Migrate Schedule

Cron-based per-migration scheduling with dependency ordering.

## Features

- Configure a schedule per migration: **disabled**, **hourly**, **daily**, or **weekly**
- Optional **skip if unchanged** — avoids redundant re-imports when source data hasn't changed
- Respects migration dependencies — skips a migration if its required dependencies haven't run
- Schedule column appears on the dashboard when this module and `migrate_admin` are both enabled

## Configuration

Settings form at `/admin/structure/migrate-suite/settings/schedules`.

Scheduled migrations are picked up on each Drupal cron run and processed via a queue worker.

## Dependencies

- `migrate_suite:migrate_suite`
