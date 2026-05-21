## Codebase Patterns
- Submodules with optional cross-module dependencies use conditional service loading in `create()`: check `module_handler->moduleExists()` before getting the service
- Per-migration permission names use `preg_replace('/[^a-z0-9_]/', '_', strtolower($migration_id))` for machine-safe names
- Module follows Drupal 10.4+/11.x coding standards with `declare(strict_types=1)` in PHP classes
- Services use constructor property promotion (`protected readonly`)
- Map tables follow Drupal migrate convention: `migrate_map_{migration_id}`
- Message tables follow: `migrate_message_{migration_id}`
- Source ID columns in map/message tables are `sourceid1`, `sourceid2`, etc. and `src_1`, `src_2` respectively
- Always inject services via constructor rather than using `\Drupal::` static calls
- Services registered in `migrate_suite.services.yml`
- Submodules live in `modules/` directory (e.g., `modules/migrate_admin/`)
- Controllers use `ControllerBase` with `create()` for DI, not services.yml
- Migration group is accessed via `$migration->getPluginDefinition()['migration_group']`
- Use Drupal render arrays (`#type => 'table'`, `#type => 'details'`) for admin UI, not Twig templates
- Status badges use custom CSS classes (`color--success`, `color--error`, etc.)
- Confirmation forms extend `ConfirmFormBase` and live in `src/Form/` directory
- Batch operations use static callbacks with `\Drupal::service()` for DI (must be serializable)
- Migration dependencies are in `$migration->getPluginDefinition()['migration_dependencies']['required']`
- Route-level permission is the broad `view migrate suite dashboard`; granular checks happen in form/controller code
- Settings forms use `ConfigFormBase` with `getEditableConfigNames()` and store in `{module_name}.settings` config
- Health badges use `health-badge` CSS class (separate from status `badge` class) with `health--healthy/stale/failing` modifiers

---

## 2026-03-10 - US-001
- Implemented shared services in migrate_suite parent module
- Files created:
  - `migrate_suite.info.yml` - Module declaration with core migrate dependency
  - `migrate_suite.install` - Schema for `migrate_suite_run_log` table
  - `migrate_suite.module` - Module file
  - `migrate_suite.services.yml` - Service definitions
  - `src/Service/MigrateMapQuery.php` - Query service for migration map tables
  - `src/Service/MigrateMessageQuery.php` - Query service for migration message tables
  - `src/EventSubscriber/MigrateRunLogger.php` - Event subscriber for PRE_IMPORT, POST_IMPORT, PRE_ROW_SAVE, POST_ROW_SAVE
- **Learnings for future iterations:**
  - No phpcs/phpstan available locally; used `php -l` for syntax validation
  - Drupal migrate map tables use `source_row_status` column with MigrateIdMapInterface constants
  - This is a greenfield project - no existing code patterns to follow initially
  - The PRD may add `inProgress` field automatically; handle that when editing
---

## 2026-03-10 - US-002
- Implemented migration overview dashboard page as `migrate_admin` submodule
- Files created:
  - `modules/migrate_admin/migrate_admin.info.yml` - Submodule declaration
  - `modules/migrate_admin/migrate_admin.module` - Module file
  - `modules/migrate_admin/migrate_admin.permissions.yml` - Dashboard permission
  - `modules/migrate_admin/migrate_admin.routing.yml` - Dashboard + detail routes
  - `modules/migrate_admin/migrate_admin.links.menu.yml` - Admin menu link
  - `modules/migrate_admin/migrate_admin.libraries.yml` - CSS library
  - `modules/migrate_admin/css/migrate_admin.css` - Status badge and filter styling
  - `modules/migrate_admin/src/Controller/MigrationDashboardController.php` - Dashboard controller
- Features: migration listing with grouping, collapsible sections, status badges, filter form, empty state, detail page placeholder
- **Learnings for future iterations:**
  - Migration group is in plugin definition, not a direct method on MigrationInterface
  - Use `#type => 'details'` with `#open => TRUE` for collapsible grouped sections
  - Dashboard links to detail route `migrate_admin.migration_detail` with `{migration_id}` parameter
  - Filter form uses plain HTML form (GET method) since we don't need CSRF for read-only filters
  - PRD auto-adds `inProgress` field; remove it when setting `passes: true`
---

## 2026-03-10 - US-003
- Implemented migration detail page with imported items table and messages tab
- Files created:
  - `modules/migrate_admin/src/Controller/MigrationDetailController.php` - New controller for detail and messages pages
- Files modified:
  - `modules/migrate_admin/migrate_admin.routing.yml` - Added messages route, updated detail route to new controller
  - `modules/migrate_admin/src/Controller/MigrationDashboardController.php` - Removed placeholder detail() method
  - `modules/migrate_admin/css/migrate_admin.css` - Added tab navigation and pager styles
- Features: summary section (label, group, source/dest plugins, status, last run stats), imported items table with source IDs, entity links, status, last import timestamp, 50-item pagination, source ID search filter, status dropdown filter, messages tab with severity icons and severity filter
- **Learnings for future iterations:**
  - Detail controller uses `loadMigration()` helper that throws NotFoundHttpException for invalid migration IDs
  - Entity linking works by parsing `entity:{type}` from destination plugin definition
  - Message severity uses RFC 5424 levels: 3=error, 4=warning, 5+=notice
  - Map table columns: `sourceid1..N`, `destid1..N`, `source_row_status`, `last_imported`
  - Message table columns: `src_1..N`, `level`, `message`
  - Use `clone $query` then `countQuery()` for total count before applying `range()` for pagination
  - Tabs are implemented as plain links with CSS styling, not Drupal's local tasks (since both pages share the same permission)
---

## 2026-03-10 - US-004
- Implemented per-migration view permissions as `migrate_permissions` submodule
- Files created:
  - `modules/migrate_permissions/migrate_permissions.info.yml` - Submodule declaration with migrate_suite dependency
  - `modules/migrate_permissions/migrate_permissions.module` - Module file
  - `modules/migrate_permissions/migrate_permissions.permissions.yml` - Dynamic permission callback
  - `modules/migrate_permissions/migrate_permissions.services.yml` - Access check service
  - `modules/migrate_permissions/src/MigratePermissions.php` - Generates `view migration {safe_id}` permissions for each migration
  - `modules/migrate_permissions/src/MigrateAccessCheck.php` - Checks per-migration view access with admin bypass
- Files modified:
  - `modules/migrate_admin/src/Controller/MigrationDashboardController.php` - Filters migrations by per-migration permissions, shows help message for no-permission users
  - `modules/migrate_admin/src/Controller/MigrationDetailController.php` - Checks per-migration permission in loadMigration(), throws AccessDeniedHttpException
- **Learnings for future iterations:**
  - Dynamic permissions use `permission_callbacks` in `.permissions.yml` pointing to a class implementing `ContainerInjectionInterface`
  - Cross-module service dependencies should be optional: check `moduleExists()` in `create()` and pass `NULL` if module not installed
  - `administer migrations` (from migrate_tools) and `administer site configuration` are the bypass permissions
  - Permission machine names use `preg_replace('/[^a-z0-9_]/', '_', strtolower($id))` for safety
---

## 2026-03-10 - US-005
- Implemented per-migration run and rollback permissions with confirmation forms and batch operations
- Files modified:
  - `modules/migrate_permissions/src/MigratePermissions.php` - Added `run migration {safe_id}` and `rollback migration {safe_id}` dynamic permissions
  - `modules/migrate_permissions/src/MigrateAccessCheck.php` - Added `canRunMigration()` and `canRollbackMigration()` methods
  - `modules/migrate_admin/src/Controller/MigrationDashboardController.php` - Added Run/Rollback action links (permission-gated) and Actions column to group tables
  - `modules/migrate_admin/migrate_admin.routing.yml` - Added routes for run/rollback confirmation forms
- Files created:
  - `modules/migrate_admin/src/Form/MigrationRunConfirmForm.php` - Confirmation form with batch import, dependency warnings, source count display
  - `modules/migrate_admin/src/Form/MigrationRollbackConfirmForm.php` - Confirmation form with batch rollback, dependent migration warnings, imported count display
- **Learnings for future iterations:**
  - Confirmation forms extend `ConfirmFormBase` with `getQuestion()`, `getCancelUrl()`, `getDescription()`, `buildForm()`, `submitForm()`
  - Batch operations use `batch_set()` with static callbacks (since batch callbacks must be serializable)
  - Static batch callbacks must use `\Drupal::service()` since they can't use DI
  - Migration dependencies are in `$definition['migration_dependencies']['required']`
  - For rollback dependency checking, iterate all migrations to find which ones depend on the current one
  - Route-level permission is `view migrate suite dashboard` (basic access), form-level code checks granular run/rollback permissions
  - When migrate_permissions module is NOT installed, fall back to checking `administer migrations` / `administer site configuration`
---

## 2026-03-10 - US-006
- Implemented permission matrix UI for managing per-migration permissions across roles
- Files created:
  - `modules/migrate_permissions/migrate_permissions.routing.yml` - Route at /admin/structure/migrate-suite/permissions
  - `modules/migrate_permissions/migrate_permissions.links.menu.yml` - Admin menu link
  - `modules/migrate_permissions/migrate_permissions.libraries.yml` - CSS library
  - `modules/migrate_permissions/css/migrate_permissions.css` - Matrix table styling
  - `modules/migrate_permissions/src/Form/PermissionMatrixForm.php` - Matrix form with checkboxes for view/run/rollback per migration per role
- Features: matrix table (rows=migrations grouped by group, cols=roles with view/run/rollback), group filter, admin role auto-checked+disabled, saves via Role::grantPermission/revokePermission, watchdog logging
- **Learnings for future iterations:**
  - `Role::load()` + `$role->hasPermission()` / `$role->grantPermission()` / `$role->revokePermission()` / `$role->save()` is the Drupal API for permission management
  - Admin roles (`$role->isAdmin()`) have all permissions implicitly - show as disabled checkboxes
  - FormBase with `getUserInput()` is needed for checkbox matrix since unchecked checkboxes don't appear in form values
  - `RoleInterface::ANONYMOUS_ID` should be excluded from the permission matrix
  - Route-level permission for permission management pages is `administer permissions`
---

## 2026-03-10 - US-007
- Implemented migration health indicators as `migrate_health` submodule
- Files created:
  - `modules/migrate_health/migrate_health.info.yml` - Submodule declaration with migrate_suite dependency
  - `modules/migrate_health/migrate_health.module` - Module file
  - `modules/migrate_health/migrate_health.services.yml` - Health analyzer service
  - `modules/migrate_health/migrate_health.routing.yml` - Settings form route at /admin/structure/migrate-suite/settings
  - `modules/migrate_health/migrate_health.links.menu.yml` - Admin menu link
  - `modules/migrate_health/migrate_health.libraries.yml` - CSS library
  - `modules/migrate_health/css/migrate_health.css` - Health badge and summary styling
  - `modules/migrate_health/src/Service/MigrationHealthAnalyzer.php` - Health computation service (healthy/stale/failing)
  - `modules/migrate_health/src/Form/HealthSettingsForm.php` - ConfigFormBase for stale threshold (days) and failure rate threshold (%)
- Files modified:
  - `modules/migrate_admin/src/Controller/MigrationDashboardController.php` - Added optional health analyzer injection, health badge column, aggregate health summary bar
- Features: health badges (green=healthy, yellow=stale, red=failing), configurable thresholds (stale: 7 days default, failure rate: 5% default), aggregate summary at dashboard top, computed on load from run_log and map tables
- **Learnings for future iterations:**
  - ConfigFormBase uses `getEditableConfigNames()` to declare which config objects are editable
  - Config values default via `$config->get('key') ?? default` since config may not exist until first save
  - Health module is optional: dashboard uses `moduleExists('migrate_health')` check in `create()` and passes NULL analyzer if not installed
  - Health badges use separate CSS classes (`health-badge health--healthy`) to avoid conflicts with status badges
  - When adding columns conditionally to tables, pass a flag to `buildGroupTable()` rather than checking inside the method
---

## 2026-03-10 - US-008
- Implemented failed item tracking on the migration detail page
- Files modified:
  - `modules/migrate_admin/migrate_admin.routing.yml` - Added routes for failed items tab and reset confirmation form
  - `modules/migrate_admin/src/Controller/MigrationDetailController.php` - Added `failedItems()` method, `buildTabs()` helper (refactored from inline tab HTML), `getFailedItemCount()`, `buildFailedItemsSection()`, `getErrorMessageForSourceIds()`, `canResetFailedItems()`, `buildFailedItemFilterForm()`
  - `modules/migrate_admin/css/migrate_admin.css` - Added styles for failed tab badge and actions area
- Files created:
  - `modules/migrate_admin/src/Form/FailedItemsResetForm.php` - ConfirmFormBase for resetting all failed items (sets source_row_status back to 0)
- Features: Failed Items tab with badge count, failed items table (source IDs, error message from message table, last attempt timestamp), source ID search filter, 50-item pagination, "Reset all failed items for retry" button (permission-gated), confirmation form before reset
- **Learnings for future iterations:**
  - Refactored tabs into `buildTabs()` helper method to keep tab definitions in one place across detail/messages/failed pages
  - Error messages for failed items are fetched from the message table by matching source IDs (src_1, src_2, etc.) and taking the most recent message (ORDER BY msgid DESC)
  - Reset permission uses `canRunMigration()` from MigrateAccessCheck - reset requires the same permission as running a migration
  - The PRD auto-adds `inProgress: true` field; must remove it when setting `passes: true`
  - Pager route map was extended to handle 'failed' tab alongside 'messages' and default 'items'
---

## 2026-03-10 - US-009

- Implemented content provenance pseudo-field on migrated entities as `migrate_source_field` submodule
- Files created:
  - `modules/migrate_source_field/migrate_source_field.info.yml` - Submodule declaration with migrate_suite dependency
  - `modules/migrate_source_field/migrate_source_field.module` - Hooks for extra field info, node view, and node form alter
  - `modules/migrate_source_field/migrate_source_field.services.yml` - ProvenanceLookup service definition
  - `modules/migrate_source_field/src/Service/ProvenanceLookup.php` - Reverse-lookup service: given entity type + ID, finds which migration imported it
- Files modified:
  - `migrate_suite.services.yml` - Added `@cache_tags.invalidator` argument to run_logger
  - `src/EventSubscriber/MigrateRunLogger.php` - Injects CacheTagsInvalidatorInterface, invalidates `migrate_source_field:provenance` tag on POST_IMPORT
- Features: pseudo-field registered on all node bundles (display + form), configurable via Manage Display, shows migration label (linked to detail page if user has permission), source IDs, import date; hidden if entity not in any map table; cached with `migrate_source_field:provenance` tag invalidated on migration run
- **Learnings for future iterations:**
  - `hook_entity_extra_field_info()` registers pseudo-fields with 'display' and 'form' keys for view and edit form respectively
  - Reverse lookup (dest ID -> source) requires iterating all migrations and checking destination plugin type before querying map tables
  - `hook_form_BASE_FORM_ID_alter()` with `node_form` handles all node edit forms; check `entity_form_display` to see if pseudo-field is enabled
  - Cache tag `migrate_source_field:provenance` is a custom tag; invalidated via `CacheTagsInvalidatorInterface` in the event subscriber
  - The PRD auto-adds `inProgress: true` when a story is being worked on; remove it when setting `passes: true`
  - For form display, load `entity_form_display` via `EntityFormDisplay::load('node.{bundle}.default')` to check component visibility
---

## 2026-03-10 - US-010
- Implemented original source link field with configurable URL patterns per migration
- Files created:
  - `modules/migrate_source_field/src/Form/SourceLinkSettingsForm.php` - ConfigFormBase listing all migrations grouped by group, text field per migration for URL pattern
  - `modules/migrate_source_field/migrate_source_field.routing.yml` - Route at /admin/structure/migrate-suite/settings/source-links
  - `modules/migrate_source_field/migrate_source_field.links.menu.yml` - Admin menu link
- Files modified:
  - `modules/migrate_source_field/migrate_source_field.module` - Added `_migrate_source_field_build_source_link()` helper, integrated into provenance display
- Features: configurable URL patterns with `[source_id]` token, composite key support (joined with /), source link displayed in provenance pseudo-field as clickable URL (target=_blank, rel=noopener), omitted when no pattern configured, settings stored in `migrate_suite.settings` config keyed by migration ID
- **Learnings for future iterations:**
  - Source link settings stored in `migrate_suite.settings` config under `source_links` key (object keyed by migration ID)
  - Migration IDs may contain dots; use `str_replace('.', '__', $id)` for form element names since Drupal form API uses dots as array path separators
  - ConfigFormBase with `getEditableConfigNames()` returning `['migrate_suite.settings']` allows the form to edit the shared config
  - The `[source_id]` token is replaced via simple `str_replace`; composite keys are joined with `/` before replacement
---
