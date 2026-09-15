<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Service;

use Drupal\Core\Database\Connection;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;

/**
 * Service for querying migration map tables.
 */
class MigrateMapQuery {

  /**
   * Constructs a MigrateMapQuery object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migrate table name resolver.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly MigrateTableNameResolver $tableNameResolver,
  ) {}

  /**
   * Gets the map table name for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The map table name.
   */
  protected function getMapTableName(string $migrationId): string {
    return $this->tableNameResolver->getMapTableName($migrationId);
  }

  /**
   * Looks up destination IDs by source ID.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param array $sourceIdValues
   *   The source ID values keyed by source ID field name.
   *
   * @return array
   *   The destination ID values, or an empty array if not found.
   */
  public function lookupDestinationIds(string $migrationId, array $sourceIdValues): array {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $query = $this->database->select($table, 'map')
      ->fields('map');

    $index = 1;
    foreach ($sourceIdValues as $value) {
      $query->condition('sourceid' . $index, $value);
      $index++;
    }

    $row = $query->execute()->fetchAssoc();

    if (!$row) {
      return [];
    }

    $destIds = [];
    foreach ($row as $key => $value) {
      if (str_starts_with($key, 'destid')) {
        $destIds[$key] = $value;
      }
    }

    return $destIds;
  }

  /**
   * Lists all imported items for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param int $limit
   *   The number of items to return.
   * @param int $offset
   *   The offset for pagination.
   *
   * @return array
   *   An array of map table rows.
   */
  public function listImportedItems(string $migrationId, int $limit = 50, int $offset = 0): array {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    return $this->database->select($table, 'map')
      ->fields('map')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Counts imported items for a migration (status = 0).
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return int
   *   The imported item count.
   */
  public function countImportedItems(string $migrationId): int {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return 0;
    }

    return (int) $this->database->select($table, 'map')
      ->condition('source_row_status', MigrateIdMapInterface::STATUS_IMPORTED)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Lists items that would be rolled back (imported status).
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param int $limit
   *   The number of items to return.
   * @param int $offset
   *   The offset for pagination.
   *
   * @return array
   *   An array of map table rows with imported status.
   */
  public function listRollbackPreview(string $migrationId, int $limit = 50, int $offset = 0): array {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    return $this->database->select($table, 'map')
      ->fields('map')
      ->condition('source_row_status', MigrateIdMapInterface::STATUS_IMPORTED)
      ->range($offset, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Counts items by status for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array
   *   An associative array with keys 'imported', 'needs_update', 'failed'
   *   and their respective counts.
   */
  public function countItemsByStatus(string $migrationId): array {
    $table = $this->getMapTableName($migrationId);

    $counts = [
      'imported' => 0,
      'needs_update' => 0,
      'failed' => 0,
    ];

    if (!$this->database->schema()->tableExists($table)) {
      return $counts;
    }

    $results = $this->database->select($table, 'map')
      ->fields('map', ['source_row_status'])
      ->groupBy('source_row_status')
      ->addExpression('COUNT(*)', 'count')
      ->execute()
      ->fetchAllKeyed();

    $statusMap = [
      (string) MigrateIdMapInterface::STATUS_IMPORTED => 'imported',
      (string) MigrateIdMapInterface::STATUS_NEEDS_UPDATE => 'needs_update',
      (string) MigrateIdMapInterface::STATUS_FAILED => 'failed',
    ];

    foreach ($results as $status => $count) {
      $key = $statusMap[(string) $status] ?? NULL;
      if ($key !== NULL) {
        $counts[$key] = (int) $count;
      }
    }

    return $counts;
  }

  /**
   * Fetches specific map rows by their source IDs.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param array $sourceIdSets
   *   Array of source ID value arrays.
   *
   * @return array
   *   Array of map table row objects.
   */
  public function getMapRowsBySourceIds(string $migrationId, array $sourceIdSets): array {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table) || empty($sourceIdSets)) {
      return [];
    }

    $rows = [];
    foreach ($sourceIdSets as $sourceIds) {
      $query = $this->database->select($table, 'map')
        ->fields('map');

      foreach ($sourceIds as $index => $value) {
        $query->condition('sourceid' . ($index + 1), $value);
      }

      $row = $query->execute()->fetchObject();
      if ($row) {
        $rows[] = $row;
      }
    }

    return $rows;
  }

  /**
   * Deletes specific rows from the map table by source IDs.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param array $sourceIdSets
   *   Array of source ID value arrays.
   *
   * @return int
   *   The number of rows deleted.
   */
  public function deleteMapRows(string $migrationId, array $sourceIdSets): int {
    $table = $this->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($table) || empty($sourceIdSets)) {
      return 0;
    }

    $deleted = 0;
    foreach ($sourceIdSets as $sourceIds) {
      $query = $this->database->delete($table);

      foreach ($sourceIds as $index => $value) {
        $query->condition('sourceid' . ($index + 1), $value);
      }

      $deleted += $query->execute();
    }

    return $deleted;
  }

}
