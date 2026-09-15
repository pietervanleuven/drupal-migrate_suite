<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\migrate_suite\EventSubscriber\MigrateRunLogger;

/**
 * Marks abandoned "running" run log entries as failed.
 *
 * MigrateRunLogger inserts a migrate_suite_run_log row with status
 * 'running' at the start of an import or rollback and only updates it once
 * the corresponding POST_IMPORT/POST_ROLLBACK event fires. If the request
 * is killed, times out, or PHP fatals mid-run, that event never fires and
 * the row stays 'running' forever, which makes the migration look
 * permanently in-progress on the dashboard and to health checks. This
 * service reaps rows that have been 'running' for longer than is plausible
 * for a real run.
 */
class StaleRunReaper {

  /**
   * Maximum time, in seconds, a run may stay 'running' before being reaped.
   */
  const STALE_THRESHOLD_SECONDS = 21600;

  /**
   * Constructs a StaleRunReaper object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\migrate_suite\EventSubscriber\MigrateRunLogger $runLogger
   *   The run logger, used to clear a lingering run session for any
   *   migration whose row is reaped, so a later run is not confused into
   *   reusing the reaped row's ID.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected MigrateRunLogger $runLogger,
  ) {}

  /**
   * Marks stale 'running' run log entries as 'failed'.
   *
   * @return int
   *   The number of run log entries that were reaped.
   */
  public function reapStaleRuns(): int {
    $staleBefore = $this->time->getRequestTime() - static::STALE_THRESHOLD_SECONDS;

    $migrationIds = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r', ['migration_id'])
      ->condition('status', 'running')
      ->condition('started', $staleBefore, '<')
      ->distinct()
      ->execute()
      ->fetchCol();

    if (!$migrationIds) {
      return 0;
    }

    $reaped = (int) $this->database->update('migrate_suite_run_log')
      ->fields([
        'status' => 'failed',
        'finished' => $this->time->getRequestTime(),
      ])
      ->condition('status', 'running')
      ->condition('started', $staleBefore, '<')
      ->execute();

    $tags = [MigrateRunLogger::CACHE_TAG_ALL_RUNS];
    foreach ($migrationIds as $migrationId) {
      $tags[] = MigrateRunLogger::CACHE_TAG_RUN_PREFIX . $migrationId;

      // A reaped row may be the row a lingering batch run session still
      // points to (e.g. the user closed their browser mid-batch). Clear the
      // session so a later run for this migration starts a fresh row
      // instead of reusing the now-'failed' one.
      $this->runLogger->clearRunSession($migrationId);
    }
    $this->cacheTagsInvalidator->invalidateTags($tags);

    return $reaped;
  }

}
