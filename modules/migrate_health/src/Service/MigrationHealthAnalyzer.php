<?php

declare(strict_types=1);

namespace Drupal\migrate_health\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Analyzes migration health based on run history and failure rates.
 */
class MigrationHealthAnalyzer {

  /**
   * Health status constants.
   */
  const HEALTHY = 'healthy';
  const STALE = 'stale';
  const FAILING = 'failing';

  /**
   * Constructs a MigrationHealthAnalyzer object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Gets the health status for a single migration.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return string
   *   One of: healthy, stale, failing.
   */
  public function getHealth(string $migrationId): string {
    $config = $this->configFactory->get('migrate_health.settings');
    $staleThreshold = (int) ($config->get('stale_threshold') ?? 7);
    $failureRateThreshold = (float) ($config->get('failure_rate_threshold') ?? 5);

    // Check failure rate first (higher priority).
    if ($this->isFailingMigration($migrationId, $failureRateThreshold)) {
      return self::FAILING;
    }

    // Check staleness.
    if ($this->isStaleMigration($migrationId, $staleThreshold)) {
      return self::STALE;
    }

    return self::HEALTHY;
  }

  /**
   * Gets health statuses for multiple migrations.
   *
   * @param array $migrationIds
   *   Array of migration IDs.
   *
   * @return array
   *   Associative array keyed by migration ID with health status values.
   */
  public function getHealthForMultiple(array $migrationIds): array {
    $results = [];
    foreach ($migrationIds as $migrationId) {
      $results[$migrationId] = $this->getHealth($migrationId);
    }
    return $results;
  }

  /**
   * Gets an aggregate summary of health across all provided migrations.
   *
   * @param array $healthStatuses
   *   Associative array of migration_id => health status.
   *
   * @return array
   *   Array with keys: healthy, stale, failing (counts).
   */
  public function getAggregateSummary(array $healthStatuses): array {
    $summary = [
      self::HEALTHY => 0,
      self::STALE => 0,
      self::FAILING => 0,
    ];

    foreach ($healthStatuses as $status) {
      if (isset($summary[$status])) {
        $summary[$status]++;
      }
    }

    return $summary;
  }

  /**
   * Checks if a migration's failure rate exceeds the threshold.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param float $threshold
   *   The failure rate threshold as a percentage.
   *
   * @return bool
   *   TRUE if failing.
   */
  protected function isFailingMigration(string $migrationId, float $threshold): bool {
    $table = 'migrate_map_' . $migrationId;
    if (!$this->database->schema()->tableExists($table)) {
      return FALSE;
    }

    $total = (int) $this->database->select($table, 'map')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($total === 0) {
      return FALSE;
    }

    // Status 2 = MigrateIdMapInterface::STATUS_FAILED.
    $failed = (int) $this->database->select($table, 'map')
      ->condition('source_row_status', 2)
      ->countQuery()
      ->execute()
      ->fetchField();

    $failureRate = ($failed / $total) * 100;
    return $failureRate > $threshold;
  }

  /**
   * Checks if a migration is stale (hasn't run within the threshold).
   *
   * @param string $migrationId
   *   The migration ID.
   * @param int $thresholdDays
   *   Number of days before a migration is considered stale.
   *
   * @return bool
   *   TRUE if stale.
   */
  protected function isStaleMigration(string $migrationId, int $thresholdDays): bool {
    $lastRun = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r', ['started'])
      ->condition('migration_id', $migrationId)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$lastRun) {
      // Never run = stale.
      return TRUE;
    }

    $staleTimestamp = $this->time->getRequestTime() - ($thresholdDays * 86400);
    return (int) $lastRun < $staleTimestamp;
  }

}
