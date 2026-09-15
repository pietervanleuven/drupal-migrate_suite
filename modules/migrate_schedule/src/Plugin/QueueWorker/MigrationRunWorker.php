<?php

declare(strict_types=1);

namespace Drupal\migrate_schedule\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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

  /**
   * Constructs a MigrationRunWorker object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\migrate_suite\Service\DeltaDetectionService|null $deltaDetection
   *   The delta detection service, or NULL if it is unavailable.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migration table name resolver service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected MigrationPluginManagerInterface $migrationPluginManager,
    protected ?DeltaDetectionService $deltaDetection,
    protected MigrateTableNameResolver $tableNameResolver,
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory,
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
      $container->get('database'),
      $container->get('logger.factory'),
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
        $this->loggerFactory->get('migrate_schedule')->info('Skipping scheduled run for @migration: no source changes detected.', [
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
      $this->loggerFactory->get('migrate_schedule')->error('Failed to load migration @id: @error', [
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
      if (!$this->database->schema()->tableExists($mapTable)) {
        $this->loggerFactory->get('migrate_schedule')->warning('Skipping scheduled run for @migration: dependency @dep has not been run.', [
          '@migration' => $migrationId,
          '@dep' => $requiredId,
        ]);
        return;
      }
    }

    $executable = new MigrateExecutable($migration, new MigrateMessage());

    try {
      $executable->import();
    }
    catch (\Throwable $e) {
      // A migration that fails deterministically must not be retried by the
      // queue: an uncaught exception here would make processItem() throw,
      // which causes the queue to release the item and retry it on every
      // subsequent cron run, forever. Log and let the item be consumed.
      $this->loggerFactory->get('migrate_schedule')->error('Scheduled run of @migration failed: @error', [
        '@migration' => $migrationId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
