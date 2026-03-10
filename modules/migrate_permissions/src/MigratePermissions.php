<?php

declare(strict_types=1);

namespace Drupal\migrate_permissions;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides dynamic per-migration permissions.
 */
class MigratePermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.migration'),
    );
  }

  /**
   * Returns an array of per-migration view permissions.
   *
   * @return array
   *   An array of permission definitions keyed by permission machine name.
   */
  public function permissions(): array {
    $permissions = [];

    try {
      $migrations = $this->migrationPluginManager->createInstances([]);
    }
    catch (\Exception $e) {
      return $permissions;
    }

    foreach ($migrations as $migration_id => $migration) {
      $label = $migration->label() ?: $migration_id;
      $safe_id = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $migration_id));

      $permissions["view migration $safe_id"] = [
        'title' => $this->t('View migration: @label', ['@label' => $label]),
        'description' => $this->t('Allows viewing the @label migration on the Migrate Suite dashboard.', ['@label' => $label]),
      ];

      $permissions["run migration $safe_id"] = [
        'title' => $this->t('Run migration: @label', ['@label' => $label]),
        'description' => $this->t('Allows running the @label migration import.', ['@label' => $label]),
      ];

      $permissions["rollback migration $safe_id"] = [
        'title' => $this->t('Rollback migration: @label', ['@label' => $label]),
        'description' => $this->t('Allows rolling back the @label migration.', ['@label' => $label]),
      ];
    }

    return $permissions;
  }

}
