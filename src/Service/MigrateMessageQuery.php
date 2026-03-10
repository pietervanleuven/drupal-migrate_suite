<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Service;

use Drupal\Core\Database\Connection;

/**
 * Service for querying migration message tables.
 */
class MigrateMessageQuery {

  /**
   * Constructs a MigrateMessageQuery object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected readonly Connection $database,
  ) {}

  /**
   * Gets the message table name for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The message table name.
   */
  protected function getMessageTableName(string $migrationId): string {
    return 'migrate_message_' . $migrationId;
  }

  /**
   * Fetches messages for a specific migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param int $limit
   *   The number of messages to return.
   * @param int $offset
   *   The offset for pagination.
   *
   * @return array
   *   An array of message rows.
   */
  public function getMessages(string $migrationId, int $limit = 50, int $offset = 0): array {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    return $this->database->select($table, 'msg')
      ->fields('msg')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Fetches messages for a specific source ID within a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param array $sourceIdValues
   *   The source ID values.
   *
   * @return array
   *   An array of message rows for the given source ID.
   */
  public function getMessagesForSourceId(string $migrationId, array $sourceIdValues): array {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $query = $this->database->select($table, 'msg')
      ->fields('msg');

    $index = 1;
    foreach ($sourceIdValues as $value) {
      $query->condition('src_' . $index, $value);
      $index++;
    }

    return $query->execute()->fetchAll();
  }

}
