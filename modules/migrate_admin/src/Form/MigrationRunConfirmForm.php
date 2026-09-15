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
use Drupal\migrate_suite\Service\DeltaDetectionService;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for running a migration import.
 */
class MigrationRunConfirmForm extends ConfirmFormBase {

  /**
   * Number of source rows imported per batch operation invocation.
   *
   * Chosen to keep a single invocation well under typical
   * max_execution_time/memory limits even when each row triggers a full
   * entity save (field processing, hook invocations, cache invalidation),
   * while still keeping the batch progress bar moving in useful increments.
   */
  public const ITEMS_PER_BATCH = 50;

  /**
   * The migration to run.
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
   * Constructs a MigrationRunConfirmForm.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migration table name resolver service.
   * @param \Drupal\migrate_permissions\MigrateAccessCheck|null $migrateAccessCheck
   *   The access check, or NULL if migrate_permissions is not installed.
   * @param \Drupal\migrate_suite\Service\DeltaDetectionService|null $deltaDetection
   *   The delta detection service, or NULL if it is unavailable.
   */
  public function __construct(
    protected MigrationPluginManagerInterface $migrationPluginManager,
    protected Connection $database,
    protected AccountInterface $currentUser,
    protected MigrateTableNameResolver $tableNameResolver,
    protected ?MigrateAccessCheck $migrateAccessCheck,
    protected ?DeltaDetectionService $deltaDetection,
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
      $container->get('migrate_suite.table_name_resolver'),
      $migrateAccessCheck,
      $container->get('migrate_suite.delta_detection'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_run_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to run the %migration migration?', [
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
    $sourceCount = $this->getSourceCount();
    $description = $this->t('This will import items from the %migration migration.', [
      '%migration' => $this->migration->label() ?: $this->migrationId,
    ]);

    if ($sourceCount !== NULL) {
      $description = $this->t('This will import up to @count items from the %migration migration.', [
        '@count' => $sourceCount,
        '%migration' => $this->migration->label() ?: $this->migrationId,
      ]);
    }

    return $description;
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

    // Check run permission.
    $this->checkRunAccess();

    // Check migration dependencies.
    $dependencyWarnings = $this->checkDependencies();
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

    // Delta detection info.
    if ($this->deltaDetection !== NULL) {
      $delta = $this->deltaDetection->detectDelta($migration_id);

      if (!$delta['has_changes'] && $delta['current_hash'] !== NULL) {
        $form['delta_info'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--status']],
          '#markup' => $this->t('No source changes detected since the last run. The source data appears unchanged.'),
        ];
      }
      elseif ($delta['has_changes'] && $delta['previous_hash'] !== NULL) {
        $parts = [$this->t('Source changes detected since last run.')];
        if ($delta['previous_count'] !== NULL && $delta['current_count'] !== NULL) {
          $diff = $delta['current_count'] - $delta['previous_count'];
          if ($diff > 0) {
            $parts[] = $this->t('@count new source items.', ['@count' => $diff]);
          }
          elseif ($diff < 0) {
            $parts[] = $this->t('@count fewer source items.', ['@count' => abs($diff)]);
          }
        }
        $form['delta_info'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--warning']],
          '#markup' => implode(' ', $parts),
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migration = $this->migration;
    $migrationId = $this->migrationId;

    $batch = [
      'title' => $this->t('Running migration: @migration', [
        '@migration' => $migration->label() ?: $migrationId,
      ]),
      'operations' => [
        [[static::class, 'batchImport'], [$migrationId]],
      ],
      'finished' => [static::class, 'batchFinished'],
    ];

    batch_set($batch);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Batch operation callback for importing a migration.
   *
   * Drupal's Batch API calls this operation repeatedly, persisting state in
   * $context['sandbox'] between calls, until $context['finished'] reaches 1.
   * Each call imports at most static::ITEMS_PER_BATCH rows (see
   * \Drupal\migrate_admin\MigrateBatchExecutable) so that a single request
   * never has to process an entire large migration.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param array $context
   *   The batch context.
   */
  public static function batchImport(string $migrationId, array &$context): void {
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
    // would leak, and the NEXT run of this migration would resume into this
    // run's log row and accumulate its counters onto it.
    $context['results']['migration_id'] = $migrationId;

    // Mark that every PRE_IMPORT/POST_IMPORT pair MigrateRunLogger sees for
    // this migration, across however many chunks it takes, belongs to one
    // logical run. Safe to call on every chunk: startRunSession() is a
    // no-op once a session is already active. static::batchImport() has no
    // container access of its own, so this legitimately goes through the
    // \Drupal facade.
    \Drupal::service('migrate_suite.run_logger')->startRunSession($migrationId, 'import');

    if (!isset($context['sandbox']['total'])) {
      $total = -1;
      try {
        $total = $migration->getSourcePlugin()->count();
      }
      catch (\Throwable $e) {
        // A source plugin can raise a PHP Error, not just an Exception --
        // the same hazard DeltaDetectionService was hardened against. An
        // uncountable source is not a reason to abort the run.
        $total = -1;
      }

      // A source can legitimately report -1 (unknown/uncountable); track
      // that explicitly rather than treating it as zero.
      $context['sandbox']['total'] = $total;
      $context['sandbox']['processed'] = 0;
      $context['results']['migration_label'] = $migration->label() ?: $migrationId;
      $context['results']['imported'] = 0;
    }

    $executable = new MigrateBatchExecutable($migration, new MigrateMessage(), static::ITEMS_PER_BATCH);
    $status = $executable->import();
    $processedThisRun = $executable->getItemsProcessed();

    $context['sandbox']['processed'] += $processedThisRun;
    $context['results']['imported'] = $context['sandbox']['processed'];
    $context['results']['status'] = $status;

    $total = $context['sandbox']['total'];
    if ($total > 0) {
      $context['message'] = t('Imported @processed of @total items.', [
        '@processed' => $context['sandbox']['processed'],
        '@total' => $total,
      ]);
    }
    else {
      $context['message'] = t('Imported @processed items so far.', [
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
      // Unknown total: there is nothing to compute a fraction from, so
      // completion is driven entirely by the terminal-status check above.
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
      $imported = $results['imported'] ?? 0;

      if ($results['status'] === MigrationInterface::RESULT_COMPLETED) {
        \Drupal::messenger()->addStatus(\Drupal::translation()->formatPlural(
          $imported,
          'Migration %migration completed successfully. One item imported.',
          'Migration %migration completed successfully. @count items imported.',
          ['%migration' => $label]
        ));
      }
      else {
        \Drupal::messenger()->addWarning(t('Migration %migration stopped after @count items with status: @status.', [
          '%migration' => $label,
          '@count' => $imported,
          '@status' => $results['status'],
        ]));
      }
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred during the migration.'));
    }
  }

  /**
   * Checks if the current user has permission to run this migration.
   */
  protected function checkRunAccess(): void {
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
   * Gets the source item count for this migration.
   *
   * @return int|null
   *   The source count, or NULL if unavailable.
   */
  protected function getSourceCount(): ?int {
    try {
      $source = $this->migration->getSourcePlugin();
      $count = $source->count();
      return $count === -1 ? NULL : $count;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Checks migration dependencies and returns warnings for unmet ones.
   *
   * @return array
   *   Array of warning message strings.
   */
  protected function checkDependencies(): array {
    $warnings = [];
    $definition = $this->migration->getPluginDefinition();
    $requirements = $definition['migration_dependencies']['required'] ?? [];

    if (empty($requirements)) {
      return $warnings;
    }

    foreach ($requirements as $requiredId) {
      try {
        $migrations = $this->migrationPluginManager->createInstances([$requiredId]);
        $requiredMigration = $migrations[$requiredId] ?? NULL;
      }
      catch (\Exception $e) {
        $warnings[] = $this->t('Required migration %id could not be loaded.', ['%id' => $requiredId]);
        continue;
      }

      if ($requiredMigration === NULL) {
        $warnings[] = $this->t('Required migration %id does not exist.', ['%id' => $requiredId]);
        continue;
      }

      // Check if the required migration has been run.
      $mapTable = $this->tableNameResolver->getMapTableName($requiredId);
      if (!$this->database->schema()->tableExists($mapTable)) {
        $label = $requiredMigration->label() ?: $requiredId;
        $warnings[] = $this->t('Dependent migration %migration has not been run yet.', [
          '%migration' => $label,
        ]);
        continue;
      }

      $importedCount = $this->database->select($mapTable, 'map')
        ->condition('source_row_status', 0)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ((int) $importedCount === 0) {
        $label = $requiredMigration->label() ?: $requiredId;
        $warnings[] = $this->t('Dependent migration %migration has no imported items.', [
          '%migration' => $label,
        ]);
      }
    }

    return $warnings;
  }

}
