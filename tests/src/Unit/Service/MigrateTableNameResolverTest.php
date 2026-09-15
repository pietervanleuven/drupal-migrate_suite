<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_suite\Unit\Service;

use Drupal\Core\Database\Connection;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\migrate_suite\Service\MigrateTableNameResolver
 * @group migrate_suite
 */
class MigrateTableNameResolverTest extends UnitTestCase {

  /**
   * Creates a resolver backed by a mocked connection reporting $prefix.
   *
   * @param string $prefix
   *   The database table prefix the mocked connection should report.
   *
   * @return \Drupal\migrate_suite\Service\MigrateTableNameResolver
   *   The resolver under test.
   */
  protected function createResolver(string $prefix = ''): MigrateTableNameResolver {
    $database = $this->createMock(Connection::class);
    $database->method('getPrefix')->willReturn($prefix);
    return new MigrateTableNameResolver($database);
  }

  /**
   * Tests plain, derived and mixed-case migration IDs.
   *
   * @covers ::getMapTableName
   * @covers ::getMessageTableName
   * @dataProvider providerTableNames
   */
  public function testTableNames(string $migrationId, string $expectedMapTable, string $expectedMessageTable): void {
    $resolver = $this->createResolver();
    $this->assertSame($expectedMapTable, $resolver->getMapTableName($migrationId));
    $this->assertSame($expectedMessageTable, $resolver->getMessageTableName($migrationId));
  }

  /**
   * Data provider for testTableNames().
   *
   * Expected values mirror what core's
   * \Drupal\migrate\Plugin\migrate\id_map\Sql::__construct() derives for
   * each migration ID.
   *
   * @return array
   *   Test cases keyed by description.
   */
  public static function providerTableNames(): array {
    return [
      'plain id' => [
        'articles',
        'migrate_map_articles',
        'migrate_message_articles',
      ],
      // Derived migration IDs contain a colon, which core replaces with a
      // double underscore. This is the regression case: naive concatenation
      // previously produced "migrate_map_d7_node:article", a table that
      // never exists.
      'derived id with a single colon' => [
        'd7_node:article',
        'migrate_map_d7_node__article',
        'migrate_message_d7_node__article',
      ],
      'derived id with multiple colons' => [
        'd7_field_instance:node:article',
        'migrate_map_d7_field_instance__node__article',
        'migrate_message_d7_field_instance__node__article',
      ],
      'mixed-case id is lowercased' => [
        'My_Migration',
        'migrate_map_my_migration',
        'migrate_message_my_migration',
      ],
    ];
  }

  /**
   * Tests that a long migration ID is truncated to exactly 63 characters.
   *
   * @covers ::getMapTableName
   * @covers ::getMessageTableName
   */
  public function testLongIdIsTruncatedToSixtyThreeCharacters(): void {
    $resolver = $this->createResolver();
    // With no database prefix, core's cap is the full 63 characters.
    $migrationId = str_repeat('x', 60);

    $mapTable = $resolver->getMapTableName($migrationId);
    $messageTable = $resolver->getMessageTableName($migrationId);

    $this->assertSame(63, strlen($mapTable));
    $this->assertSame(63, strlen($messageTable));
    $this->assertSame(mb_substr('migrate_map_' . $migrationId, 0, 63), $mapTable);
    $this->assertSame(mb_substr('migrate_message_' . $migrationId, 0, 63), $messageTable);
  }

  /**
   * Tests truncation with a non-empty database table prefix.
   *
   * Core subtracts strlen($this->database->getPrefix()) from the 63
   * character cap before truncating, so a prefixed site has less room for
   * the migration ID portion of the table name.
   *
   * @covers ::getMapTableName
   * @covers ::getMessageTableName
   */
  public function testTruncationAccountsForNonEmptyDatabasePrefix(): void {
    $prefix = 'drupal_';
    $resolver = $this->createResolver($prefix);
    $migrationId = str_repeat('x', 60);
    $cap = 63 - strlen($prefix);

    $mapTable = $resolver->getMapTableName($migrationId);
    $messageTable = $resolver->getMessageTableName($migrationId);

    $this->assertSame(56, $cap);
    $this->assertSame($cap, strlen($mapTable));
    $this->assertSame($cap, strlen($messageTable));
    $this->assertSame(mb_substr('migrate_map_' . $migrationId, 0, $cap), $mapTable);
    $this->assertSame(mb_substr('migrate_message_' . $migrationId, 0, $cap), $messageTable);
  }

}
