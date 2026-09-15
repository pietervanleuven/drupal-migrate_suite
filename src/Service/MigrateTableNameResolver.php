<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Service;

use Drupal\Core\Database\Connection;

/**
 * Resolves the map and message table names for a migration ID.
 *
 * Migration IDs cannot be concatenated onto the table prefix directly. Derived
 * migrations carry a colon in their ID ("d7_node:article"), which is not valid
 * in a table name, and core lowercases and truncates the result. Reproducing
 * those rules here keeps every query in this module pointed at the tables the
 * core ID map plugin actually created.
 *
 * @see \Drupal\migrate\Plugin\migrate\id_map\Sql::__construct()
 */
class MigrateTableNameResolver {

  /**
   * Maximum table name length, matching the core SQL ID map plugin.
   */
  protected const MAX_LENGTH = 63;

  /**
   * Constructs a MigrateTableNameResolver object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * Gets the map table name for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The unprefixed map table name.
   */
  public function getMapTableName(string $migrationId): string {
    return $this->buildTableName('migrate_map_', $migrationId);
  }

  /**
   * Gets the message table name for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The unprefixed message table name.
   */
  public function getMessageTableName(string $migrationId): string {
    return $this->buildTableName('migrate_message_', $migrationId);
  }

  /**
   * Builds a table name from a base prefix and a migration ID.
   *
   * @param string $tablePrefix
   *   The table name prefix, including the trailing underscore.
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The unprefixed table name.
   */
  protected function buildTableName(string $tablePrefix, string $migrationId): string {
    $machineName = str_replace(':', '__', $migrationId);
    $dbPrefixLength = strlen($this->database->getPrefix());

    return mb_substr(
      $tablePrefix . mb_strtolower($machineName),
      0,
      self::MAX_LENGTH - $dbPrefixLength,
    );
  }

}
