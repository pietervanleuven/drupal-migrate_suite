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
use Drupal\migrate_suite\Service\MigrateMapQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for rolling back a migration.
 */
class MigrationRollbackConfirmForm extends ConfirmFormBase {

  /**
   * The migration to rollback.
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
   * Constructs a MigrationRollbackConfirmForm.
   */
  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly Connection $database,
    protected readonly AccountInterface $currentUser,
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
    protected readonly MigrateMapQuery $mapQuery,
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
      $container->get('migrate_suite.map_query'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_rollback_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to rollback the %migration migration?', [
      '%migration' => $this->migration->label() ?: $this->migrationId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('migrate_admin.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    $importedCount = $this->getImportedCount();

    if ($importedCount !== NULL) {
      return $this->t('This will rollback @count imported items from the %migration migration. This action cannot be undone.', [
        '@count' => $importedCount,
        '%migration' => $this->migration->label() ?: $this->migrationId,
      ]);
    }

    return $this->t('This will rollback all imported items from the %migration migration. This action cannot be undone.', [
      '%migration' => $this->migration->label() ?: $this->migrationId,
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

    // Check rollback permission.
    $this->checkRollbackAccess();

    // Check if dependent migrations exist.
    $dependencyWarnings = $this->checkDependents();
    if (!empty($dependencyWarnings)) {
      $form['dependency_warnings'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        'heading' => [
          '#markup' => '<h3>' . $this->t('Dependency warnings') . '</h3>',
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $dependencyWarnings,
        ],
      ];
    }

    // Add rollback preview showing entities that will be affected.
    $preview = $this->buildRollbackPreview();
    if ($preview) {
      $form['rollback_preview'] = $preview;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds a preview table of entities that will be rolled back.
   *
   * @return array|null
   *   A render array, or NULL if no preview available.
   */
  protected function buildRollbackPreview(): ?array {
    $previewLimit = 20;
    $totalCount = $this->mapQuery->countImportedItems($this->migrationId);

    if ($totalCount === 0) {
      return NULL;
    }

    $items = $this->mapQuery->listRollbackPreview($this->migrationId, $previewLimit);

    $rows = [];
    foreach ($items as $item) {
      $sourceIds = [];
      foreach ((array) $item as $key => $value) {
        if (str_starts_with($key, 'sourceid') && $value !== NULL) {
          $sourceIds[] = $value;
        }
      }

      $destIds = [];
      foreach ((array) $item as $key => $value) {
        if (str_starts_with($key, 'destid') && $value !== NULL) {
          $destIds[] = $value;
        }
      }

      $rows[] = [
        implode(', ', $sourceIds),
        implode(', ', $destIds),
      ];
    }

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Items to be rolled back (@count total)', ['@count' => $totalCount]),
      '#open' => $totalCount <= $previewLimit,
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Source ID'),
          $this->t('Destination ID'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No imported items found.'),
      ],
    ];

    if ($totalCount > $previewLimit) {
      $build['more'] = [
        '#markup' => '<p>' . $this->t('... and @count more items.', [
          '@count' => $totalCount - $previewLimit,
        ]) . '</p>',
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migration = $this->migration;
    $migrationId = $this->migrationId;

    $batch = [
      'title' => $this->t('Rolling back migration: @migration', [
        '@migration' => $migration->label() ?: $migrationId,
      ]),
      'operations' => [
        [[static::class, 'batchRollback'], [$migrationId]],
      ],
      'finished' => [static::class, 'batchFinished'],
    ];

    batch_set($batch);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Batch operation callback for rolling back a migration.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param array $context
   *   The batch context.
   */
  public static function batchRollback(string $migrationId, array &$context): void {
    $migrationPluginManager = \Drupal::service('plugin.manager.migration');

    try {
      $migrations = $migrationPluginManager->createInstances([$migrationId]);
      $migration = $migrations[$migrationId] ?? NULL;
    }
    catch (\Exception $e) {
      $context['results']['errors'][] = $e->getMessage();
      return;
    }

    if ($migration === NULL) {
      $context['results']['errors'][] = t('Migration @id not found.', ['@id' => $migrationId]);
      return;
    }

    $executable = new \Drupal\migrate\MigrateExecutable($migration, new \Drupal\migrate\MigrateMessage());
    $result = $executable->rollback();

    $context['results']['migration_id'] = $migrationId;
    $context['results']['migration_label'] = $migration->label() ?: $migrationId;
    $context['results']['status'] = $result;
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The remaining operations.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        \Drupal::messenger()->addError($error);
      }
      return;
    }

    if ($success && isset($results['status'])) {
      $label = $results['migration_label'] ?? $results['migration_id'] ?? 'Unknown';

      if ($results['status'] === MigrationInterface::RESULT_COMPLETED) {
        \Drupal::messenger()->addStatus(t('Migration %migration rollback completed successfully.', [
          '%migration' => $label,
        ]));
      }
      else {
        \Drupal::messenger()->addWarning(t('Migration %migration rollback finished with status: @status.', [
          '%migration' => $label,
          '@status' => $results['status'],
        ]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during the migration rollback.'));
    }
  }

  /**
   * Checks if the current user has permission to rollback this migration.
   */
  protected function checkRollbackAccess(): void {
    if ($this->migrateAccessCheck !== NULL) {
      if (!$this->migrateAccessCheck->canRollbackMigration($this->currentUser, $this->migrationId)) {
        throw new AccessDeniedHttpException();
      }
    }
    elseif (!$this->currentUser->hasPermission('administer migrations') && !$this->currentUser->hasPermission('administer site configuration')) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Gets the imported item count for this migration.
   *
   * @return int|null
   *   The imported count, or NULL if map table doesn't exist.
   */
  protected function getImportedCount(): ?int {
    $mapTable = 'migrate_map_' . $this->migrationId;
    if (!$this->database->schema()->tableExists($mapTable)) {
      return NULL;
    }

    return (int) $this->database->select($mapTable, 'map')
      ->condition('source_row_status', 0)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Checks for migrations that depend on this one.
   *
   * @return array
   *   Array of warning message strings.
   */
  protected function checkDependents(): array {
    $warnings = [];

    try {
      $allMigrations = $this->migrationPluginManager->createInstances([]);
    }
    catch (\Exception $e) {
      return $warnings;
    }

    foreach ($allMigrations as $otherId => $otherMigration) {
      if ($otherId === $this->migrationId) {
        continue;
      }

      $definition = $otherMigration->getPluginDefinition();
      $requirements = $definition['migration_dependencies']['required'] ?? [];

      if (in_array($this->migrationId, $requirements, TRUE)) {
        $mapTable = 'migrate_map_' . $otherId;
        if ($this->database->schema()->tableExists($mapTable)) {
          $importedCount = (int) $this->database->select($mapTable, 'map')
            ->condition('source_row_status', 0)
            ->countQuery()
            ->execute()
            ->fetchField();

          if ($importedCount > 0) {
            $label = $otherMigration->label() ?: $otherId;
            $warnings[] = $this->t('Migration %migration depends on this migration and has @count imported items.', [
              '%migration' => $label,
              '@count' => $importedCount,
            ]);
          }
        }
      }
    }

    return $warnings;
  }

}
