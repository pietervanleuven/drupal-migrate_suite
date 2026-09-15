<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_suite\Kernel\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\migrate_suite\EventSubscriber\MigrateRunLogger;

/**
 * @coversDefaultClass \Drupal\migrate_suite\EventSubscriber\MigrateRunLogger
 * @group migrate_suite
 */
class MigrateRunLoggerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['migrate', 'migrate_suite'];

  /**
   * The run logger service.
   */
  protected MigrateRunLogger $runLogger;

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
    $this->runLogger = $this->container->get('migrate_suite.run_logger');
    $this->database = $this->container->get('database');
  }

  /**
   * Creates a mock migration.
   */
  protected function createMockMigration(string $id): MigrationInterface {
    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('id')->willReturn($id);
    return $migration;
  }

  /**
   * @covers ::getSubscribedEvents
   */
  public function testSubscribedEvents(): void {
    $events = MigrateRunLogger::getSubscribedEvents();

    $this->assertArrayHasKey(MigrateEvents::PRE_IMPORT, $events);
    $this->assertArrayHasKey(MigrateEvents::POST_IMPORT, $events);
    $this->assertArrayHasKey(MigrateEvents::PRE_ROW_SAVE, $events);
    $this->assertArrayHasKey(MigrateEvents::POST_ROW_SAVE, $events);
  }

  /**
   * @covers ::onPreImport
   */
  public function testPreImportCreatesRunLogEntry(): void {
    $migration = $this->createMockMigration('test_migration');
    $event = new MigrateImportEvent($migration);

    $this->runLogger->onPreImport($event);

    $row = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($row);
    $this->assertEquals('running', $row['status']);
    $this->assertEquals('test_migration', $row['migration_id']);
  }

  /**
   * @covers ::onPostImport
   */
  public function testPostImportCompletesRunLog(): void {
    $migration = $this->createMockMigration('test_migration');
    $preEvent = new MigrateImportEvent($migration);
    $postEvent = new MigrateImportEvent($migration);

    $this->runLogger->onPreImport($preEvent);
    $this->runLogger->onPostImport($postEvent);

    $row = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals('completed', $row['status']);
    $this->assertNotEmpty($row['finished']);
  }

  /**
   * @covers ::onPreRowSave
   * @covers ::onPostRowSave
   * @covers ::onPostImport
   */
  public function testCountersTrackProcessedRows(): void {
    $migration = $this->createMockMigration('test_migration');

    // Start the import.
    $this->runLogger->onPreImport(new MigrateImportEvent($migration));

    // Simulate processing 2 rows (new creates).
    for ($i = 0; $i < 2; $i++) {
      $row = $this->createMock(Row::class);
      $row->method('getIdMap')->willReturn([
        'source_row_status' => MigrateIdMapInterface::STATUS_IMPORTED,
      ]);
      $row->method('getDestination')->willReturn([]);

      $preRowEvent = new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row);
      $postRowEvent = new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row, [$i + 1]);

      $this->runLogger->onPreRowSave($preRowEvent);
      $this->runLogger->onPostRowSave($postRowEvent);
    }

    // End the import.
    $this->runLogger->onPostImport(new MigrateImportEvent($migration));

    $log = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(2, $log['items_processed']);
    $this->assertEquals(2, $log['items_created']);
    $this->assertEquals(0, $log['items_failed']);
    $this->assertEquals('completed', $log['status']);
  }

  /**
   * @covers ::onPostRowSave
   * @covers ::onPostImport
   */
  public function testFailedRowsSetsFailedStatus(): void {
    $migration = $this->createMockMigration('test_migration');

    $this->runLogger->onPreImport(new MigrateImportEvent($migration));

    // Simulate a failed row.
    $row = $this->createMock(Row::class);
    $row->method('getIdMap')->willReturn([
      'source_row_status' => MigrateIdMapInterface::STATUS_FAILED,
    ]);

    $preRowEvent = new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row);
    $postRowEvent = new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row, []);

    $this->runLogger->onPreRowSave($preRowEvent);
    $this->runLogger->onPostRowSave($postRowEvent);

    $this->runLogger->onPostImport(new MigrateImportEvent($migration));

    $log = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(1, $log['items_failed']);
    $this->assertEquals('failed', $log['status']);
  }

  /**
   * @covers ::onPostImport
   */
  public function testPostImportWithoutPreImportDoesNothing(): void {
    $migration = $this->createMockMigration('test_migration');
    $event = new MigrateImportEvent($migration);

    // Call post without pre — should not throw or insert anything.
    $this->runLogger->onPostImport($event);

    $count = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(0, $count);
  }

}
