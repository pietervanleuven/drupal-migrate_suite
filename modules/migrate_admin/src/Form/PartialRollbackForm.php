<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\migrate_suite\Service\MigrateMapQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Form for selecting imported items to partially rollback.
 */
class PartialRollbackForm extends FormBase {

  /**
   * The migration plugin ID.
   */
  protected string $migrationId;

  /**
   * Constructs a PartialRollbackForm object.
   *
   * @param \Drupal\migrate_suite\Service\MigrateMapQuery $mapQuery
   *   The migrate map query service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public function __construct(
    protected readonly MigrateMapQuery $mapQuery,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('migrate_suite.map_query'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_admin_partial_rollback';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $migration_id = NULL): array {
    if ($migration_id === NULL) {
      throw new NotFoundHttpException();
    }

    $this->migrationId = $migration_id;

    $items = $this->mapQuery->listRollbackPreview($migration_id, 100);

    if (empty($items)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No imported items found for this migration.') . '</p>',
      ];
      return $form;
    }

    $options = [];
    foreach ($items as $item) {
      $sourceIds = [];
      $destIds = [];
      foreach ((array) $item as $key => $value) {
        if (str_starts_with($key, 'sourceid') && $value !== NULL) {
          $sourceIds[] = $value;
        }
        if (str_starts_with($key, 'destid') && $value !== NULL) {
          $destIds[] = $value;
        }
      }

      $key = implode('|', $sourceIds);
      $options[$key] = [
        'source_id' => implode(', ', $sourceIds),
        'dest_id' => implode(', ', $destIds),
      ];
    }

    $form['items'] = [
      '#type' => 'tableselect',
      '#header' => [
        'source_id' => $this->t('Source ID'),
        'dest_id' => $this->t('Destination ID'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No imported items found.'),
    ];

    $form['migration_id'] = [
      '#type' => 'hidden',
      '#value' => $migration_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Rollback selected items'),
        '#button_type' => 'primary',
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('migrate_admin.migration_detail', ['migration_id' => $migration_id]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migrationId = $form_state->getValue('migration_id');
    $selected = array_filter($form_state->getValue('items', []));

    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No items selected.'));
      return;
    }

    // Store in tempstore for the confirm form.
    $tempstore = $this->tempStoreFactory->get('migrate_admin');
    $sourceIdSets = [];
    foreach ($selected as $key) {
      $sourceIdSets[] = explode('|', $key);
    }
    $tempstore->set('partial_rollback_' . $migrationId, $sourceIdSets);

    $form_state->setRedirect('migrate_admin.migration_partial_rollback_confirm', [
      'migration_id' => $migrationId,
    ]);
  }

}
