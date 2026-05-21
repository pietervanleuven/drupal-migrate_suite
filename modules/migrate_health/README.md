# Migrate Health

Health badges, stale detection, and failure rate monitoring for migrations.

## Features

- Three health states per migration:
  - **Healthy** (green) — recent run, low failure rate
  - **Stale** (yellow) — never run or last run exceeds threshold
  - **Failing** (red) — failure rate exceeds threshold
- Configurable thresholds:
  - Stale threshold: days since last run (default: 7)
  - Failure rate threshold: percentage of failed items (default: 5%)
- Health badge column on the dashboard (when enabled)
- Aggregate health summary bar on dashboard

## How It Works

The `MigrationHealthAnalyzer` service checks:
1. **Failure rate** (higher priority) — queries the `migrate_map_*` table for the ratio of failed items
2. **Staleness** — queries `migrate_suite_run_log` for the most recent completed run timestamp

Health is computed on page load, not stored separately.

## Configuration

Settings form at `/admin/structure/migrate-suite/settings`.

Default config installed at `config/install/migrate_health.settings.yml`:
```yaml
stale_threshold: 7
failure_rate_threshold: 5
```

## Dependencies

- `migrate_suite:migrate_suite`
