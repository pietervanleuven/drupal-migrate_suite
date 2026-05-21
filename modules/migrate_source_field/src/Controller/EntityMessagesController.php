<?php

declare(strict_types=1);

namespace Drupal\migrate_source_field\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\migrate_source_field\Service\ProvenanceLookup;
use Drupal\migrate_suite\Service\MigrateMessageQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for viewing migration messages for a specific entity.
 */
class EntityMessagesController extends ControllerBase {

  public function __construct(
    protected readonly ProvenanceLookup $provenanceLookup,
    protected readonly MigrateMessageQuery $messageQuery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('migrate_source_field.provenance_lookup'),
      $container->get('migrate_suite.message_query'),
    );
  }

  /**
   * Displays migration messages for a specific entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   *
   * @return array
   *   A render array.
   */
  public function messages(string $entity_type, string $entity_id): array {
    $provenance = $this->provenanceLookup->lookupProvenance($entity_type, $entity_id);

    if ($provenance === NULL) {
      return [
        '#markup' => '<p>' . $this->t('No migration provenance found for this entity.') . '</p>',
      ];
    }

    $migrationId = $provenance['migration_id'];
    $sourceIds = $provenance['source_ids'];

    $messages = $this->messageQuery->getMessagesForSourceId($migrationId, $sourceIds);

    $levelLabels = [
      3 => $this->t('Error'),
      4 => $this->t('Warning'),
      6 => $this->t('Notice'),
    ];

    $build = [];

    $build['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity provenance'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#rows' => [
          [$this->t('Entity type'), $entity_type],
          [$this->t('Entity ID'), $entity_id],
          [$this->t('Migration'), $provenance['migration_label']],
          [$this->t('Source ID(s)'), implode(' / ', $sourceIds)],
        ],
      ],
    ];

    $rows = [];
    foreach ($messages as $message) {
      $rows[] = [
        (string) ($levelLabels[(int) $message->level] ?? $this->t('Unknown')),
        $message->message ?? '',
      ];
    }

    $build['messages'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Severity'),
        $this->t('Message'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No migration messages found for this entity.'),
    ];

    return $build;
  }

}
