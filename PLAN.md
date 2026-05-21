# Migrate Admin

**Machine name:** `migrate_admin`
**Category:** Migration Management & Monitoring
**Drupal compatibility:** 10.4+ / 11.x

## Problem

Once migrations are set up and running, there is no editor-friendly way to monitor their status, inspect migrated content, or control access per migration. The Migrate module provides Drush commands and a minimal admin page, but site managers, content leads, and non-developer admins are left in the dark.

Editors working with migrated content have no way to tell where a node came from, when it was imported, or how to find the original source — making content verification and quality assurance after migration painful.

## Solution

An administration dashboard for migrations with granular permissions, plus content-level provenance tracking that surfaces migration metadata to editors.

### Core Features

1. **Migration Overview Dashboard**
   - List all migrations with status (idle, running, completed, failed)
   - Per-migration stats: total items, imported, skipped, failed, last run timestamp
   - Filter/search by migration group, source plugin, status
   - Quick actions: run, rollback, stop, reset (with appropriate permissions)

2. **Granular Permissions**
   - Per-migration permissions: view status, run, rollback, manage
   - Role-based access: give content leads view-only access to "their" migrations
   - Permission matrix UI for easy configuration
   - Integration with Drupal's core permission system

3. **Content Provenance Meta Field**
   - Computed field added to migrated entities showing:
     - **Import date:** when the entity was first migrated
     - **Last update date:** when the entity was last updated by migration
     - **Migration link:** link to the migration that created this entity
     - **Messages link:** link to migration messages/errors for this entity
   - **Re-sync button:** "Update from source" action on the entity edit form
     - Looks up the source ID from `migrate_map_*` and re-runs the migration for that single item
     - Shows a diff/summary of what changed after re-sync
     - Respects per-migration permissions (only visible to users with run access)
   - Displayed as a fieldset on the entity view/edit form
   - Configurable visibility per content type

4. **Imported Items Overview (per migration)**
   - Browsable list of all entities imported by a specific migration
   - Sourced directly from `migrate_map_*` tables — no extra storage needed
   - Columns: destination entity (linked), source ID, status (imported/needs update/failed), last import timestamp
   - Bulk actions: re-sync selected, rollback selected
   - Filter by status, search by source ID or destination title
   - Accessible from the migration dashboard as a drill-down

5. **Original Source Link Field**
   - Link field pointing back to the content on the original platform
   - Supports migration tokens for dynamic URL construction (e.g., `https://old-site.com/node/[migrate:source_id]`)
   - Available in:
     - Views (as a field and filter)
     - Entity detail pages
     - Content admin listings
   - Enables side-by-side comparison of source vs. migrated content

6. **Migration Health & Status Indicators**
   - Per-migration health badge: healthy (green), stale (yellow), failing (red)
   - **Stale detection:** flag migrations where source has changed but hasn't been re-run (configurable threshold)
   - **Failed item tracking:** count and list entities that failed to import/update, with error details
   - **Last run indicator:** time since last execution with warning when overdue
   - Aggregate health dashboard: at-a-glance site-wide migration health

7. **Rollback Visibility**
   - Per-migration rollback status: show what would be affected (entity count, types)
   - Dry-run rollback preview: list entities that would be deleted without executing
   - Rollback history log: who rolled back what and when
   - Partial rollback support: rollback specific items, not just entire migrations

8. **Re-run Scheduling**
   - Schedule migrations to re-run on a cron-based interval (hourly, daily, weekly, custom)
   - Per-migration schedule configuration via the dashboard UI
   - Dependency-aware scheduling: run migrations in correct order (e.g., taxonomy before nodes)
   - Run conditions: only re-run if source has changed (delta detection)
   - Execution log: history of scheduled runs with duration, item counts, and outcomes
   - Manual override: trigger immediate re-run from the dashboard

9. **Migration Messages & Logs**
   - Per-entity message viewer (not just per-migration)
   - Filter messages by severity (notice, warning, error)
   - Link from entity edit form directly to its migration messages
   - Bulk message review UI for post-migration QA

### Architecture

- **Leverages `migrate_map_*` tables:** Reads existing migration tracking data to connect entities to their source migrations — also powers the per-migration imported items overview and single-item re-sync
- **Single-item re-import:** Uses `MigrateExecutable` with an `idlist` option to re-run a migration for one source ID, same mechanism as `drush migrate:import --idlist`
- **Computed fields:** Provenance metadata is computed from migration map/message tables, not duplicated
- **Extra fields / pseudo fields:** Migration metadata displayed via hook_entity_extra_field_info, not stored redundantly
- **Views integration:** Provides Views field/filter/sort plugins for all provenance data
- **Event subscriber:** Listens to migrate events to track import/update timestamps, run history, and health status in a lightweight custom table
- **Cron-based scheduler:** Custom QueueWorker plugin that checks scheduled migrations and enqueues them respecting dependency order
- **Health computation:** Periodic cron task compares source counts vs. map table counts, checks last-run timestamps against configured thresholds, and aggregates failed item counts
- **Rollback service:** Wraps Migrate API's rollback with dry-run capability (count affected entities without executing) and audit logging

### Configuration

- Enable/disable provenance field per content type
- Configure original source URL pattern per migration (with token support)
- Permission assignment per migration per role
- Dashboard: configure visible columns, default filters
- Messages retention period
- Health thresholds: stale after X days without re-run, failure rate warning threshold (%)
- Schedule: per-migration cron interval and run conditions
- Rollback: require confirmation, enable/disable dry-run preview

### Dependencies

- Drupal Core: `migrate`
- Contrib: `migrate_tools` (optional, for enhanced Drush integration)
- No hard contrib dependencies beyond core Migrate

### Target Users

- Content managers overseeing post-migration QA
- Site administrators monitoring migration health
- Multi-team organizations needing per-migration access control
- Agencies handing off migrated sites to clients

## Competitive Landscape

- **Migrate (core):** No admin UI beyond a basic list page
- **Migrate Tools:** Adds Drush commands and a slightly better UI, but no permissions, no provenance tracking
- **Migrate Plus:** Source plugins and process plugins, no admin/monitoring features
- **Migration UI (our MIGRATION-UI.md):** Focuses on *running* new migrations — Migrate Admin focuses on *managing and monitoring* them afterward. They are complementary.

## MVP Scope

Phase 1: Migration dashboard with status overview + imported items overview per migration + per-migration view/run permissions + health indicators
Phase 2: Content provenance meta field + single-item re-sync button + original source link with token support
Phase 3: Rollback visibility (dry-run preview) + re-run scheduling with dependency ordering + per-entity message viewer
Phase 4: Views integration for all provenance fields, bulk QA tools, partial rollback, delta detection for smart re-runs

## Open Questions

- Should provenance data survive migration rollback + re-import (likely yes, with updated timestamps)?
- How to handle entities created by multiple migrations (e.g., node via one migration, its media via another)?
- Should the source link field be a base field or a bundle field configurable per content type?
- Performance implications of querying `migrate_map_*` tables on entity load — caching strategy?
- How to detect source changes for delta re-runs — hash comparison, timestamp, or source-plugin-specific?
- Should scheduled re-runs use Drupal cron or recommend a dedicated cron runner (Ultimate Cron)?
- Partial rollback: how to handle entity references when rolling back only some items?
