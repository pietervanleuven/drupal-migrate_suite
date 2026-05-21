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
  /**
   * Gets message counts grouped by severity level.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array
   *   Associative array with keys 'error', 'warning', 'notice' and counts.
   */
  public function getSeverityCounts(string $migrationId): array {
    $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return $counts;
    }

    $results = $this->database->select($table, 'msg')
      ->fields('msg', ['level'])
      ->groupBy('level')
      ->addExpression('COUNT(*)', 'count')
      ->execute()
      ->fetchAllKeyed();

    $levelMap = [3 => 'error', 4 => 'warning', 6 => 'notice'];
    foreach ($results as $level => $count) {
      $key = $levelMap[(int) $level] ?? NULL;
      if ($key !== NULL) {
        $counts[$key] = (int) $count;
      }
    }

    return $counts;
  }

  /**
   * Gets messages grouped by message text and severity.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param int $limit
   *   The number of groups to return.
   * @param int $offset
   *   The offset for pagination.
   *
   * @return array
   *   Array of objects with 'message', 'level', and 'count' properties.
   */
  public function getGroupedMessages(string $migrationId, int $limit = 50, int $offset = 0): array {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $query = $this->database->select($table, 'msg')
      ->fields('msg', ['message', 'level'])
      ->groupBy('message')
      ->groupBy('level')
      ->orderBy('count', 'DESC')
      ->range($offset, $limit);
    $query->addExpression('COUNT(*)', 'count');

    return $query->execute()->fetchAll();
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
  /**
   * Searches messages by text with optional severity filter.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param string $search
   *   Search string to match against message text.
   * @param int|null $severity
   *   Optional severity level filter (RFC 5424).
   * @param int $limit
   *   The number of messages to return.
   * @param int $offset
   *   The offset for pagination.
   *
   * @return array
   *   An array of message rows.
   */
  public function searchMessages(string $migrationId, string $search, ?int $severity = NULL, int $limit = 50, int $offset = 0): array {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $query = $this->database->select($table, 'msg')
      ->fields('msg');

    if ($search !== '') {
      $query->condition('message', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
    }

    if ($severity !== NULL) {
      $query->condition('level', $severity);
    }

    return $query->range($offset, $limit)->execute()->fetchAll();
  }

  /**
   * Yields all messages for export, with optional severity filter.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param int|null $severity
   *   Optional severity level filter.
   *
   * @return \Generator
   *   Yields message row objects.
   */
  public function getAllMessages(string $migrationId, ?int $severity = NULL): \Generator {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return;
    }

    $query = $this->database->select($table, 'msg')
      ->fields('msg');

    if ($severity !== NULL) {
      $query->condition('level', $severity);
    }

    $result = $query->execute();
    while ($row = $result->fetchObject()) {
      yield $row;
    }
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
  /**
   * Gets source IDs for all messages matching a specific text.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param string $messageText
   *   The exact message text to match.
   *
   * @return array
   *   Array of arrays, each containing source ID values for a matching row.
   */
  public function getSourceIdsForMessage(string $migrationId, string $messageText): array {
    $table = $this->getMessageTableName($migrationId);

    if (!$this->database->schema()->tableExists($table)) {
      return [];
    }

    $rows = $this->database->select($table, 'msg')
      ->fields('msg')
      ->condition('message', $messageText)
      ->execute()
      ->fetchAll();

    $sourceIdSets = [];
    foreach ($rows as $row) {
      $sourceIds = [];
      for ($i = 1; $i <= 9; $i++) {
        $col = 'src_' . $i;
        if (isset($row->$col) && $row->$col !== NULL) {
          $sourceIds[] = $row->$col;
        }
      }
      if (!empty($sourceIds)) {
        $sourceIdSets[] = $sourceIds;
      }
    }

    return $sourceIdSets;
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
