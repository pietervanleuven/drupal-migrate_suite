<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_suite\Kernel\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrateSourceInterface;
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
    $source = $this->createMock(MigrateSourceInterface::class);
    $source->method('count')->willReturn(0);
    $source->method('valid')->willReturn(FALSE);

    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('id')->willReturn($id);
    $migration->method('getSourcePlugin')->willReturn($source);
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
    $this->assertArrayHasKey(MigrateEvents::MAP_SAVE, $events);
  }

  /**
   * @covers ::onPreImport
   */
  public function testPreImportCreatesRunLogEntry(): void {
    $migration = $this->createMockMigration('test_migration');
    $event = new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class));

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
    $preEvent = new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class));
    $postEvent = new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class));

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
    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    // Simulate processing 2 new rows (no previous id_map entry, so these
    // are creates, not updates).
    for ($i = 0; $i < 2; $i++) {
      $row = $this->createMock(Row::class);
      $row->method('getIdMap')->willReturn([
        'source_row_status' => MigrateIdMapInterface::STATUS_IMPORTED,
      ]);

      $preRowEvent = new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row);
      $postRowEvent = new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row, [$i + 1]);

      $this->runLogger->onPreRowSave($preRowEvent);
      $this->runLogger->onPostRowSave($postRowEvent);
    }

    // End the import.
    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $log = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(2, $log['items_processed']);
    $this->assertEquals(2, $log['items_created']);
    $this->assertEquals(0, $log['items_updated']);
    $this->assertEquals(0, $log['items_failed']);
    $this->assertEquals('completed', $log['status']);
  }

  /**
   * @covers ::onPreRowSave
   * @covers ::onPostRowSave
   * @covers ::onPostImport
   */
  public function testCountersTrackUpdatedRows(): void {
    $migration = $this->createMockMigration('test_migration');

    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    // Simulate a row that already had a destination ID recorded in the map
    // from a previous run: this must count as an update, not a create.
    // Row::getDestination() (the processed destination values for *this*
    // run) is always populated at POST_ROW_SAVE time, which is exactly why
    // it cannot be used to distinguish a create from an update; only the
    // previous id_map record (Row::getIdMap()) can.
    $row = $this->createMock(Row::class);
    $row->method('getIdMap')->willReturn([
      'sourceid1' => 5,
      'destid1' => 42,
      'source_row_status' => MigrateIdMapInterface::STATUS_IMPORTED,
    ]);
    $row->method('getDestination')->willReturn(['nid' => 42]);

    $preRowEvent = new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row);
    $postRowEvent = new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row, [42]);

    $this->runLogger->onPreRowSave($preRowEvent);
    $this->runLogger->onPostRowSave($postRowEvent);

    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $log = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(1, $log['items_processed']);
    $this->assertEquals(0, $log['items_created']);
    $this->assertEquals(1, $log['items_updated']);
    $this->assertEquals('completed', $log['status']);
  }

  /**
   * @covers ::onMapSave
   * @covers ::onPostImport
   */
  public function testFailedRowsSetsFailedStatus(): void {
    $migration = $this->createMockMigration('test_migration');

    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    // Core never dispatches POST_ROW_SAVE for a failed row: pipeline
    // exceptions, destination exceptions, and a destination plugin
    // returning an empty result all go straight to
    // MigrateIdMapInterface::saveIdMapping() with STATUS_FAILED, which
    // dispatches MigrateEvents::MAP_SAVE with that status in its fields.
    $idMap = $this->createMock(MigrateIdMapInterface::class);
    $mapSaveEvent = new MigrateMapSaveEvent($idMap, [
      'sourceid1' => 5,
      'source_row_status' => MigrateIdMapInterface::STATUS_FAILED,
    ]);

    $this->runLogger->onMapSave($mapSaveEvent);

    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $log = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'test_migration')
      ->execute()
      ->fetchAssoc();

    $this->assertEquals(1, $log['items_failed']);
    $this->assertEquals('failed', $log['status']);
  }

  /**
   * @covers ::onMapSave
   */
  public function testMapSaveIgnoredOutsideAnImport(): void {
    // With no onPreImport() having run, there is no migration on the
    // import stack, so onMapSave() must be a no-op rather than erroring or
    // attributing the failure to the wrong migration.
    $idMap = $this->createMock(MigrateIdMapInterface::class);
    $mapSaveEvent = new MigrateMapSaveEvent($idMap, [
      'sourceid1' => 5,
      'source_row_status' => MigrateIdMapInterface::STATUS_FAILED,
    ]);

    $this->runLogger->onMapSave($mapSaveEvent);

    $count = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(0, $count);
  }

  /**
   * @covers ::onPostImport
   */
  public function testPostImportWithoutPreImportDoesNothing(): void {
    $migration = $this->createMockMigration('test_migration');
    $event = new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class));

    // Call post without pre — should not throw or insert anything.
    $this->runLogger->onPostImport($event);

    $count = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(0, $count);
  }

  /**
   * @covers ::endRunSession
   */
  public function testEndRunSessionWithoutSessionIsNoOp(): void {
    // No startRunSession() call precedes this: it must not throw or create
    // a row.
    $this->runLogger->endRunSession('no_such_session_migration');

    $count = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(0, $count);
  }

  /**
   * @covers ::startRunSession
   * @covers ::endRunSession
   * @covers ::onPreImport
   * @covers ::onPostImport
   * @covers ::onPreRowSave
   * @covers ::onPostRowSave
   */
  public function testRunSessionAccumulatesCountersAcrossChunks(): void {
    $migration = $this->createMockMigration('chunked_migration');

    // A batch form calls startRunSession() before the first chunk; it may
    // legitimately call it again on later chunks, which must be a no-op.
    $this->runLogger->startRunSession('chunked_migration', 'import');
    $this->runLogger->startRunSession('chunked_migration', 'import');

    // First chunk: PRE_IMPORT/POST_IMPORT around a single created row.
    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $row1 = $this->createMock(Row::class);
    $row1->method('getIdMap')->willReturn([
      'source_row_status' => MigrateIdMapInterface::STATUS_IMPORTED,
    ]);
    $this->runLogger->onPreRowSave(new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row1));
    $this->runLogger->onPostRowSave(new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row1, [1]));

    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    // Exactly one row must exist after the first chunk, and it must still
    // be 'running' — a session-backed POST_IMPORT never finalizes.
    $countAfterFirstChunk = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->condition('migration_id', 'chunked_migration')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $countAfterFirstChunk);

    $rowAfterFirstChunk = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'chunked_migration')
      ->execute()
      ->fetchAssoc();
    $this->assertEquals('running', $rowAfterFirstChunk['status']);
    $this->assertEquals(1, $rowAfterFirstChunk['items_processed']);
    $this->assertEquals(1, $rowAfterFirstChunk['items_created']);
    $this->assertEmpty($rowAfterFirstChunk['finished']);

    // Second chunk: the batch form calls startRunSession() again, which
    // must remain a no-op mid-session, then a second PRE_IMPORT/POST_IMPORT
    // pair around a second created row.
    $this->runLogger->startRunSession('chunked_migration', 'import');
    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $row2 = $this->createMock(Row::class);
    $row2->method('getIdMap')->willReturn([
      'source_row_status' => MigrateIdMapInterface::STATUS_IMPORTED,
    ]);
    $this->runLogger->onPreRowSave(new MigratePreRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row2));
    $this->runLogger->onPostRowSave(new MigratePostRowSaveEvent($migration, $this->createMock(MigrateMessageInterface::class), $row2, [2]));

    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    // Still exactly one row — a 1,000-row migration chunked into 20 calls
    // must not write 20 rows — with counters that are the SUM across both
    // chunks, and still 'running'.
    $countAfterSecondChunk = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->condition('migration_id', 'chunked_migration')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $countAfterSecondChunk);

    $rowAfterSecondChunk = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'chunked_migration')
      ->execute()
      ->fetchAssoc();
    $this->assertEquals('running', $rowAfterSecondChunk['status']);
    $this->assertEquals(2, $rowAfterSecondChunk['items_processed']);
    $this->assertEquals(2, $rowAfterSecondChunk['items_created']);
    $this->assertEmpty($rowAfterSecondChunk['finished']);

    // The batch's 'finished' callback ends the session: only now may the
    // row become terminal.
    $this->runLogger->endRunSession('chunked_migration');

    $finalCount = (int) $this->database->select('migrate_suite_run_log', 'r')
      ->condition('migration_id', 'chunked_migration')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(1, $finalCount);

    $finalRow = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'chunked_migration')
      ->execute()
      ->fetchAssoc();
    $this->assertEquals('completed', $finalRow['status']);
    $this->assertEquals(2, $finalRow['items_processed']);
    $this->assertEquals(2, $finalRow['items_created']);
    $this->assertNotEmpty($finalRow['finished']);
  }

  /**
   * @covers ::startRunSession
   * @covers ::endRunSession
   * @covers ::onMapSave
   */
  public function testRunSessionFailedRowKeepsStatusRunningUntilEnded(): void {
    $migration = $this->createMockMigration('chunked_failing_migration');

    $this->runLogger->startRunSession('chunked_failing_migration', 'import');
    $this->runLogger->onPreImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $idMap = $this->createMock(MigrateIdMapInterface::class);
    $mapSaveEvent = new MigrateMapSaveEvent($idMap, [
      'sourceid1' => 1,
      'source_row_status' => MigrateIdMapInterface::STATUS_FAILED,
    ]);
    $this->runLogger->onMapSave($mapSaveEvent);

    $this->runLogger->onPostImport(new MigrateImportEvent($migration, $this->createMock(MigrateMessageInterface::class)));

    $row = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'chunked_failing_migration')
      ->execute()
      ->fetchAssoc();
    // A session-backed row stays 'running' after a chunk, even one that
    // recorded a failure: only endRunSession() may set a terminal status.
    $this->assertEquals('running', $row['status']);
    $this->assertEquals(1, $row['items_failed']);

    $this->runLogger->endRunSession('chunked_failing_migration');

    $finalRow = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', 'chunked_failing_migration')
      ->execute()
      ->fetchAssoc();
    $this->assertEquals('failed', $finalRow['status']);
    $this->assertEquals(1, $finalRow['items_failed']);
  }

}
