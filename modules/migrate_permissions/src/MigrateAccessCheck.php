<?php

declare(strict_types=1);

namespace Drupal\migrate_permissions;

use Drupal\Core\Session\AccountInterface;

/**
 * Checks per-migration view access.
 */
class MigrateAccessCheck {

  /**
   * Checks if a user can view a specific migration.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   * @param string $migration_id
   *   The migration plugin ID.
   *
   * @return bool
   *   TRUE if the user can view this migration.
   */
  public function canViewMigration(AccountInterface $account, string $migration_id): bool {
    // Bypass for admin-level permissions.
    if ($account->hasPermission('administer migrations') || $account->hasPermission('administer site configuration')) {
      return TRUE;
    }

    $safe_id = preg_replace('/[^a-z0-9_]/', '_', strtolower($migration_id));
    return $account->hasPermission("view migration $safe_id");
  }

}
