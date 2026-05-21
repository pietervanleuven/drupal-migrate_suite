# Migrate Views

Views integration for migration run history.

## Features

- Exposes the `migrate_suite_run_log` table as a Views base table
- All run log fields available as Views fields, filters, and sorts:
  - Migration ID, operation (import/rollback), status, started/finished timestamps
  - Item counts: processed, created, updated, failed, deleted
  - Delta detection data: source count, source hash
- **MigrationStatus** field plugin — renders status as a colored badge
- **MigrationIdFilter** filter plugin — dropdown of all known migration plugin IDs

## Usage

After enabling the module:

1. Go to **Structure > Views > Add view**
2. Select **Migration Run Log** as the view type
3. Add fields, filters, and sorts from the "Migration Run Log" group

Example use cases:
- Run history report filtered by migration and date range
- Failed run alert view showing only `status = failed`
- Migration activity dashboard with item counts over time

## Scope

This module exposes the `migrate_suite_run_log` table only. Dynamic `migrate_map_*` table support is not included due to the variable table naming — each migration creates its own map table at runtime.

## Dependencies

- `migrate_suite:migrate_suite`
- `drupal:views`
