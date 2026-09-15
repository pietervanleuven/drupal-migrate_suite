<?php

declare(strict_types=1);

namespace Drupal\migrate_source_field\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;

/**
 * Service for looking up migration provenance data for entities.
 */
class ProvenanceLookup {

  /**
   * The cache tag applied to every cached provenance lookup.
   */
  protected const CACHE_TAG = 'migrate_source_field:provenance';

  /**
   * Constructs a ProvenanceLookup object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The table name resolver.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache bin, used to cache provenance lookups so they do not
   *   re-instantiate every migration plugin and re-query the map tables on
   *   every entity view and edit.
   */
  public function __construct(
    protected Connection $database,
    protected MigrationPluginManagerInterface $migrationPluginManager,
    protected MigrateTableNameResolver $tableNameResolver,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Looks up provenance data for an entity.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g., 'node').
   * @param string|int $entityId
   *   The entity ID.
   *
   * @return array|null
   *   An associative array with keys 'migration_id', 'migration_label',
   *   'source_ids', and 'last_imported', or NULL if not found.
   */
  public function lookupProvenance(string $entityTypeId, string|int $entityId): ?array {
    $cid = 'migrate_source_field:provenance:' . $entityTypeId . ':' . $entityId;

    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $provenance = $this->doLookupProvenance($entityTypeId, $entityId);

    $tags = Cache::mergeTags([static::CACHE_TAG], [$entityTypeId . ':' . $entityId]);
    $this->cache->set($cid, $provenance, Cache::PERMANENT, $tags);

    return $provenance;
  }

  /**
   * Performs the uncached provenance lookup for an entity.
   *
   * @param string $entityTypeId
   *   The entity type ID (e.g., 'node').
   * @param string|int $entityId
   *   The entity ID.
   *
   * @return array|null
   *   An associative array with keys 'migration_id', 'migration_label',
   *   'source_ids', and 'last_imported', or NULL if not found.
   */
  protected function doLookupProvenance(string $entityTypeId, string|int $entityId): ?array {
    $migrations = $this->migrationPluginManager->createInstances([]);

    foreach ($migrations as $migrationId => $migration) {
      $definition = $migration->getPluginDefinition();
      $destPlugin = $definition['destination']['plugin'] ?? '';

      // Only check migrations that target this entity type.
      if ($destPlugin !== 'entity:' . $entityTypeId) {
        continue;
      }

      $table = $this->tableNameResolver->getMapTableName($migrationId);
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }

      // Query for a row where destid1 matches the entity ID.
      if (!$this->database->schema()->fieldExists($table, 'destid1')) {
        continue;
      }

      try {
        $row = $this->database->select($table, 'map')
          ->fields('map')
          ->condition('destid1', (string) $entityId)
          ->range(0, 1)
          ->execute()
          ->fetchAssoc();
      }
      catch (\Exception $e) {
        continue;
      }

      if (!$row) {
        continue;
      }

      // Extract source IDs.
      $sourceIds = [];
      for ($i = 1; $i <= 9; $i++) {
        $col = 'sourceid' . $i;
        if (isset($row[$col]) && $row[$col] !== NULL) {
          $sourceIds[] = $row[$col];
        }
      }

      return [
        'migration_id' => $migrationId,
        'migration_label' => $migration->label() ?: $migrationId,
        'source_ids' => $sourceIds,
        'last_imported' => !empty($row['last_imported']) ? (int) $row['last_imported'] : NULL,
      ];
    }

    return NULL;
  }

}
