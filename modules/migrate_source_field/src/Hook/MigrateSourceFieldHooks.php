<?php

declare(strict_types=1);

namespace Drupal\migrate_source_field\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeTypeInterface;

/**
 * Hook implementations for Migrate Source Field.
 *
 * On Drupal 11.1+ these methods are discovered via the #[Hook] attributes;
 * on Drupal 10.4 the procedural implementations in
 * migrate_source_field.module delegate here instead. Both paths resolve
 * this class as the service registered under its own name in
 * migrate_source_field.services.yml.
 *
 * The `_migrate_source_field_*()` helpers these methods call are shared
 * render/lookup utilities, not hooks, and stay in the .module file — which
 * Drupal loads on every request, on all supported core versions.
 */
class MigrateSourceFieldHooks {

  use StringTranslationTrait;

  /**
   * Constructs a MigrateSourceFieldHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];

    $nodeTypes = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    foreach ($nodeTypes as $nodeType) {
      assert($nodeType instanceof NodeTypeInterface);
      $extra['node'][$nodeType->id()]['display']['migrate_provenance'] = [
        'label' => $this->t('Migration provenance'),
        'description' => $this->t('Shows migration source information for imported content.'),
        'weight' => 100,
        'visible' => FALSE,
      ];
      $extra['node'][$nodeType->id()]['form']['migrate_provenance'] = [
        'label' => $this->t('Migration provenance'),
        'description' => $this->t('Shows migration source information for imported content.'),
        'weight' => 100,
        'visible' => FALSE,
      ];
    }

    return $extra;
  }

  /**
   * Implements hook_ENTITY_TYPE_view() for node entities.
   */
  #[Hook('node_view')]
  public function nodeView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if (!$display->getComponent('migrate_provenance')) {
      return;
    }

    $provenance = _migrate_source_field_get_provenance($entity);
    if ($provenance === NULL) {
      return;
    }

    $build['migrate_provenance'] = _migrate_source_field_build_provenance_display($provenance);
    $build['migrate_provenance']['#cache'] = [
      'tags' => ['migrate_source_field:provenance'],
    ];
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node forms.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state): void {
    $formObject = $form_state->getFormObject();
    if (!$formObject instanceof EntityFormInterface) {
      return;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $formObject->getEntity();

    if ($node->isNew()) {
      return;
    }

    // Check if the pseudo-field is enabled on the form display.
    $formDisplay = $this->entityTypeManager
      ->getStorage('entity_form_display')
      ->load('node.' . $node->bundle() . '.default');

    if (!$formDisplay || !$formDisplay->getComponent('migrate_provenance')) {
      return;
    }

    $provenance = _migrate_source_field_get_provenance($node);
    if ($provenance === NULL) {
      return;
    }

    $form['migrate_provenance'] = _migrate_source_field_build_provenance_display($provenance);
    $form['migrate_provenance']['#weight'] = 100;
  }

}
