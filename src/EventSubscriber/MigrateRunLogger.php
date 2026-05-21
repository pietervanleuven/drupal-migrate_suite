<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs migration run data to migrate_suite_run_log.
 */
class MigrateRunLogger implements EventSubscriberInterface {

  /**
   * Tracks the current run log IDs keyed by migration ID.
   *
   * @var array<string, int>
   */
  protected array $activeRuns = [];

  /**
   * Tracks row-level counters keyed by migration ID.
   *
   * @var array<string, array{processed: int, created: int, updated: int, failed: int}>
   */
  protected array $counters = [];

  /**
   * Constructs a MigrateRunLogger object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_IMPORT => ['onPreImport'],
      MigrateEvents::POST_IMPORT => ['onPostImport'],
      MigrateEvents::PRE_ROW_SAVE => ['onPreRowSave'],
      MigrateEvents::POST_ROW_SAVE => ['onPostRowSave'],
      MigrateEvents::PRE_ROLLBACK => ['onPreRollback'],
      MigrateEvents::POST_ROLLBACK => ['onPostRollback'],
      MigrateEvents::POST_ROW_DELETE => ['onPostRowDelete'],
    ];
  }

  /**
   * Reacts to the pre-import event.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The import event.
   */
  public function onPreImport(MigrateImportEvent $event): void {
    $migrationId = $event->getMigration()->id();

    $this->counters[$migrationId] = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
    ];

    $id = $this->database->insert('migrate_suite_run_log')
      ->fields([
        'migration_id' => $migrationId,
        'status' => 'running',
        'started' => $this->time->getRequestTime(),
      ])
      ->execute();

    $this->activeRuns[$migrationId] = (int) $id;
  }

  /**
   * Reacts to the post-import event.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The import event.
   */
  public function onPostImport(MigrateImportEvent $event): void {
    $migrationId = $event->getMigration()->id();

    if (!isset($this->activeRuns[$migrationId])) {
      return;
    }

    $counters = $this->counters[$migrationId] ?? [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
    ];

    $status = $counters['failed'] > 0 ? 'failed' : 'completed';

    $this->database->update('migrate_suite_run_log')
      ->fields([
        'status' => $status,
        'finished' => $this->time->getRequestTime(),
        'items_processed' => $counters['processed'],
        'items_created' => $counters['created'],
        'items_updated' => $counters['updated'],
        'items_failed' => $counters['failed'],
      ])
      ->condition('id', $this->activeRuns[$migrationId])
      ->execute();

    // Invalidate provenance cache tags so pseudo-fields update.
    $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);

    unset($this->activeRuns[$migrationId], $this->counters[$migrationId]);
  }

  /**
   * Reacts to the pre-row-save event.
   *
   * @param \Drupal\migrate\Event\MigratePreRowSaveEvent $event
   *   The pre-row-save event.
   */
  public function onPreRowSave(MigratePreRowSaveEvent $event): void {
    // Track that a row is being processed.
    $migrationId = $event->getMigration()->id();
    if (isset($this->counters[$migrationId])) {
      $this->counters[$migrationId]['processed']++;
    }
  }

  /**
   * Reacts to the post-row-save event.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The post-row-save event.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    $migrationId = $event->getMigration()->id();

    if (!isset($this->counters[$migrationId])) {
      return;
    }

    $idMap = $event->getRow()->getIdMap();
    $status = $idMap['source_row_status'] ?? MigrateIdMapInterface::STATUS_IMPORTED;

    switch ($status) {
      case MigrateIdMapInterface::STATUS_IMPORTED:
        // Determine if this is a create or update based on destination IDs.
        $destIds = $event->getDestinationIdValues();
        if (!empty($destIds)) {
          // Check if the row had pre-existing destination IDs (update).
          $previousDestIds = $event->getRow()->getDestination();
          if (!empty($previousDestIds)) {
            $this->counters[$migrationId]['updated']++;
          }
          else {
            $this->counters[$migrationId]['created']++;
          }
        }
        break;

      case MigrateIdMapInterface::STATUS_FAILED:
        $this->counters[$migrationId]['failed']++;
        break;
    }
  }

  /**
   * Reacts to the pre-rollback event.
   *
   * @param \Drupal\migrate\Event\MigrateRollbackEvent $event
   *   The rollback event.
   */
  public function onPreRollback(MigrateRollbackEvent $event): void {
    $migrationId = $event->getMigration()->id();

    $this->counters[$migrationId] = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
      'deleted' => 0,
    ];

    $id = $this->database->insert('migrate_suite_run_log')
      ->fields([
        'migration_id' => $migrationId,
        'status' => 'running',
        'operation' => 'rollback',
        'started' => $this->time->getRequestTime(),
      ])
      ->execute();

    $this->activeRuns[$migrationId] = (int) $id;
  }

  /**
   * Reacts to the post-rollback event.
   *
   * @param \Drupal\migrate\Event\MigrateRollbackEvent $event
   *   The rollback event.
   */
  public function onPostRollback(MigrateRollbackEvent $event): void {
    $migrationId = $event->getMigration()->id();

    if (!isset($this->activeRuns[$migrationId])) {
      return;
    }

    $counters = $this->counters[$migrationId] ?? ['deleted' => 0];

    $this->database->update('migrate_suite_run_log')
      ->fields([
        'status' => 'completed',
        'finished' => $this->time->getRequestTime(),
        'items_deleted' => $counters['deleted'],
      ])
      ->condition('id', $this->activeRuns[$migrationId])
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);

    unset($this->activeRuns[$migrationId], $this->counters[$migrationId]);
  }

  /**
   * Reacts to the post-row-delete event.
   *
   * @param \Drupal\migrate\Event\MigrateRowDeleteEvent $event
   *   The row delete event.
   */
  public function onPostRowDelete(MigrateRowDeleteEvent $event): void {
    $migrationId = $event->getMigration()->id();
    if (isset($this->counters[$migrationId])) {
      $this->counters[$migrationId]['deleted']++;
    }
  }

}
