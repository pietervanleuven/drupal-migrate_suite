# Migrate Views

Views integration for migration run history.

## Features

- Adds **Migration Run Log** as a Views base table
- Available fields: migration ID, operation (import/rollback), status, start/finish time, item counts, source fingerprint
- Status field renders as a colored badge
- Migration ID filter provides a dropdown of all known migrations

## Usage

1. Go to **Structure > Views > Add view**
2. Select **Migration Run Log** as the view type
3. Add fields, filters, and sorts from the "Migration Run Log" group

Example use cases:
- Run history report filtered by migration and date range
- Failed run alert view showing only failed imports
- Migration activity dashboard with item counts over time

## Dependencies

- `migrate_suite:migrate_suite`
- `drupal:views`
