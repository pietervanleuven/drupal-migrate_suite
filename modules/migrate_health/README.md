# Migrate Health

Health badges, stale detection, and failure rate monitoring for migrations.

## Features

- Three health states per migration:
  - **Healthy** (green) — recent run, low failure rate
  - **Stale** (yellow) — never run or last run exceeds threshold
  - **Failing** (red) — failure rate exceeds threshold
- Health badge column and aggregate summary on the dashboard
- Configurable thresholds at `/admin/structure/migrate-suite/settings`:
  - Stale threshold: days since last run (default: 7)
  - Failure rate threshold: percentage of failed items (default: 5%)

## Dependencies

- `migrate_suite:migrate_suite`
