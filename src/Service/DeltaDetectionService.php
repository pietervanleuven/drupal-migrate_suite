<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Service;

use Drupal\Core\Database\Connection;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;

/**
 * Detects changes in migration source data between runs.
 */
class DeltaDetectionService {

  public function __construct(
    protected readonly Connection $database,
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
  ) {}

  /**
   * Computes a SHA-256 fingerprint of source row IDs.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration plugin instance.
   *
   * @return string|null
   *   A hash string, or NULL if source cannot be iterated.
   */
  public function computeSourceFingerprint(MigrationInterface $migration): ?string {
    try {
      $source = $migration->getSourcePlugin();
      $source->rewind();

      $ids = [];
      while ($source->valid()) {
        $row = $source->current();
        $ids[] = implode('|', $row->getSourceIdValues());
        $source->next();
      }

      sort($ids);
      return hash('sha256', implode("\n", $ids));
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the source item count for a migration.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration plugin instance.
   *
   * @return int|null
   *   The count, or NULL if unavailable.
   */
  public function getSourceCount(MigrationInterface $migration): ?int {
    try {
      $count = $migration->getSourcePlugin()->count();
      return $count === -1 ? NULL : $count;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the fingerprint from the last completed import run.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array|null
   *   Array with 'source_count' and 'source_hash', or NULL if no prior run.
   */
  public function getLastRunFingerprint(string $migrationId): ?array {
    $row = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r', ['source_count', 'source_hash'])
      ->condition('migration_id', $migrationId)
      ->condition('operation', 'import')
      ->condition('status', 'completed')
      ->isNotNull('source_hash')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Detects whether source data has changed since the last run.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array
   *   Array with keys: 'has_changes' (bool), 'previous_count' (?int),
   *   'current_count' (?int), 'previous_hash' (?string),
   *   'current_hash' (?string).
   */
  public function detectDelta(string $migrationId): array {
    $lastRun = $this->getLastRunFingerprint($migrationId);

    try {
      $migrations = $this->migrationPluginManager->createInstances([$migrationId]);
      $migration = $migrations[$migrationId] ?? NULL;
    }
    catch (\Exception $e) {
      $migration = NULL;
    }

    $currentCount = $migration ? $this->getSourceCount($migration) : NULL;
    $currentHash = $migration ? $this->computeSourceFingerprint($migration) : NULL;

    $previousCount = $lastRun ? (isset($lastRun['source_count']) ? (int) $lastRun['source_count'] : NULL) : NULL;
    $previousHash = $lastRun['source_hash'] ?? NULL;

    $hasChanges = TRUE;
    if ($currentHash !== NULL && $previousHash !== NULL) {
      $hasChanges = $currentHash !== $previousHash;
    }

    return [
      'has_changes' => $hasChanges,
      'previous_count' => $previousCount,
      'current_count' => $currentCount,
      'previous_hash' => $previousHash,
      'current_hash' => $currentHash,
    ];
  }

}
