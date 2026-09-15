<?php

declare(strict_types=1);

namespace Drupal\migrate_suite\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\migrate_suite\Service\StaleRunReaper;

/**
 * Hook implementations for Migrate Suite.
 *
 * On Drupal 11.1+ these methods are discovered via the #[Hook] attributes;
 * on Drupal 10.4 the procedural implementations in migrate_suite.module
 * delegate here instead. Both paths resolve this class as the service
 * registered under its own name in migrate_suite.services.yml.
 */
class MigrateSuiteHooks {

  /**
   * Constructs a MigrateSuiteHooks object.
   *
   * @param \Drupal\migrate_suite\Service\StaleRunReaper $staleRunReaper
   *   The stale run reaper.
   */
  public function __construct(
    protected StaleRunReaper $staleRunReaper,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->staleRunReaper->reapStaleRuns();
  }

}
