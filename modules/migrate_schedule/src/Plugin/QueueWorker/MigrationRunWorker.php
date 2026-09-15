<?php

declare(strict_types=1);

namespace Drupal\migrate_schedule\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_suite\Service\DeltaDetectionService;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes scheduled migration imports.
 *
 * @QueueWorker(
 *   id = "migrate_schedule_run",
 *   title = @Translation("Scheduled migration runner"),
 *   cron = {"time" = 300}
 * )
 */
class MigrationRunWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly ?DeltaDetectionService $deltaDetection,
    protected readonly MigrateTableNameResolver $tableNameResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.migration'),
      $container->get('migrate_suite.delta_detection'),
      $container->get('migrate_suite.table_name_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $migrationId = $data['migration_id'] ?? NULL;
    $skipIfNoChanges = $data['skip_if_no_changes'] ?? FALSE;

    if ($migrationId === NULL) {
      return;
    }

    // Check delta if configured.
    if ($skipIfNoChanges && $this->deltaDetection !== NULL) {
      $delta = $this->deltaDetection->detectDelta($migrationId);
      if (!$delta['has_changes'] && $delta['current_hash'] !== NULL) {
        \Drupal::logger('migrate_schedule')->info('Skipping scheduled run for @migration: no source changes detected.', [
          '@migration' => $migrationId,
        ]);
        return;
      }
    }

    try {
      $migrations = $this->migrationPluginManager->createInstances([$migrationId]);
      $migration = $migrations[$migrationId] ?? NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('migrate_schedule')->error('Failed to load migration @id: @error', [
        '@id' => $migrationId,
        '@error' => $e->getMessage(),
      ]);
      return;
    }

    if ($migration === NULL) {
      return;
    }

    // Check dependencies — ensure required migrations have imported items.
    $definition = $migration->getPluginDefinition();
    $requirements = $definition['migration_dependencies']['required'] ?? [];
    foreach ($requirements as $requiredId) {
      $mapTable = $this->tableNameResolver->getMapTableName($requiredId);
      if (!\Drupal::database()->schema()->tableExists($mapTable)) {
        \Drupal::logger('migrate_schedule')->warning('Skipping scheduled run for @migration: dependency @dep has not been run.', [
          '@migration' => $migrationId,
          '@dep' => $requiredId,
        ]);
        return;
      }
    }

    $executable = new MigrateExecutable($migration, new MigrateMessage());
    $executable->import();
  }

}
