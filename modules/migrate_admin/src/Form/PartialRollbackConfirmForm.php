<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_suite\Service\MigrateMapQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for partial rollback of selected items.
 */
class PartialRollbackConfirmForm extends ConfirmFormBase {

  /**
   * The migration plugin ID.
   */
  protected string $migrationId;

  /**
   * The selected source ID sets.
   */
  protected array $sourceIdSets = [];

  public function __construct(
    protected readonly MigrateMapQuery $mapQuery,
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('migrate_suite.map_query'),
      $container->get('plugin.manager.migration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_partial_rollback_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to rollback @count selected items?', [
      '@count' => count($this->sourceIdSets),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('migrate_admin.migration_detail', [
      'migration_id' => $this->migrationId,
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

    $tempstore = \Drupal::service('tempstore.private')->get('migrate_admin');
    $this->sourceIdSets = $tempstore->get('partial_rollback_' . $migration_id) ?? [];

    if (empty($this->sourceIdSets)) {
      $this->messenger()->addWarning($this->t('No items selected for rollback.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migrationId = $this->migrationId;

    // Get destination entity type from migration definition.
    try {
      $migrations = $this->migrationPluginManager->createInstances([$migrationId]);
      $migration = $migrations[$migrationId] ?? NULL;
    }
    catch (\Exception $e) {
      $migration = NULL;
    }

    $entityTypeId = NULL;
    if ($migration) {
      $definition = $migration->getPluginDefinition();
      $destPlugin = $definition['destination']['plugin'] ?? '';
      if (str_starts_with($destPlugin, 'entity:')) {
        $entityTypeId = substr($destPlugin, 7);
      }
    }

    // Get map rows to find destination IDs.
    $mapRows = $this->mapQuery->getMapRowsBySourceIds($migrationId, $this->sourceIdSets);

    $deletedEntities = 0;
    foreach ($mapRows as $row) {
      if ($entityTypeId && !empty($row->destid1)) {
        try {
          $entity = $this->entityTypeManager->getStorage($entityTypeId)->load($row->destid1);
          if ($entity) {
            $entity->delete();
            $deletedEntities++;
          }
        }
        catch (\Exception $e) {
          $this->messenger()->addWarning($this->t('Could not delete entity @id: @error', [
            '@id' => $row->destid1,
            '@error' => $e->getMessage(),
          ]));
        }
      }
    }

    // Delete map rows.
    $deletedRows = $this->mapQuery->deleteMapRows($migrationId, $this->sourceIdSets);

    // Clear tempstore.
    $tempstore = \Drupal::service('tempstore.private')->get('migrate_admin');
    $tempstore->delete('partial_rollback_' . $migrationId);

    $this->messenger()->addStatus($this->t('Partially rolled back @entities entities and @rows map entries.', [
      '@entities' => $deletedEntities,
      '@rows' => $deletedRows,
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
