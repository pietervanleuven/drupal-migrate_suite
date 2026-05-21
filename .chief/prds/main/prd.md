# PRD: Migrate Suite

## Introduction

**Migrate Suite** is a Drupal 10.4+ / 11.x module that provides an administration dashboard for managing and monitoring migrations. It fills a major gap in the Drupal migration ecosystem: once migrations are configured, there is no editor-friendly way to monitor their status, inspect migrated content, or control access per migration. The core Migrate module and Migrate Tools provide Drush commands and a minimal admin page, but site managers, content leads, and non-developer admins are left without visibility.

Migrate Suite is a parent module with four independently installable submodules:

- **migrate_admin** — Migration dashboard with status overview and imported items list
- **migrate_source_field** — Content provenance meta field and original source link field on migrated entities
- **migrate_permissions** — Granular per-migration permissions integrated with Drupal's permission system
- **migrate_health** — Health badges, stale detection, and failed item tracking

This PRD covers **Phase 1 (MVP)** only.

### Module Architecture

```
migrate_suite/                    (parent: shared services, event subscriber, base API)
├── modules/
│   ├── migrate_admin/            (dashboard UI, imported items list, messages viewer)
│   ├── migrate_source_field/     (provenance pseudo-field, source link field, Views integration)
│   ├── migrate_permissions/      (per-migration permissions, permission matrix)
│   └── migrate_health/           (health badges, stale detection, failed item tracking)
```

**Parent module (migrate_suite)** contains:
- Shared services for querying `migrate_map_*` and `migrate_message_*` tables
- Event subscriber listening to migrate events (tracks import timestamps, run history)
- A lightweight custom database table (`migrate_suite_run_log`) for run history tracking
- Base route definitions and libraries shared across submodules

**Each submodule** depends only on `migrate_suite` + core `migrate`. No inter-submodule dependencies.

### Competitive Landscape

| Module | Status | What it does | What it lacks |
|---|---|---|---|
| **Migrate (core)** | Active | Core migration framework | No admin UI beyond a basic list page |
| **Migrate Tools** | Active (~62K installs) | Drush commands + minimal web UI at `/admin/structure/migrate` | No dashboard, no per-migration permissions, no provenance, no health monitoring |
| **Migrate Plus** | Active (~61K installs) | Source/process plugins, config entities | Developer-only API, no admin/monitoring features |
| **Migrate UI** (contrib) | **Abandoned** (2017) | Was a migration CRUD UI | Dead. Not compatible with Drupal 10/11 |
| **Migrate Scheduler** | Unstable (RC) | Cron-based migration scheduling | No UI, single maintainer, no stable release |
| **Migrate Status** | Seeking maintainer | API to check if migration is running | No UI, effectively orphaned |

No existing module provides a migration dashboard, per-migration permissions, content provenance tracking, or health monitoring for Drupal 10.4+/11.x.

## Goals

- Provide a visual migration dashboard where non-developer admins can see migration status at a glance
- Enable granular per-migration access control so content leads can view "their" migrations without full admin access
- Surface migration metadata (provenance) on migrated entities so editors can trace content origin
- Detect unhealthy migrations (stale, failing) and surface them proactively
- Build on existing `migrate_map_*` tables — no redundant data storage
- Keep submodules independently installable so sites only enable what they need

## User Stories

### US-001: Set up shared services in migrate_suite parent module
**Priority:** 1
**Description:** As a developer, I need shared services in the parent module so that all submodules can query migration map/message tables and access run history without duplicating code.

**Acceptance Criteria:**
- [ ] `migrate_suite.info.yml` declares the module with dependency on core `migrate`
- [ ] `MigrateMapQuery` service exists that can: look up destination IDs by source ID, list all imported items for a migration, count items by status (imported/failed)
- [ ] `MigrateMessageQuery` service exists that can: fetch messages for a specific migration, fetch messages for a specific source ID within a migration
- [ ] Database schema defines `migrate_suite_run_log` table with columns: `id`, `migration_id`, `status` (idle/running/completed/failed), `started`, `finished`, `items_processed`, `items_created`, `items_updated`, `items_failed`
- [ ] Event subscriber listens to `MigrateEvents::PRE_IMPORT`, `POST_IMPORT`, `PRE_ROW_SAVE`, `POST_ROW_SAVE` and logs run data to `migrate_suite_run_log`
- [ ] All services are registered in `migrate_suite.services.yml`
- [ ] Module installs and uninstalls cleanly (schema install/uninstall hooks work)
- [ ] PHPStan/phpcs passes at Drupal coding standards

### US-002: Migration overview dashboard page
**Priority:** 2
**Description:** As a site administrator, I want a dashboard listing all migrations with their current status so that I can monitor migration progress without using Drush.

**Acceptance Criteria:**
- [ ] Route at `/admin/structure/migrate-suite` with title "Migration Dashboard"
- [ ] Page lists all migration plugin instances with columns: label, migration group, status (idle/importing/rolling back/completed/failed), total source count, imported count, failed count, last run timestamp
- [ ] Status is displayed with a colored badge: green (completed/idle), blue (importing), red (failed), yellow (rolling back)
- [ ] Migrations are grouped by migration group with collapsible sections
- [ ] Filter form at top: text search (migration ID/label), status dropdown, migration group dropdown
- [ ] Each migration row links to a detail page (US-003)
- [ ] Page uses Drupal's admin theme and standard table formatting
- [ ] Empty state message shown when no migrations exist
- [ ] Page permission: `view migrate suite dashboard` (provided by migrate_admin)

### US-003: Migration detail page with imported items
**Priority:** 3
**Description:** As a content lead, I want to see all entities imported by a specific migration so that I can verify content was migrated correctly.

**Acceptance Criteria:**
- [ ] Route at `/admin/structure/migrate-suite/{migration_id}` showing migration detail
- [ ] Summary section: migration label, group, source plugin, destination plugin, status, last run stats (from `migrate_suite_run_log`)
- [ ] Imported items table sourced from `migrate_map_{migration_id}` with columns: source ID(s), destination entity (linked to entity view), status (`source_row_status`: imported/needs update), last import timestamp (from map table `last_imported` column)
- [ ] Pager: 50 items per page
- [ ] Filter: text search on source ID, status dropdown (imported/needs update/failed)
- [ ] Migration messages tab showing messages from `migrate_message_{migration_id}` with columns: source ID(s), severity (icon), message text
- [ ] Messages filterable by severity (notice/warning/error)
- [ ] Uses same permission as dashboard: `view migrate suite dashboard`

### US-004: Per-migration view permissions
**Priority:** 4
**Description:** As a site administrator, I want to control which roles can view specific migrations so that content leads only see migrations relevant to them.

**Acceptance Criteria:**
- [ ] `migrate_permissions.info.yml` declares the submodule with dependency on `migrate_suite`
- [ ] Module dynamically generates permissions in a permission callback: `view migration {migration_id}` for each migration plugin instance
- [ ] Permissions appear in Drupal's standard permissions page (`/admin/people/permissions`) under a "Migrate Suite" group
- [ ] Dashboard (US-002) filters the migration list based on the user's per-migration view permissions
- [ ] Detail page (US-003) checks per-migration view permission before displaying
- [ ] A user with `view migrate suite dashboard` permission but without any per-migration permissions sees an empty dashboard with a help message
- [ ] A user with `administer migrations` (from migrate_tools) or `administer site configuration` bypasses per-migration checks
- [ ] Permission names are derived from the migration plugin ID (machine-safe)

### US-005: Per-migration run permissions
**Priority:** 5
**Description:** As a site administrator, I want to control which roles can execute or rollback specific migrations so that only authorized users can trigger imports.

**Acceptance Criteria:**
- [ ] Dynamic permissions generated: `run migration {migration_id}` and `rollback migration {migration_id}` for each migration
- [ ] Dashboard row shows "Run" and "Rollback" action buttons only if user has the corresponding permission
- [ ] Run action triggers a batch import of the migration and redirects to a batch progress page
- [ ] Rollback action triggers a batch rollback and redirects to a batch progress page
- [ ] Confirmation form shown before run/rollback with migration label and item count
- [ ] `administer migrations` permission bypasses per-migration run/rollback checks
- [ ] Actions respect migration dependencies (warn if dependent migrations haven't run)

### US-006: Permission matrix UI
**Priority:** 6
**Description:** As a site administrator, I want a matrix view of migration permissions so that I can quickly see and configure which roles have access to which migrations.

**Acceptance Criteria:**
- [ ] Route at `/admin/structure/migrate-suite/permissions` with title "Migration Permissions"
- [ ] Matrix table: rows = migrations (grouped by migration group), columns = roles
- [ ] Each cell shows checkboxes for: view, run, rollback
- [ ] Form saves permissions via Drupal's `user_role_grant_permissions` / `user_role_revoke_permissions` API
- [ ] Matrix is filterable by migration group
- [ ] Requires `administer permissions` permission to access
- [ ] Changes are logged to watchdog

### US-007: Migration health indicators
**Priority:** 7
**Description:** As a site administrator, I want to see at-a-glance health badges on each migration so that I can identify problems quickly.

**Acceptance Criteria:**
- [ ] `migrate_health.info.yml` declares the submodule with dependency on `migrate_suite`
- [ ] Each migration on the dashboard shows a health badge: healthy (green), stale (yellow), failing (red)
- [ ] **Healthy:** migration has run within configured threshold AND failure rate is below configured threshold
- [ ] **Stale:** migration has not run within the configured "stale after X days" threshold (default: 7 days)
- [ ] **Failing:** migration's failure rate (failed items / total items) exceeds configured threshold (default: 5%)
- [ ] Health thresholds configurable via settings form at `/admin/structure/migrate-suite/settings`
- [ ] Settings form fields: stale threshold (days, default 7), failure rate threshold (percentage, default 5)
- [ ] Health is computed on dashboard load from `migrate_suite_run_log` and map table data (no separate cron task in MVP)
- [ ] Aggregate health summary at top of dashboard: "X healthy, Y stale, Z failing"

### US-008: Failed item tracking
**Priority:** 8
**Description:** As a site administrator, I want to see which items failed to import with their error details so that I can take corrective action.

**Acceptance Criteria:**
- [ ] Migration detail page (US-003) shows a "Failed Items" tab
- [ ] Tab lists items from `migrate_map_{migration_id}` where `source_row_status = 2` (MigrateIdMapInterface::STATUS_FAILED)
- [ ] Columns: source ID(s), error message (from `migrate_message_{migration_id}`), last attempt timestamp
- [ ] Failed item count shown as a badge on the tab header
- [ ] Filter by source ID text search
- [ ] Pager: 50 items per page
- [ ] "Reset status" bulk action to mark selected failed items for retry (sets `source_row_status` back to 0)
- [ ] Reset action requires `run migration {migration_id}` permission (from migrate_permissions, or `administer migrations` if migrate_permissions is not installed)

### US-009: Content provenance pseudo-field on migrated entities
**Priority:** 9
**Description:** As a content editor, I want to see migration provenance information on entity edit/view pages so that I can tell where content came from and when it was imported.

**Acceptance Criteria:**
- [ ] `migrate_source_field.info.yml` declares the submodule with dependency on `migrate_suite`
- [ ] Module implements `hook_entity_extra_field_info()` to register a "Migration provenance" pseudo-field on node entities
- [ ] Pseudo-field is configurable per content type via "Manage display" (can be shown/hidden, reordered)
- [ ] When displayed, shows a fieldset with: migration label (linked to detail page if user has permission), import date (from map table `last_imported`), source ID(s)
- [ ] If entity is not found in any `migrate_map_*` table, pseudo-field is hidden (no empty fieldset)
- [ ] Provenance data is computed at render time by querying map tables (not stored redundantly)
- [ ] Results are cached per entity with appropriate cache tags (invalidated on migration run)
- [ ] Works on both entity canonical view and edit form

### US-010: Original source link field
**Priority:** 10
**Description:** As a content editor, I want a link back to the original content on the source platform so that I can compare migrated content with the original.

**Acceptance Criteria:**
- [ ] Configurable source URL pattern per migration via settings form, supporting a `[source_id]` token (e.g., `https://old-site.com/node/[source_id]`)
- [ ] Settings stored in `migrate_suite.settings` config, keyed by migration ID
- [ ] Source link displayed within the provenance pseudo-field (US-009) as a clickable URL
- [ ] Link opens in a new tab (`target="_blank"` with `rel="noopener"`)
- [ ] If no URL pattern is configured for a migration, the source link row is omitted (not shown as empty)
- [ ] URL pattern configuration form accessible at `/admin/structure/migrate-suite/settings/source-links`
- [ ] Form lists all migrations with a text field for each URL pattern
- [ ] Token replacement handles multiple source IDs (composite keys) by joining with `/`

## Functional Requirements

- FR-1: The parent module `migrate_suite` must provide a `MigrateMapQuery` service that queries `migrate_map_*` tables to look up destination IDs, list imported items, and count items by status
- FR-2: The parent module must provide a `MigrateMessageQuery` service that queries `migrate_message_*` tables to fetch messages per migration or per source ID
- FR-3: The parent module must track migration run history in a `migrate_suite_run_log` table via an event subscriber on migrate events
- FR-4: `migrate_admin` must provide a dashboard page at `/admin/structure/migrate-suite` listing all migrations with status, counts, and last run time
- FR-5: `migrate_admin` must provide a detail page per migration at `/admin/structure/migrate-suite/{migration_id}` showing imported items sourced from map tables
- FR-6: `migrate_admin` must provide run and rollback actions on the dashboard using Drupal's Batch API
- FR-7: `migrate_permissions` must dynamically generate `view migration {id}`, `run migration {id}`, and `rollback migration {id}` permissions for each migration
- FR-8: `migrate_permissions` must provide a permission matrix UI at `/admin/structure/migrate-suite/permissions`
- FR-9: Dashboard and detail pages must respect per-migration permissions when `migrate_permissions` is enabled, and fall back to generic `view migrate suite dashboard` permission when it is not
- FR-10: `migrate_health` must compute health badges (healthy/stale/failing) based on configurable thresholds
- FR-11: `migrate_health` must display an aggregate health summary on the dashboard
- FR-12: `migrate_health` must track and display failed items with error messages from the message table
- FR-13: `migrate_source_field` must register a pseudo-field via `hook_entity_extra_field_info()` that displays provenance data on migrated entities
- FR-14: `migrate_source_field` must support configurable source URL patterns per migration with `[source_id]` token replacement
- FR-15: All submodules must be independently installable, depending only on `migrate_suite` and core `migrate`
- FR-16: All modules must be compatible with Drupal 10.4+ and 11.x
- FR-17: All admin routes must use the admin theme and follow Drupal UX conventions (tables, pagers, filter forms, batch operations)

## Non-Goals (Out of Scope)

- **Re-sync / re-import button on entities** — Phase 2 feature, not in MVP
- **Diff/summary after re-sync** — Phase 2
- **Re-run scheduling and cron-based execution** — Phase 3
- **Rollback visibility / dry-run preview** — Phase 3
- **Partial rollback of specific items** — Phase 4
- **Views integration for provenance data** — Phase 4 (note: the pseudo-field itself is Phase 1, but dedicated Views field/filter/sort plugins are Phase 4)
- **Bulk QA tools** — Phase 4
- **Delta detection for smart re-runs** — Phase 4
- **Real-time progress monitoring** (websocket/AJAX polling during import) — Not planned for any phase
- **Migration creation or editing UI** — This module manages/monitors existing migrations, it does not create them
- **Drush commands** — migrate_tools already provides these; Migrate Suite focuses on the web UI
- **Support for non-node entities in provenance field** — MVP targets nodes only; other entity types in a later phase

## Technical Considerations

### Dependencies
- **Hard dependency:** Drupal core `migrate` module
- **Optional integration:** `migrate_tools` (for enhanced Drush commands; not required)
- **No contrib dependencies** beyond core

### Architecture Decisions
- **Querying `migrate_map_*` tables directly:** These tables are created per-migration by the Migrate framework. The table name follows the pattern `migrate_map_{migration_id}`. The service must discover and query these dynamically. Use `MigrateIdMapInterface` where possible rather than raw SQL.
- **Pseudo-fields over stored fields:** Provenance data is computed from map/message tables at render time, not duplicated into entity fields. This avoids data sync issues and works retroactively for already-migrated content.
- **Dynamic permissions:** Use a permission callback (`YourModule.permissions.yml` with a `permission_callbacks` entry) to generate permissions from the migration plugin manager's definitions.
- **Graceful degradation when submodules are absent:** The dashboard must work without `migrate_permissions` (no per-migration filtering) and without `migrate_health` (no health badges). Use `\Drupal::moduleHandler()->moduleExists()` or service injection with `@?` optional dependencies.
- **Cache strategy for provenance field:** Cache provenance data per entity using `cache_tags` tied to the migration ID. Invalidate on migrate events via the event subscriber.

### Database
- Custom table: `migrate_suite_run_log` (defined in `migrate_suite.install`)
- All other data is read from existing `migrate_map_*` and `migrate_message_*` tables

### Performance
- Dashboard queries migration plugin definitions (lightweight) plus `migrate_suite_run_log` (indexed by migration_id)
- Imported items list queries map tables with pagination (LIMIT/OFFSET) — these can be large but pagination limits impact
- Provenance pseudo-field: single map table lookup per entity, cached

### Coding Standards
- Follow Drupal coding standards (phpcs with `Drupal` and `DrupalPractice` sniffs)
- PHPStan level 6 minimum
- Automated tests: at minimum, kernel tests for services and functional tests for admin pages

## Success Metrics

- Site administrators can view migration status for all migrations in under 3 clicks from the admin menu
- Content leads with view-only permissions can see imported items for their migrations but cannot trigger runs or rollbacks
- Health badges correctly identify stale migrations (not run within threshold) and failing migrations (above failure rate threshold)
- Provenance field displays correct import date and source ID on migrated node view/edit pages
- All four submodules install and uninstall independently without errors
- Dashboard page loads in under 2 seconds for sites with up to 50 migrations

## Open Questions

1. Should provenance data survive migration rollback + re-import? (Likely yes — the map table is rebuilt, so provenance auto-updates)
2. How to handle entities created by multiple migrations (e.g., node via one migration, media via another)? Show all migrations in provenance, or just the primary one?
3. Performance of querying `migrate_map_*` tables on entity load for the provenance field — is a single indexed lookup fast enough, or should we use a dedicated cache bin?
4. Should the permission matrix UI support filtering by role as well as by migration group?
5. How should health thresholds behave for migrations that are intentionally one-time (never expected to re-run)? Should there be a "one-time migration" flag that disables stale detection?
6. Should the dashboard auto-refresh (AJAX polling) or is manual refresh sufficient for MVP?
7. The `migrate_map_*` table name is derived from the migration ID but may be truncated for long IDs — should we handle this edge case explicitly?
