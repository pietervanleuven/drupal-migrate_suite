<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for resetting failed migration items.
 */
class FailedItemsResetForm extends ConfirmFormBase {

  /**
   * The migration instance.
   *
   * @var \Drupal\migrate\Plugin\MigrationInterface
   */
  protected MigrationInterface $migration;

  /**
   * The migration ID.
   *
   * @var string
   */
  protected string $migrationId;

  /**
   * Constructs a FailedItemsResetForm.
   */
  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly Connection $database,
    protected readonly AccountInterface $currentUser,
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $moduleHandler = $container->get('module_handler');
    $migrateAccessCheck = $moduleHandler->moduleExists('migrate_permissions')
      ? $container->get('migrate_permissions.access_check')
      : NULL;

    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('database'),
      $container->get('current_user'),
      $migrateAccessCheck,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_reset_failed_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to reset all failed items in the %migration migration?', [
      '%migration' => $this->migration->label() ?: $this->migrationId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('migrate_admin.migration_failed_items', ['migration_id' => $this->migrationId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $failedCount = $this->getFailedCount();
    return $this->t('This will reset @count failed items to "needs update" status so they will be retried on the next migration run.', [
      '@count' => $failedCount,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $migration_id = NULL): array {
    if ($migration_id === NULL) {
      throw new NotFoundHttpException();
    }

    $this->migrationId = $migration_id;

    try {
      $migrations = $this->migrationPluginManager->createInstances([$migration_id]);
      $this->migration = $migrations[$migration_id] ?? NULL;
    }
    catch (\Exception $e) {
      $this->migration = NULL;
    }

    if ($this->migration === NULL) {
      throw new NotFoundHttpException();
    }

    // Check run permission (reset requires run permission).
    $this->checkResetAccess();

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mapTable = 'migrate_map_' . $this->migrationId;

    if ($this->database->schema()->tableExists($mapTable)) {
      $updated = $this->database->update($mapTable)
        ->fields(['source_row_status' => 0])
        ->condition('source_row_status', 2)
        ->execute();

      $this->messenger()->addStatus($this->t('Reset @count failed items to "imported" status for the %migration migration.', [
        '@count' => $updated,
        '%migration' => $this->migration->label() ?: $this->migrationId,
      ]));

      $this->logger('migrate_suite')->notice('Reset @count failed items for migration %migration.', [
        '@count' => $updated,
        '%migration' => $this->migrationId,
      ]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Checks if the current user has permission to reset failed items.
   */
  protected function checkResetAccess(): void {
    if ($this->migrateAccessCheck !== NULL) {
      if (!$this->migrateAccessCheck->canRunMigration($this->currentUser, $this->migrationId)) {
        throw new AccessDeniedHttpException();
      }
    }
    elseif (!$this->currentUser->hasPermission('administer migrations') && !$this->currentUser->hasPermission('administer site configuration')) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Gets the count of failed items.
   *
   * @return int
   *   The number of failed items.
   */
  protected function getFailedCount(): int {
    $mapTable = 'migrate_map_' . $this->migrationId;
    if (!$this->database->schema()->tableExists($mapTable)) {
      return 0;
    }

    return (int) $this->database->select($mapTable, 'map')
      ->condition('source_row_status', 2)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
