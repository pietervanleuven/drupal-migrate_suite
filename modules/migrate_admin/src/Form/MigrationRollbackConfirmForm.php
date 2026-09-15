<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Form;

use Drupal\migrate\MigrateMessage;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_admin\MigrateBatchExecutable;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Drupal\migrate_suite\Service\MigrateMapQuery;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for rolling back a migration.
 */
class MigrationRollbackConfirmForm extends ConfirmFormBase {

  /**
   * Number of ID map rows rolled back per batch operation invocation.
   *
   * Chosen to keep a single invocation well under typical
   * max_execution_time/memory limits even when each row triggers a full
   * entity delete (field cleanup, hook invocations, cache invalidation),
   * while still keeping the batch progress bar moving in useful increments.
   */
  public const ITEMS_PER_BATCH = 50;

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
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\migrate_suite\Service\MigrateMapQuery $mapQuery
   *   The migrate map query service.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migration table name resolver service.
   * @param \Drupal\migrate_permissions\MigrateAccessCheck|null $migrateAccessCheck
   *   The access check, or NULL if migrate_permissions is not installed.
   */
  public function __construct(
    protected MigrationPluginManagerInterface $migrationPluginManager,
    protected Connection $database,
    protected AccountInterface $currentUser,
    protected MigrateMapQuery $mapQuery,
    protected MigrateTableNameResolver $tableNameResolver,
    protected ?MigrateAccessCheck $migrateAccessCheck,
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
      $container->get('migrate_suite.map_query'),
      $container->get('migrate_suite.table_name_resolver'),
      $migrateAccessCheck,
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
   * Drupal's Batch API calls this operation repeatedly, persisting state in
   * $context['sandbox'] between calls, until $context['finished'] reaches 1.
   * Each call rolls back at most static::ITEMS_PER_BATCH ID map rows (see
   * \Drupal\migrate_admin\MigrateBatchExecutable) so that a single request
   * never has to process an entire large migration.
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
      $context['finished'] = 1;
      return;
    }

    if ($migration === NULL) {
      $context['results']['errors'][] = t('Migration @id not found.', ['@id' => $migrationId]);
      $context['finished'] = 1;
      return;
    }

    // Record the migration ID before starting the session, not inside the
    // first-chunk block below. batchFinished() needs it to end the session,
    // and anything between here and there can throw: if a session existed
    // without batchFinished() knowing which migration to end, the session
    // would leak, and the NEXT rollback of this migration would resume into
    // this run's log row and accumulate its counters onto it.
    $context['results']['migration_id'] = $migrationId;

    // Mark that every PRE_ROLLBACK/POST_ROLLBACK pair MigrateRunLogger sees
    // for this migration, across however many chunks it takes, belongs to
    // one logical run. Safe to call on every chunk: startRunSession() is a
    // no-op once a session is already active. static::batchRollback() has
    // no container access of its own, so this legitimately goes through
    // the \Drupal facade.
    \Drupal::service('migrate_suite.run_logger')->startRunSession($migrationId, 'rollback');

    if (!isset($context['sandbox']['total'])) {
      // The rollback loop iterates every row currently in the ID map
      // (regardless of status), so that is the correct denominator for
      // progress reporting — unlike import(), this is always a real count.
      $total = 0;
      try {
        $total = $migration->getIdMap()->processedCount();
      }
      catch (\Throwable $e) {
        // An ID map plugin can raise a PHP Error, not just an Exception --
        // the same hazard DeltaDetectionService was hardened against. A
        // failed count is not a reason to abort the rollback.
        $total = 0;
      }

      $context['sandbox']['total'] = $total;
      $context['sandbox']['processed'] = 0;
      $context['results']['migration_label'] = $migration->label() ?: $migrationId;
      $context['results']['rolled_back'] = 0;
    }

    $executable = new MigrateBatchExecutable($migration, new MigrateMessage(), static::ITEMS_PER_BATCH);
    $status = $executable->rollback();
    $processedThisRun = $executable->getItemsProcessed();

    $context['sandbox']['processed'] += $processedThisRun;
    $context['results']['rolled_back'] = $context['sandbox']['processed'];
    $context['results']['status'] = $status;

    $total = $context['sandbox']['total'];
    if ($total > 0) {
      $context['message'] = t('Rolled back @processed of @total items.', [
        '@processed' => $context['sandbox']['processed'],
        '@total' => $total,
      ]);
    }
    else {
      $context['message'] = t('Rolled back @processed items so far.', [
        '@processed' => $context['sandbox']['processed'],
      ]);
    }

    // A terminal status always ends the batch, whether or not it is the
    // "successful" one — RESULT_FAILED/RESULT_STOPPED must stop the loop
    // just as much as RESULT_COMPLETED does.
    $terminalStatuses = [
      MigrationInterface::RESULT_COMPLETED,
      MigrationInterface::RESULT_STOPPED,
      MigrationInterface::RESULT_FAILED,
    ];
    if (in_array($status, $terminalStatuses, TRUE)) {
      $context['finished'] = 1;
      return;
    }

    // Guarantee termination: a chunk that processed nothing and did not
    // report completion cannot be trusted to ever finish if we keep
    // re-running it, so treat it as done instead of looping forever.
    if ($processedThisRun === 0) {
      $context['finished'] = 1;
      return;
    }

    if ($total > 0) {
      // Cap below 1 so the progress bar cannot report 100% before the
      // executable itself reports a terminal status.
      $context['finished'] = min($context['sandbox']['processed'] / $total, 0.99);
    }
    else {
      // Unknown/zero total: completion is driven entirely by the
      // terminal-status check above.
      $context['finished'] = 0;
    }
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
    // Finalize the run session on every path — success, failure, and abort
    // (Drupal calls this callback with $success = FALSE and $results
    // containing whatever was gathered before an operation failed) — so a
    // run log row is never left stuck 'running'. endRunSession() is a
    // no-op if no session was ever started (e.g. the migration lookup
    // failed before startRunSession() was reached). This has no container
    // access of its own, so it legitimately goes through the \Drupal
    // facade.
    if (isset($results['migration_id']) && is_string($results['migration_id'])) {
      \Drupal::service('migrate_suite.run_logger')->endRunSession($results['migration_id']);
    }

    if (!empty($results['errors'])) {
      foreach ($results['errors'] as $error) {
        \Drupal::messenger()->addError($error);
      }
      return;
    }

    if ($success && isset($results['status'])) {
      $label = $results['migration_label'] ?? $results['migration_id'] ?? 'Unknown';
      $rolledBack = $results['rolled_back'] ?? 0;

      if ($results['status'] === MigrationInterface::RESULT_COMPLETED) {
        \Drupal::messenger()->addStatus(\Drupal::translation()->formatPlural(
          $rolledBack,
          'Migration %migration rollback completed successfully. One item rolled back.',
          'Migration %migration rollback completed successfully. @count items rolled back.',
          ['%migration' => $label]
        ));
      }
      else {
        \Drupal::messenger()->addWarning(t('Migration %migration rollback stopped after @count items with status: @status.', [
          '%migration' => $label,
          '@count' => $rolledBack,
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
    $mapTable = $this->tableNameResolver->getMapTableName($this->migrationId);
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
        $mapTable = $this->tableNameResolver->getMapTableName($otherId);
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
