<?php

declare(strict_types=1);

namespace Drupal\migrate_views\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders the migration run status as a badge.
 *
 * @ViewsField("migrate_views_status")
 */
class MigrationStatus extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array {
    $status = $this->getValue($values);

    $classMap = [
      'running' => 'color-warning',
      'completed' => 'color-success',
      'failed' => 'color-error',
    ];

    $class = $classMap[$status] ?? '';

    return [
      '#markup' => '<span class="' . $class . '">' . htmlspecialchars((string) $status) . '</span>',
    ];
  }

}
