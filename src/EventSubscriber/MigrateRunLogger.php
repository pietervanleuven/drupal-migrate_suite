<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Drupal\migrate\Event\MigrateRowDeleteEvent;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate_suite\Service\DeltaDetectionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs migration run data to migrate_suite_run_log.
 *
 * A single user-initiated "run" can span multiple calls to
 * MigrateExecutable::import()/rollback(): \Drupal\migrate_admin's batch
 * forms chunk large migrations into repeated calls, each of which is a full
 * PRE_IMPORT/POST_IMPORT (or PRE_ROLLBACK/POST_ROLLBACK) pair, and each of
 * which may happen in its own HTTP request under Drupal's Batch API. The
 * $activeRuns/$counters/$importStack properties below are therefore only
 * ever valid for the lifetime of a single request and a single
 * import()/rollback() call; they cannot carry a logical run across chunks.
 *
 * Cross-request continuity for chunked (batch) runs is handled separately
 * via a "run session", persisted in the State API and keyed by migration
 * ID (see startRunSession()/endRunSession() and the session-aware branches
 * in onPreImport()/onPostImport()/onPreRollback()/onPostRollback()). When no
 * session is active for a migration — a Drush run, a cron/queue run via
 * migrate_schedule, or any other non-batch caller — behaviour is unchanged
 * from before sessions existed: one row is inserted per import()/rollback()
 * call and finalized when it returns.
 */
class MigrateRunLogger implements EventSubscriberInterface {

  /**
   * Cache tag invalidated whenever any migration run or rollback changes.
   *
   * Consumed by admin controllers that tag their render arrays with it so
   * dashboard counts do not go stale after a run.
   */
  const CACHE_TAG_ALL_RUNS = 'migrate_suite:runs';

  /**
   * Cache tag prefix for a single migration's run history.
   *
   * The full tag is this prefix concatenated with the migration ID, e.g.
   * 'migrate_suite:run:my_migration'.
   */
  const CACHE_TAG_RUN_PREFIX = 'migrate_suite:run:';

  /**
   * State key prefix for a migration's active run session.
   *
   * The full key is this prefix concatenated with the migration ID.
   */
  const SESSION_STATE_KEY_PREFIX = 'migrate_suite.run_session:';

  /**
   * Tracks the current run log IDs keyed by migration ID.
   *
   * Only valid for the current request/import() call; see the class
   * docblock.
   *
   * @var array<string, int>
   */
  protected array $activeRuns = [];

  /**
   * Tracks row-level counters keyed by migration ID.
   *
   * Only valid for the current request/import() call; see the class
   * docblock.
   *
   * @var array<string, array{processed: int, created: int, updated: int, failed: int, deleted: int}>
   */
  protected array $counters = [];

  /**
   * Stack of migration IDs whose import() is currently in progress.
   *
   * MigrateMapSaveEvent (MigrateEvents::MAP_SAVE) does not carry a reference
   * to the migration being processed, so we cannot read the migration ID
   * off the event itself. PRE_IMPORT/POST_IMPORT always bracket a single
   * call to MigrateExecutable::import(), so pushing/popping here gives us
   * the innermost migration currently importing, which is the one any
   * MAP_SAVE event in between belongs to.
   *
   * @var string[]
   */
  protected array $importStack = [];

  /**
   * Constructs a MigrateRunLogger object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, used to persist run sessions across the multiple
   *   HTTP requests a chunked batch run spans.
   * @param \Drupal\migrate_suite\Service\DeltaDetectionService|null $deltaDetection
   *   The delta detection service, or NULL if it is unavailable.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected StateInterface $state,
    protected ?DeltaDetectionService $deltaDetection = NULL,
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
      MigrateEvents::MAP_SAVE => ['onMapSave'],
      MigrateEvents::PRE_ROLLBACK => ['onPreRollback'],
      MigrateEvents::POST_ROLLBACK => ['onPostRollback'],
      MigrateEvents::POST_ROW_DELETE => ['onPostRowDelete'],
    ];
  }

  /**
   * Starts (or resumes) a cross-request run session for a migration.
   *
   * Called by a batch form BEFORE the first chunk runs. Marks that all
   * subsequent PRE_IMPORT/POST_IMPORT (or PRE_ROLLBACK/POST_ROLLBACK) event
   * pairs for this migration, across however many chunks/requests it takes,
   * belong to a single logical run and must share one run-log row.
   *
   * Safe to call more than once (e.g. once per batch chunk): if a session
   * is already active for the migration, this is a no-op.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param string $operation
   *   The operation the session covers: 'import' or 'rollback'.
   */
  public function startRunSession(string $migrationId, string $operation): void {
    if ($this->getSession($migrationId) !== NULL) {
      return;
    }

    $this->saveSession($migrationId, [
      'operation' => $operation,
      'row_id' => NULL,
      'counters' => $this->defaultCounters(),
    ]);
  }

  /**
   * Ends a run session, finalizing its run-log row.
   *
   * Called from a batch's 'finished' callback on every path — success,
   * failure, and abort — so a session is never left dangling. Writes the
   * accumulated counters, sets the final status ('failed' if the
   * accumulated failed count is greater than zero, otherwise 'completed'),
   * stamps the finish timestamp, invalidates the run cache tags, and clears
   * the stored session.
   *
   * Safe to call when no session is active for the migration: it is then a
   * no-op and does not create a row.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   */
  public function endRunSession(string $migrationId): void {
    $session = $this->getSession($migrationId);
    if ($session === NULL) {
      return;
    }

    if ($session['row_id'] !== NULL) {
      $counters = $session['counters'];
      $status = $counters['failed'] > 0 ? 'failed' : 'completed';

      $this->database->update('migrate_suite_run_log')
        ->fields($this->counterFields($counters) + [
          'status' => $status,
          'finished' => $this->time->getRequestTime(),
        ])
        ->condition('id', $session['row_id'])
        ->execute();

      $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);
      $this->invalidateRunCacheTags($migrationId);
    }

    $this->clearRunSession($migrationId);
  }

  /**
   * Clears any stored run session for a migration, without touching a row.
   *
   * Used by endRunSession() and by \Drupal\migrate_suite\Service\StaleRunReaper
   * so a reaped row's ID cannot be confused for the ID of a fresh run.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   */
  public function clearRunSession(string $migrationId): void {
    $this->state->delete($this->sessionStateKey($migrationId));
  }

  /**
   * Gets the active run session for a migration, if any.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array{operation: string, row_id: int|null, counters: array{processed: int, created: int, updated: int, failed: int, deleted: int}}|null
   *   The session data, or NULL if no session is active.
   */
  protected function getSession(string $migrationId): ?array {
    $session = $this->state->get($this->sessionStateKey($migrationId));
    return is_array($session) ? $session : NULL;
  }

  /**
   * Persists a run session for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param array{operation: string, row_id: int|null, counters: array{processed: int, created: int, updated: int, failed: int, deleted: int}} $session
   *   The session data.
   */
  protected function saveSession(string $migrationId, array $session): void {
    $this->state->set($this->sessionStateKey($migrationId), $session);
  }

  /**
   * Builds the state key under which a migration's run session is stored.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return string
   *   The state key.
   */
  protected function sessionStateKey(string $migrationId): string {
    return self::SESSION_STATE_KEY_PREFIX . $migrationId;
  }

  /**
   * Builds a fresh, zeroed-out counters array.
   *
   * @return array{processed: int, created: int, updated: int, failed: int, deleted: int}
   *   The zeroed counters.
   */
  protected function defaultCounters(): array {
    return [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
      'deleted' => 0,
    ];
  }

  /**
   * Maps an in-memory counters array to migrate_suite_run_log field values.
   *
   * @param array{processed: int, created: int, updated: int, failed: int, deleted: int} $counters
   *   The counters to map.
   *
   * @return array<string, int>
   *   Field values keyed by column name, ready to pass to fields().
   */
  protected function counterFields(array $counters): array {
    return [
      'items_processed' => $counters['processed'],
      'items_created' => $counters['created'],
      'items_updated' => $counters['updated'],
      'items_failed' => $counters['failed'],
      'items_deleted' => $counters['deleted'],
    ];
  }

  /**
   * Invalidates the cache tags that cover a migration's run history.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   */
  protected function invalidateRunCacheTags(string $migrationId): void {
    $this->cacheTagsInvalidator->invalidateTags([
      self::CACHE_TAG_ALL_RUNS,
      self::CACHE_TAG_RUN_PREFIX . $migrationId,
    ]);
  }

  /**
   * Inserts a new 'running' run-log row for an import.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The import event.
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return int
   *   The new row's ID.
   */
  protected function insertImportRunLogRow(MigrateImportEvent $event, string $migrationId): int {
    $fields = [
      'migration_id' => $migrationId,
      'status' => 'running',
      'started' => $this->time->getRequestTime(),
    ];

    // Store source fingerprint for delta detection.
    if ($this->deltaDetection !== NULL) {
      $migration = $event->getMigration();
      $sourceCount = $this->deltaDetection->getSourceCount($migration);
      $sourceHash = $this->deltaDetection->computeSourceFingerprint($migration);

      if ($sourceCount !== NULL) {
        $fields['source_count'] = $sourceCount;
      }
      if ($sourceHash !== NULL) {
        $fields['source_hash'] = $sourceHash;
      }
    }

    return (int) $this->database->insert('migrate_suite_run_log')
      ->fields($fields)
      ->execute();
  }

  /**
   * Inserts a new 'running' run-log row for a rollback.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return int
   *   The new row's ID.
   */
  protected function insertRollbackRunLogRow(string $migrationId): int {
    return (int) $this->database->insert('migrate_suite_run_log')
      ->fields([
        'migration_id' => $migrationId,
        'status' => 'running',
        'operation' => 'rollback',
        'started' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Reacts to the pre-import event.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The import event.
   */
  public function onPreImport(MigrateImportEvent $event): void {
    $migrationId = $event->getMigration()->id();
    $this->importStack[] = $migrationId;

    $session = $this->getSession($migrationId);
    if ($session !== NULL && $session['operation'] === 'import') {
      // A batch run session is tracking this migration: continue counting
      // from where the previous chunk left off instead of resetting.
      $this->counters[$migrationId] = $session['counters'];

      if ($session['row_id'] !== NULL) {
        // The session already has a row from an earlier chunk: reuse it
        // rather than inserting a second row for the same logical run.
        $this->activeRuns[$migrationId] = $session['row_id'];
        return;
      }

      // First chunk of the session: insert the row now and record its ID
      // on the session so later chunks reuse it.
      $rowId = $this->insertImportRunLogRow($event, $migrationId);
      $session['row_id'] = $rowId;
      $this->saveSession($migrationId, $session);
      $this->activeRuns[$migrationId] = $rowId;
      $this->invalidateRunCacheTags($migrationId);
      return;
    }

    // No session active: a Drush run, a cron/queue run, or any other
    // non-batch caller. Behave exactly as before sessions existed.
    $this->counters[$migrationId] = $this->defaultCounters();
    $this->activeRuns[$migrationId] = $this->insertImportRunLogRow($event, $migrationId);
    $this->invalidateRunCacheTags($migrationId);
  }

  /**
   * Reacts to the post-import event.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The import event.
   */
  public function onPostImport(MigrateImportEvent $event): void {
    $migrationId = $event->getMigration()->id();

    // The import for this migration is no longer in progress, regardless of
    // whether we logged a run for it below.
    array_pop($this->importStack);

    if (!isset($this->activeRuns[$migrationId])) {
      return;
    }

    $counters = $this->counters[$migrationId] ?? $this->defaultCounters();
    $rowId = $this->activeRuns[$migrationId];

    $session = $this->getSession($migrationId);
    if ($session !== NULL && $session['operation'] === 'import') {
      // More chunks of this logical run may still follow: persist progress
      // into the session (so the next chunk resumes from here) and into the
      // row (so the dashboard reflects live progress), but leave 'status'
      // as 'running' and keep the session alive. Only endRunSession() may
      // finalize a session-backed row.
      $session['row_id'] = $rowId;
      $session['counters'] = $counters;
      $this->saveSession($migrationId, $session);

      $this->database->update('migrate_suite_run_log')
        ->fields($this->counterFields($counters))
        ->condition('id', $rowId)
        ->execute();

      $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);
      $this->invalidateRunCacheTags($migrationId);

      unset($this->activeRuns[$migrationId], $this->counters[$migrationId]);
      return;
    }

    // No session active: finalize the row now, exactly as before sessions
    // existed.
    $status = $counters['failed'] > 0 ? 'failed' : 'completed';

    $this->database->update('migrate_suite_run_log')
      ->fields($this->counterFields($counters) + [
        'status' => $status,
        'finished' => $this->time->getRequestTime(),
      ])
      ->condition('id', $rowId)
      ->execute();

    // Invalidate provenance cache tags so pseudo-fields update.
    $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);
    $this->invalidateRunCacheTags($migrationId);

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
   * Core dispatches this event once the destination plugin's import() call
   * has returned, but only when it returned a non-empty result: a falsy
   * result is turned into a STATUS_FAILED map entry (see onMapSave()) without
   * a further event. So by the time this method runs, the row is either a
   * successful create or a successful update; there is nothing left here to
   * classify as failed.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The post-row-save event.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    $migrationId = $event->getMigration()->id();

    if (!isset($this->counters[$migrationId])) {
      return;
    }

    $destIds = $event->getDestinationIdValues();
    if (empty($destIds)) {
      // No destination ID was produced: core will save this row to the map
      // as STATUS_FAILED right after this event, which onMapSave() counts.
      return;
    }

    // Row::getDestination() is NOT a reliable create-vs-update signal: it
    // returns the row's *processed* destination values, which core always
    // populates by the time POST_ROW_SAVE fires, whether the row is new or
    // not. That is why the previous implementation counted every row as an
    // update. The reliable signal is Row::getIdMap(), which holds the
    // *previous* id_map record for this source row: SourcePluginBase::next()
    // populates it from MigrateIdMapInterface::getRowBySource() before the
    // row is handed to the process pipeline, so it reflects the map's state
    // from before this run touched it. If that previous record already had
    // a non-empty destination ID, the row existed before this run started,
    // so this save is an update; otherwise it is a create.
    $previousIdMap = $event->getRow()->getIdMap();
    $hadPreviousDestination = FALSE;
    foreach ($previousIdMap as $key => $value) {
      if (str_starts_with((string) $key, 'destid') && $value !== NULL && $value !== '') {
        $hadPreviousDestination = TRUE;
        break;
      }
    }

    if ($hadPreviousDestination) {
      $this->counters[$migrationId]['updated']++;
    }
    else {
      $this->counters[$migrationId]['created']++;
    }
  }

  /**
   * Reacts to the map-save event to count failed rows.
   *
   * Core does NOT dispatch MigrateEvents::POST_ROW_SAVE for rows that fail:
   * a pipeline exception, a destination exception, or a destination plugin
   * returning an empty result all skip straight to
   * MigrateIdMapInterface::saveIdMapping() with STATUS_FAILED, which is the
   * only place all three failure paths converge. saveIdMapping() dispatches
   * MigrateEvents::MAP_SAVE with the fields about to be written (including
   * 'source_row_status') immediately before persisting them, which is the
   * one reliable place to observe a failure regardless of where it occurred.
   * See MigrateExecutable::import() for the call sites that lead here with
   * STATUS_FAILED.
   *
   * @param \Drupal\migrate\Event\MigrateMapSaveEvent $event
   *   The map-save event.
   */
  public function onMapSave(MigrateMapSaveEvent $event): void {
    // MigrateMapSaveEvent carries no migration reference, so attribute it to
    // the innermost migration currently importing.
    $migrationId = end($this->importStack);
    if ($migrationId === FALSE || !isset($this->counters[$migrationId])) {
      return;
    }

    $fields = $event->getFields();
    $status = (int) ($fields['source_row_status'] ?? MigrateIdMapInterface::STATUS_IMPORTED);
    if ($status === MigrateIdMapInterface::STATUS_FAILED) {
      $this->counters[$migrationId]['failed']++;
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

    $session = $this->getSession($migrationId);
    if ($session !== NULL && $session['operation'] === 'rollback') {
      // A batch run session is tracking this migration: continue counting
      // from where the previous chunk left off instead of resetting.
      $this->counters[$migrationId] = $session['counters'];

      if ($session['row_id'] !== NULL) {
        // The session already has a row from an earlier chunk: reuse it
        // rather than inserting a second row for the same logical run.
        $this->activeRuns[$migrationId] = $session['row_id'];
        return;
      }

      // First chunk of the session: insert the row now and record its ID
      // on the session so later chunks reuse it.
      $rowId = $this->insertRollbackRunLogRow($migrationId);
      $session['row_id'] = $rowId;
      $this->saveSession($migrationId, $session);
      $this->activeRuns[$migrationId] = $rowId;
      $this->invalidateRunCacheTags($migrationId);
      return;
    }

    // No session active: a Drush run, a cron/queue run, or any other
    // non-batch caller. Behave exactly as before sessions existed.
    $this->counters[$migrationId] = $this->defaultCounters();
    $this->activeRuns[$migrationId] = $this->insertRollbackRunLogRow($migrationId);
    $this->invalidateRunCacheTags($migrationId);
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

    $counters = $this->counters[$migrationId] ?? $this->defaultCounters();
    $rowId = $this->activeRuns[$migrationId];

    $session = $this->getSession($migrationId);
    if ($session !== NULL && $session['operation'] === 'rollback') {
      // More chunks of this logical run may still follow: persist progress
      // into the session and into the row, but leave 'status' as 'running'
      // and keep the session alive. Only endRunSession() may finalize a
      // session-backed row.
      $session['row_id'] = $rowId;
      $session['counters'] = $counters;
      $this->saveSession($migrationId, $session);

      $this->database->update('migrate_suite_run_log')
        ->fields(['items_deleted' => $counters['deleted']])
        ->condition('id', $rowId)
        ->execute();

      $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);
      $this->invalidateRunCacheTags($migrationId);

      unset($this->activeRuns[$migrationId], $this->counters[$migrationId]);
      return;
    }

    // No session active: finalize the row now, exactly as before sessions
    // existed.
    $this->database->update('migrate_suite_run_log')
      ->fields([
        'status' => 'completed',
        'finished' => $this->time->getRequestTime(),
        'items_deleted' => $counters['deleted'],
      ])
      ->condition('id', $rowId)
      ->execute();

    $this->cacheTagsInvalidator->invalidateTags(['migrate_source_field:provenance']);
    $this->invalidateRunCacheTags($migrationId);

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
