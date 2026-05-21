# Migrate Source Field

Content provenance pseudo-field and original source links on migrated entities.

## Features

- Registers a `migrate_provenance` pseudo-field on all node bundles
- Displays on node view and edit forms:
  - Migration name (linked to detail page if user has dashboard access)
  - Source ID(s)
  - Import date
  - Original source link (if URL pattern configured)
  - Link to migration messages
- Hidden by default — enable per bundle via **Manage Display** / **Manage Form Display**
- Configurable source URL patterns per migration with `[source_id]` token replacement
- **Per-entity message viewer** at `/admin/structure/migrate-suite/entity/{type}/{id}/messages`
- Cached with `migrate_source_field:provenance` tag, invalidated on post-import

## How It Works

The `ProvenanceLookup` service reverse-looks up an entity: given entity type + ID, it iterates all migrations targeting that entity type and queries each `migrate_map_*` table for a matching `destid1`.

Source URL patterns are stored in `migrate_suite.settings` config under the `source_links` key, keyed by migration ID. The `[source_id]` token is replaced with the source ID value (composite keys joined with `/`).

## Configuration

Source link patterns at `/admin/structure/migrate-suite/settings/source-links`.

Example pattern: `https://old-site.com/node/[source_id]`

## Routes

| Path | Description | Permission |
|---|---|---|
| `/admin/structure/migrate-suite/settings/source-links` | Source link patterns | `administer site configuration` |
| `/admin/structure/migrate-suite/entity/{type}/{id}/messages` | Entity messages | `view migrate suite dashboard` |

## Dependencies

- `migrate_suite:migrate_suite`
