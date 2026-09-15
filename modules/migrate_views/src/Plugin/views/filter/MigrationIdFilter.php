<?php

declare(strict_types=1);

namespace Drupal\migrate_views\Plugin\views\filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter by migration plugin ID with a dropdown of known migrations.
 *
 * @ViewsFilter("migrate_views_migration_id")
 */
class MigrationIdFilter extends InOperator implements ContainerFactoryPluginInterface {

  /**
   * The migration plugin manager.
   */
  protected MigrationPluginManagerInterface $migrationPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->migrationPluginManager = $container->get('plugin.manager.migration');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions(): ?array {
    if (!isset($this->valueOptions)) {
      $this->valueOptions = [];

      try {
        $migrations = $this->migrationPluginManager->createInstances([]);

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
