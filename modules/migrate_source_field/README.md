# Migrate Source Field

Content provenance pseudo-field and original source links on migrated entities.

## Features

- Adds a **Migration provenance** field to all node bundles showing:
  - Migration name (linked to the dashboard detail page)
  - Source ID(s)
  - Import date
  - Original source link (when a URL pattern is configured)
  - Link to migration messages for that entity
- Hidden by default — enable per bundle via **Manage Display** / **Manage Form Display**
- **Source URL patterns** configurable per migration at `/admin/structure/migrate-suite/settings/source-links` using a `[source_id]` token (e.g. `https://old-site.com/node/[source_id]`)
- **Per-entity message viewer** at `/admin/structure/migrate-suite/entity/{type}/{id}/messages`

## Dependencies

- `migrate_suite:migrate_suite`
