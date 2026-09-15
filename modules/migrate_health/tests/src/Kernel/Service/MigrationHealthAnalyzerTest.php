<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_health\Kernel\Service;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate_health\Service\MigrationHealthAnalyzer;

/**
 * @coversDefaultClass \Drupal\migrate_health\Service\MigrationHealthAnalyzer
 * @group migrate_suite
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
  protected Connection $database;

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
   *
   * Only valid for migration IDs without a colon, where naive concatenation
   * and core's actual table-naming rules happen to agree.
   */
  protected function createMapTable(string $migrationId): void {
    $this->createMapTableNamed('migrate_map_' . $migrationId);
  }

  /**
   * Creates a migrate_map table for testing, given an already-built name.
   *
   * @param string $table
   *   The full (unprefixed) map table name, e.g. as core's
   *   \Drupal\migrate\Plugin\migrate\id_map\Sql would build it for a
   *   migration ID.
   */
  protected function createMapTableNamed(string $table): void {
    $this->database->schema()->createTable($table, [
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
   *
   * Only valid for migration IDs without a colon; see createMapTable().
   */
  protected function insertMapRow(string $migrationId, string $sourceId, int $status): void {
    $this->insertMapRowIntoTable('migrate_map_' . $migrationId, $sourceId, $status);
  }

  /**
   * Inserts a row into an already-named map table.
   */
  protected function insertMapRowIntoTable(string $table, string $sourceId, int $status): void {
    $this->database->insert($table)
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

  /**
   * Tests that a derived migration ID resolves to core's actual table name.
   *
   * Regression test: derived migrations carry a colon in their ID
   * ("d7_node:article"). Naively concatenating "migrate_map_" with the raw
   * ID produces "migrate_map_d7_node:article", a table that core never
   * creates, so the failure-rate check silently saw no rows and could
   * never report a migration as failing. Core actually creates
   * "migrate_map_d7_node__article" (colon replaced with a double
   * underscore, lowercased) — see
   * \Drupal\migrate\Plugin\migrate\id_map\Sql::__construct(). This test
   * creates the fixture table under that real name and confirms the
   * analyzer still finds it when passed the raw, colon-bearing ID.
   *
   * @covers ::getHealth
   */
  public function testDerivedMigrationIdResolvesToCoreTableName(): void {
    $migrationId = 'd7_node:article';
    $table = 'migrate_map_d7_node__article';

    $this->insertRunLog($migrationId, time() - 3600);

    // 10 items: 4 imported, 6 failed = 60% failure rate.
    $this->createMapTableNamed($table);
    for ($i = 1; $i <= 4; $i++) {
      $this->insertMapRowIntoTable($table, (string) $i, MigrateIdMapInterface::STATUS_IMPORTED);
    }
    for ($i = 5; $i <= 10; $i++) {
      $this->insertMapRowIntoTable($table, (string) $i, MigrateIdMapInterface::STATUS_FAILED);
    }

    $this->assertEquals(MigrationHealthAnalyzer::FAILING, $this->analyzer->getHealth($migrationId));
  }

}
