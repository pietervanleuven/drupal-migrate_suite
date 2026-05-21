<?php

declare(strict_types=1);

namespace Drupal\Tests\migrate_permissions\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\migrate_permissions\MigrateAccessCheck
 * @group migrate_permissions
 */
class MigrateAccessCheckTest extends UnitTestCase {

  /**
   * The access check service under test.
   */
  protected MigrateAccessCheck $accessCheck;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->accessCheck = new MigrateAccessCheck();
  }

  /**
   * Creates a mock account with the given permissions.
   *
   * @param array $permissions
   *   The permissions the account should have.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The mocked account.
   */
  protected function createMockAccount(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->willReturnCallback(fn(string $permission) => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * @covers ::canViewMigration
   */
  public function testCanViewMigrationWithSpecificPermission(): void {
    $account = $this->createMockAccount(['view migration my_articles']);
    $this->assertTrue($this->accessCheck->canViewMigration($account, 'my_articles'));
  }

  /**
   * @covers ::canViewMigration
   */
  public function testCanViewMigrationDeniedWithoutPermission(): void {
    $account = $this->createMockAccount([]);
    $this->assertFalse($this->accessCheck->canViewMigration($account, 'my_articles'));
  }

  /**
   * @covers ::canViewMigration
   */
  public function testCanViewMigrationWithAdminBypass(): void {
    $account = $this->createMockAccount(['administer migrations']);
    $this->assertTrue($this->accessCheck->canViewMigration($account, 'any_migration'));

    $account2 = $this->createMockAccount(['administer site configuration']);
    $this->assertTrue($this->accessCheck->canViewMigration($account2, 'any_migration'));
  }

  /**
   * @covers ::canRunMigration
   */
  public function testCanRunMigrationWithSpecificPermission(): void {
    $account = $this->createMockAccount(['run migration my_articles']);
    $this->assertTrue($this->accessCheck->canRunMigration($account, 'my_articles'));
  }

  /**
   * @covers ::canRunMigration
   */
  public function testCanRunMigrationDeniedWithoutPermission(): void {
    $account = $this->createMockAccount(['view migration my_articles']);
    $this->assertFalse($this->accessCheck->canRunMigration($account, 'my_articles'));
  }

  /**
   * @covers ::canRollbackMigration
   */
  public function testCanRollbackMigrationWithSpecificPermission(): void {
    $account = $this->createMockAccount(['rollback migration my_articles']);
    $this->assertTrue($this->accessCheck->canRollbackMigration($account, 'my_articles'));
  }

  /**
   * @covers ::canRollbackMigration
   */
  public function testCanRollbackMigrationDeniedWithoutPermission(): void {
    $account = $this->createMockAccount([]);
    $this->assertFalse($this->accessCheck->canRollbackMigration($account, 'my_articles'));
  }

  /**
   * Tests that migration IDs are normalized to safe permission strings.
   *
   * @covers ::canViewMigration
   */
  public function testMigrationIdNormalization(): void {
    // Dots and uppercase should be normalized to underscores/lowercase.
    $account = $this->createMockAccount(['view migration my_group_articles']);
    $this->assertTrue($this->accessCheck->canViewMigration($account, 'my_group.articles'));
    $this->assertTrue($this->accessCheck->canViewMigration($account, 'My_Group.Articles'));
  }

}
