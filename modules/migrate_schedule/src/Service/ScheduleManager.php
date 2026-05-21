<?php

declare(strict_types=1);

namespace Drupal\migrate_schedule\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Manages per-migration schedule configuration.
 */
class ScheduleManager {

  /**
   * Interval durations in seconds.
   */
  protected const INTERVALS = [
    'hourly' => 3600,
    'daily' => 86400,
    'weekly' => 604800,
  ];

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
  ) {}

  /**
   * Gets all configured schedules.
   *
   * @return array
   *   Associative array keyed by migration ID with schedule data.
   */
  public function getSchedules(): array {
    $config = $this->configFactory->get('migrate_schedule.settings');
    $schedules = $config->get('schedules') ?? [];

    // Filter out disabled schedules.
    return array_filter($schedules, fn(array $schedule) => ($schedule['interval'] ?? 'disabled') !== 'disabled');
  }

  /**
   * Gets the schedule for a specific migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return array|null
   *   The schedule data, or NULL if not scheduled.
   */
  public function getSchedule(string $migrationId): ?array {
    $schedules = $this->configFactory->get('migrate_schedule.settings')->get('schedules') ?? [];
    $schedule = $schedules[$migrationId] ?? NULL;

    if ($schedule === NULL || ($schedule['interval'] ?? 'disabled') === 'disabled') {
      return NULL;
    }

    return $schedule;
  }

  /**
   * Sets a schedule for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   * @param string $interval
   *   The interval (disabled, hourly, daily, weekly, custom).
   * @param string $cronExpression
   *   The cron expression for custom intervals.
   * @param bool $skipIfNoChanges
   *   Whether to skip if no source changes detected.
   */
  public function setSchedule(string $migrationId, string $interval, string $cronExpression = '', bool $skipIfNoChanges = FALSE): void {
    $config = $this->configFactory->getEditable('migrate_schedule.settings');
    $schedules = $config->get('schedules') ?? [];

    $schedules[$migrationId] = [
      'interval' => $interval,
      'cron_expression' => $cronExpression,
      'skip_if_no_changes' => $skipIfNoChanges,
    ];

    $config->set('schedules', $schedules)->save();
  }

  /**
   * Removes a schedule for a migration.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   */
  public function removeSchedule(string $migrationId): void {
    $config = $this->configFactory->getEditable('migrate_schedule.settings');
    $schedules = $config->get('schedules') ?? [];
    unset($schedules[$migrationId]);
    $config->set('schedules', $schedules)->save();
  }

  /**
   * Checks whether a migration is due to run based on its schedule.
   *
   * @param string $migrationId
   *   The migration plugin ID.
   *
   * @return bool
   *   TRUE if the migration should be run.
   */
  public function isDue(string $migrationId): bool {
    $schedule = $this->getSchedule($migrationId);
    if ($schedule === NULL) {
      return FALSE;
    }

    $interval = $schedule['interval'];
    if (!isset(self::INTERVALS[$interval])) {
      return FALSE;
    }

    $intervalSeconds = self::INTERVALS[$interval];

    // Find the last completed run.
    $lastRun = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r', ['finished'])
      ->condition('migration_id', $migrationId)
      ->condition('operation', 'import')
      ->condition('status', 'completed')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$lastRun) {
      return TRUE;
    }

    return (\Drupal::time()->getRequestTime() - (int) $lastRun) >= $intervalSeconds;
  }

}
