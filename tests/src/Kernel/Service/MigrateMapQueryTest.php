<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_suite\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate_suite\Service\MigrateMapQuery;

/**
 * @coversDefaultClass \Drupal\migrate_suite\Service\MigrateMapQuery
 * @group migrate_suite
 */
class MigrateMapQueryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['migrate', 'migrate_suite'];

  /**
   * The service under test.
   */
  protected MigrateMapQuery $mapQuery;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('migrate_suite', ['migrate_suite_run_log']);
    $this->mapQuery = $this->container->get('migrate_suite.map_query');
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
    $this->container->get('database')->schema()->createTable($table, [
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
  protected function insertMapRow(string $migrationId, string $sourceId, int $destId, int $status = 0): void {
    $this->insertMapRowIntoTable('migrate_map_' . $migrationId, $sourceId, $destId, $status);
  }

  /**
   * Inserts a row into an already-named map table.
   */
  protected function insertMapRowIntoTable(string $table, string $sourceId, int $destId, int $status = 0): void {
    $this->container->get('database')->insert($table)
      ->fields([
        'source_ids_hash' => md5($sourceId),
        'sourceid1' => $sourceId,
        'destid1' => $destId,
        'source_row_status' => $status,
        'last_imported' => time(),
        'hash' => '',
      ])
      ->execute();
  }

  /**
   * @covers ::lookupDestinationIds
   */
  public function testLookupDestinationIds(): void {
    $this->insertMapRow('test_migration', '100', 1);

    $result = $this->mapQuery->lookupDestinationIds('test_migration', ['100']);
    $this->assertEquals(['destid1' => '1'], $result);
  }

  /**
   * @covers ::lookupDestinationIds
   */
  public function testLookupDestinationIdsReturnsEmptyForMissing(): void {
    $result = $this->mapQuery->lookupDestinationIds('test_migration', ['999']);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::lookupDestinationIds
   */
  public function testLookupDestinationIdsReturnsEmptyForNonexistentTable(): void {
    $result = $this->mapQuery->lookupDestinationIds('nonexistent', ['1']);
    $this->assertEmpty($result);
  }

  /**
   * @covers ::listImportedItems
   */
  public function testListImportedItems(): void {
    $this->insertMapRow('test_migration', '100', 1);
    $this->insertMapRow('test_migration', '101', 2);
    $this->insertMapRow('test_migration', '102', 3);

    $items = $this->mapQuery->listImportedItems('test_migration');
    $this->assertCount(3, $items);
  }

  /**
   * @covers ::listImportedItems
   */
  public function testListImportedItemsPagination(): void {
    $this->insertMapRow('test_migration', '100', 1);
    $this->insertMapRow('test_migration', '101', 2);
    $this->insertMapRow('test_migration', '102', 3);

    $items = $this->mapQuery->listImportedItems('test_migration', 2, 0);
    $this->assertCount(2, $items);

    $items = $this->mapQuery->listImportedItems('test_migration', 2, 2);
    $this->assertCount(1, $items);
  }

  /**
   * @covers ::listImportedItems
   */
  public function testListImportedItemsReturnsEmptyForNonexistentTable(): void {
    $items = $this->mapQuery->listImportedItems('nonexistent');
    $this->assertEmpty($items);
  }

  /**
   * @covers ::countItemsByStatus
   */
  public function testCountItemsByStatus(): void {
    $this->insertMapRow('test_migration', '100', 1, MigrateIdMapInterface::STATUS_IMPORTED);
    $this->insertMapRow('test_migration', '101', 2, MigrateIdMapInterface::STATUS_IMPORTED);
    $this->insertMapRow('test_migration', '102', 3, MigrateIdMapInterface::STATUS_NEEDS_UPDATE);
    $this->insertMapRow('test_migration', '103', 0, MigrateIdMapInterface::STATUS_FAILED);

    $counts = $this->mapQuery->countItemsByStatus('test_migration');
    $this->assertEquals(2, $counts['imported']);
    $this->assertEquals(1, $counts['needs_update']);
    $this->assertEquals(1, $counts['failed']);
  }

  /**
   * @covers ::countItemsByStatus
   */
  public function testCountItemsByStatusReturnsZerosForEmptyTable(): void {
    $counts = $this->mapQuery->countItemsByStatus('test_migration');
    $this->assertEquals(['imported' => 0, 'needs_update' => 0, 'failed' => 0], $counts);
  }

  /**
   * @covers ::countItemsByStatus
   */
  public function testCountItemsByStatusReturnsZerosForNonexistentTable(): void {
    $counts = $this->mapQuery->countItemsByStatus('nonexistent');
    $this->assertEquals(['imported' => 0, 'needs_update' => 0, 'failed' => 0], $counts);
  }

  /**
   * Tests that a derived migration ID resolves to core's actual table name.
   *
   * Regression test: derived migrations carry a colon in their ID
   * ("d7_node:article"). Naively concatenating "migrate_map_" with the raw
   * ID produces "migrate_map_d7_node:article", a table that core never
   * creates, so every query against it silently returned nothing. Core
   * actually creates "migrate_map_d7_node__article" (colon replaced with a
   * double underscore, lowercased) — see
   * \Drupal\migrate\Plugin\migrate\id_map\Sql::__construct(). This test
   * creates the fixture table under that real name and confirms the
   * service still finds it when passed the raw, colon-bearing ID.
   *
   * @covers ::lookupDestinationIds
   * @covers ::listImportedItems
   * @covers ::countItemsByStatus
   */
  public function testDerivedMigrationIdResolvesToCoreTableName(): void {
    $migrationId = 'd7_node:article';
    $table = 'migrate_map_d7_node__article';

    $this->createMapTableNamed($table);
    $this->insertMapRowIntoTable($table, '100', 1, MigrateIdMapInterface::STATUS_IMPORTED);
    $this->insertMapRowIntoTable($table, '101', 2, MigrateIdMapInterface::STATUS_FAILED);

    $result = $this->mapQuery->lookupDestinationIds($migrationId, ['100']);
    $this->assertEquals(['destid1' => '1'], $result);

    $items = $this->mapQuery->listImportedItems($migrationId);
    $this->assertCount(2, $items);

    $counts = $this->mapQuery->countItemsByStatus($migrationId);
    $this->assertEquals(1, $counts['imported']);
    $this->assertEquals(1, $counts['failed']);
  }

}
