<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_suite\Service\MigrateMessageQuery;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for re-running items that have a specific error message.
 */
class RerunByErrorForm extends ConfirmFormBase {

  /**
   * The migration plugin ID.
   */
  protected string $migrationId;

  /**
   * The error message text.
   */
  protected string $messageText;

  /**
   * The source IDs matching the error.
   */
  protected array $sourceIdSets = [];

  /**
   * Constructs a RerunByErrorForm.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\migrate_suite\Service\MigrateMessageQuery $messageQuery
   *   The migrate message query service.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migration table name resolver service.
   */
  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly Connection $database,
    protected readonly MigrateMessageQuery $messageQuery,
    protected readonly MigrateTableNameResolver $tableNameResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('database'),
      $container->get('migrate_suite.message_query'),
      $container->get('migrate_suite.table_name_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_rerun_by_error';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Re-run @count items with this error?', [
      '@count' => count($this->sourceIdSets),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This will reset @count items to "needs update" status and re-run the %migration migration for those items. Error: %message', [
      '@count' => count($this->sourceIdSets),
      '%migration' => $this->migrationId,
      '%message' => mb_strimwidth($this->messageText, 0, 200, '...'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('migrate_admin.migration_messages', [
      'migration_id' => $this->migrationId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $migration_id = NULL, ?Request $request = NULL): array {
    if ($migration_id === NULL) {
      throw new NotFoundHttpException();
    }

    $this->migrationId = $migration_id;
    $this->messageText = $request?->query->get('message', '') ?? '';

    if ($this->messageText === '') {
      throw new NotFoundHttpException();
    }

    $this->sourceIdSets = $this->messageQuery->getSourceIdsForMessage($migration_id, $this->messageText);

    if (empty($this->sourceIdSets)) {
      $this->messenger()->addWarning($this->t('No items found matching this error message.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return $form;
    }

    $form['message_preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Error message'),
      '#open' => TRUE,
      'text' => [
        '#markup' => '<pre>' . htmlspecialchars($this->messageText) . '</pre>',
      ],
    ];

    $form['migration_id'] = [
      '#type' => 'hidden',
      '#value' => $this->migrationId,
    ];

    $form['message_text'] = [
      '#type' => 'hidden',
      '#value' => $this->messageText,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migrationId = $form_state->getValue('migration_id');
    $messageText = $form_state->getValue('message_text');

    $sourceIdSets = $this->messageQuery->getSourceIdsForMessage($migrationId, $messageText);
    $mapTable = $this->tableNameResolver->getMapTableName($migrationId);

    if (!$this->database->schema()->tableExists($mapTable)) {
      $this->messenger()->addError($this->t('Map table does not exist for this migration.'));
      return;
    }

    // Reset matching items to "needs update" so they are re-processed.
    $resetCount = 0;
    foreach ($sourceIdSets as $sourceIds) {
      $query = $this->database->update($mapTable)
        ->fields(['source_row_status' => MigrateIdMapInterface::STATUS_NEEDS_UPDATE]);

      foreach ($sourceIds as $index => $value) {
        $query->condition('sourceid' . ($index + 1), $value);
      }

      $resetCount += $query->execute();
    }

    $this->messenger()->addStatus($this->t('Reset @count items to "needs update" status. Run the migration to re-import them.', [
      '@count' => $resetCount,
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('migrate_admin.migration_run_confirm', [
      'migration_id' => $migrationId,
    ]));
  }

}
