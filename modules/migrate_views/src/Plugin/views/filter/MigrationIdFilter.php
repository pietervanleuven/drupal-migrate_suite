<?php

declare(strict_types=1);

namespace Drupal\migrate_views\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Filter by migration plugin ID with a dropdown of known migrations.
 *
 * @ViewsFilter("migrate_views_migration_id")
 */
class MigrationIdFilter extends InOperator {

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): ?array {
    if (!isset($this->valueOptions)) {
      $this->valueOptions = [];

      try {
        $migrationPluginManager = \Drupal::service('plugin.manager.migration');
        $migrations = $migrationPluginManager->createInstances([]);

        foreach ($migrations as $id => $migration) {
          $this->valueOptions[$id] = $migration->label() ?: $id;
        }
      }
      catch (\Exception $e) {
        // Fallback to empty if plugin manager fails.
      }

      asort($this->valueOptions);
    }

    return $this->valueOptions;
  }

}
