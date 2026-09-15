<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_permissions\Unit;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_permissions\MigratePermissions;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\migrate_permissions\MigratePermissions
 * @group migrate_suite
 * @group migrate_permissions
 */
class MigratePermissionsTest extends UnitTestCase {

  /**
   * @covers ::permissions
   */
  public function testPermissionsGeneratesThreePerMigration(): void {
    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('label')->willReturn('Articles');

    $manager = $this->createMock(MigrationPluginManagerInterface::class);
    $manager->method('createInstances')
      ->with([])
      ->willReturn(['my_articles' => $migration]);

    $permissions = (new MigratePermissions($manager))->permissions();

    $this->assertArrayHasKey('view migration my_articles', $permissions);
    $this->assertArrayHasKey('run migration my_articles', $permissions);
    $this->assertArrayHasKey('rollback migration my_articles', $permissions);
    $this->assertCount(3, $permissions);
  }

  /**
   * @covers ::permissions
   */
  public function testPermissionsNormalizesMigrationId(): void {
    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('label')->willReturn('Group Articles');

    $manager = $this->createMock(MigrationPluginManagerInterface::class);
    $manager->method('createInstances')
      ->with([])
      ->willReturn(['My.Group.Articles' => $migration]);

    $permissions = (new MigratePermissions($manager))->permissions();

    $this->assertArrayHasKey('view migration my_group_articles', $permissions);
    $this->assertArrayHasKey('run migration my_group_articles', $permissions);
    $this->assertArrayHasKey('rollback migration my_group_articles', $permissions);
  }

  /**
   * @covers ::permissions
   */
  public function testPermissionsReturnsEmptyOnException(): void {
    $manager = $this->createMock(MigrationPluginManagerInterface::class);
    $manager->method('createInstances')
      ->willThrowException(new \Exception('Plugin error'));

    $permissions = (new MigratePermissions($manager))->permissions();

    $this->assertEmpty($permissions);
  }

  /**
   * @covers ::permissions
   */
  public function testPermissionsUsesIdAsLabelFallback(): void {
    $migration = $this->createMock(MigrationInterface::class);
    $migration->method('label')->willReturn('');

    $manager = $this->createMock(MigrationPluginManagerInterface::class);
    $manager->method('createInstances')
      ->with([])
      ->willReturn(['my_articles' => $migration]);

    $permissions = (new MigratePermissions($manager))->permissions();

    // When label is empty, the migration ID is used in the title.
    $title = (string) $permissions['view migration my_articles']['title'];
    $this->assertStringContainsString('my_articles', $title);
  }

}
