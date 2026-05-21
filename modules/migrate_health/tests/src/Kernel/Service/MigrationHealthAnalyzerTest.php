<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_health\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate_health\Service\MigrationHealthAnalyzer;

/**
 * @coversDefaultClass \Drupal\migrate_health\Service\MigrationHealthAnalyzer
 * @group migrate_health
 */
class MigrationHealthAnalyzerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['migrate', 'migrate_suite', 'migrate_health'];

  /**
   * The service under test.
   */
  protected MigrationHealthAnalyzer $analyzer;

  /**
   * The database connection.
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('migrate_suite', ['migrate_suite_run_log']);
    $this->installConfig(['migrate_health']);
    $this->analyzer = $this->container->get('migrate_health.analyzer');
    $this->database = $this->container->get('database');
    $this->createMapTable('test_migration');
  }

  /**
   * Creates a migrate_map table for testing.
   */
  protected function createMapTable(string $migrationId): void {
    $this->database->schema()->createTable('migrate_map_' . $migrationId, [
      'fields' => [
        'source_ids_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
        'sourceid1' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'destid1' => ['type' => 'int', 'unsigned' => TRUE],
        'source_row_status' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'last_imported' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'hash' => ['type' => 'varchar', 'length' => 64],
      ],
      'primary key' => ['source_ids_hash'],
    ]);
  }

  /**
   * Inserts a row into the map table.
   */
  protected function insertMapRow(string $migrationId, string $sourceId, int $status): void {
    $this->database->insert('migrate_map_' . $migrationId)
      ->fields([
        'source_ids_hash' => md5($sourceId),
        'sourceid1' => $sourceId,
        'destid1' => (int) $sourceId,
        'source_row_status' => $status,
        'last_imported' => time(),
        'hash' => '',
      ])
      ->execute();
  }

  /**
   * Inserts a run log entry.
   */
  protected function insertRunLog(string $migrationId, int $started): void {
    $this->database->insert('migrate_suite_run_log')
      ->fields([
        'migration_id' => $migrationId,
        'status' => 'completed',
        'started' => $started,
        'finished' => $started + 60,
        'items_processed' => 10,
        'items_created' => 10,
        'items_updated' => 0,
        'items_failed' => 0,
      ])
      ->execute();
  }

  /**
   * @covers ::getHealth
   */
  public function testHealthyMigration(): void {
    // Recent run, no failures.
    $this->insertRunLog('test_migration', time() - 3600);
    $this->insertMapRow('test_migration', '1', MigrateIdMapInterface::STATUS_IMPORTED);

    $this->assertEquals(MigrationHealthAnalyzer::HEALTHY, $this->analyzer->getHealth('test_migration'));
  }

  /**
   * @covers ::getHealth
   */
  public function testStaleMigrationNeverRun(): void {
    // No run log entry = stale.
    $this->insertMapRow('test_migration', '1', MigrateIdMapInterface::STATUS_IMPORTED);

    $this->assertEquals(MigrationHealthAnalyzer::STALE, $this->analyzer->getHealth('test_migration'));
  }

  /**
   * @covers ::getHealth
   */
  public function testStaleMigrationOldRun(): void {
    // Run 30 days ago, threshold is 7 days.
    $this->insertRunLog('test_migration', time() - (30 * 86400));
    $this->insertMapRow('test_migration', '1', MigrateIdMapInterface::STATUS_IMPORTED);

    $this->assertEquals(MigrationHealthAnalyzer::STALE, $this->analyzer->getHealth('test_migration'));
  }

  /**
   * @covers ::getHealth
   */
  public function testFailingMigration(): void {
    // Recent run but high failure rate (>5% default threshold).
    $this->insertRunLog('test_migration', time() - 3600);

    // 10 items: 4 imported, 6 failed = 60% failure rate.
    for ($i = 1; $i <= 4; $i++) {
      $this->insertMapRow('test_migration', (string) $i, MigrateIdMapInterface::STATUS_IMPORTED);
    }
    for ($i = 5; $i <= 10; $i++) {
      $this->insertMapRow('test_migration', (string) $i, MigrateIdMapInterface::STATUS_FAILED);
    }

    $this->assertEquals(MigrationHealthAnalyzer::FAILING, $this->analyzer->getHealth('test_migration'));
  }

  /**
   * @covers ::getHealth
   */
  public function testFailingTakesPriorityOverStale(): void {
    // Old run AND high failure rate — should report failing, not stale.
    $this->insertRunLog('test_migration', time() - (30 * 86400));

    for ($i = 1; $i <= 10; $i++) {
      $this->insertMapRow('test_migration', (string) $i, MigrateIdMapInterface::STATUS_FAILED);
    }

    $this->assertEquals(MigrationHealthAnalyzer::FAILING, $this->analyzer->getHealth('test_migration'));
  }

  /**
   * @covers ::getHealth
   */
  public function testNonexistentMapTableIsNotFailing(): void {
    // No map table at all — should be stale (never run), not failing.
    $this->assertEquals(MigrationHealthAnalyzer::STALE, $this->analyzer->getHealth('nonexistent_migration'));
  }

  /**
   * @covers ::getHealthForMultiple
   */
  public function testGetHealthForMultiple(): void {
    $this->createMapTable('migration_b');
    $this->insertRunLog('test_migration', time() - 3600);
    $this->insertMapRow('test_migration', '1', MigrateIdMapInterface::STATUS_IMPORTED);
    // migration_b has no run log, so it's stale.

    $results = $this->analyzer->getHealthForMultiple(['test_migration', 'migration_b']);

    $this->assertEquals(MigrationHealthAnalyzer::HEALTHY, $results['test_migration']);
    $this->assertEquals(MigrationHealthAnalyzer::STALE, $results['migration_b']);
  }

  /**
   * @covers ::getAggregateSummary
   */
  public function testGetAggregateSummary(): void {
    $statuses = [
      'a' => MigrationHealthAnalyzer::HEALTHY,
      'b' => MigrationHealthAnalyzer::HEALTHY,
      'c' => MigrationHealthAnalyzer::STALE,
      'd' => MigrationHealthAnalyzer::FAILING,
    ];

    $summary = $this->analyzer->getAggregateSummary($statuses);

    $this->assertEquals(2, $summary['healthy']);
    $this->assertEquals(1, $summary['stale']);
    $this->assertEquals(1, $summary['failing']);
  }

}
