<?php

declare(strict_types=1);

namespace Drupal\migrate_schedule\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\migrate_schedule\Service\ScheduleManager;

/**
 * Hook implementations for Migrate Schedule.
 *
 * On Drupal 11.1+ these methods are discovered via the #[Hook] attributes;
 * on Drupal 10.4 the procedural implementations in migrate_schedule.module
 * delegate here instead. Both paths resolve this class as the service
 * registered under its own name in migrate_schedule.services.yml.
 */
class MigrateScheduleHooks {

  /**
   * Constructs a MigrateScheduleHooks object.
   *
   * @param \Drupal\migrate_schedule\Service\ScheduleManager $scheduleManager
   *   The schedule manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    protected ScheduleManager $scheduleManager,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $queue = $this->queueFactory->get('migrate_schedule_run');

    foreach ($this->scheduleManager->getSchedules() as $migrationId => $schedule) {
      if ($this->scheduleManager->isDue($migrationId)) {
        $queue->createItem([
          'migration_id' => $migrationId,
          'skip_if_no_changes' => $schedule['skip_if_no_changes'] ?? FALSE,
        ]);
      }
    }
  }

}
