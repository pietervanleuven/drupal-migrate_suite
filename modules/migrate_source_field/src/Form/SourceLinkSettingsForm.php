<?php

declare(strict_types=1);

namespace Drupal\migrate_source_field\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for source link URL patterns per migration.
 */
class SourceLinkSettingsForm extends ConfigFormBase {

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface
   */
  protected MigrationPluginManagerInterface $migrationPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->migrationPluginManager = $container->get('plugin.manager.migration');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['migrate_source_field.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_source_field_source_link_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('migrate_source_field.settings');
    $sourceLinks = $config->get('source_links') ?? [];

    $migrations = $this->migrationPluginManager->createInstances([]);

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure source URL patterns for each migration. Use the <code>[source_id]</code> token to insert the source ID into the URL. For migrations with composite keys, the source IDs are joined with <code>/</code>.') . '</p>',
    ];

    // Group migrations by group.
    $groups = [];
    foreach ($migrations as $migrationId => $migration) {
      $definition = $migration->getPluginDefinition();
      $group = $definition['migration_group'] ?? 'default';
      $groups[$group][$migrationId] = $migration;
    }

    ksort($groups);

    // Migration IDs may contain '.' and ':' (derived migrations, e.g.
    // 'd7_node:article'), which are not safe to use verbatim as form keys,
    // and a naive character substitution is not reversible (it can collide).
    // Instead, generate an opaque key per migration ID and keep the mapping
    // in $form_state so submitForm() can resolve the real IDs exactly.
    $migrationKeys = [];

    foreach ($groups as $group => $groupMigrations) {
      $form['group_' . $group] = [
        '#type' => 'details',
        '#title' => $this->t('Group: @group', ['@group' => $group]),
        '#open' => TRUE,
      ];

      foreach ($groupMigrations as $migrationId => $migration) {
        $safeKey = 'm' . md5($migrationId);
        $migrationKeys[$safeKey] = $migrationId;
        $form['group_' . $group]['source_link_' . $safeKey] = [
          '#type' => 'textfield',
          '#title' => $migration->label() ?: $migrationId,
          '#description' => $this->t('e.g., https://old-site.com/node/[source_id]'),
          '#default_value' => $sourceLinks[$migrationId] ?? '',
          '#maxlength' => 2048,
        ];
      }
    }

    $form_state->set('migration_keys', $migrationKeys);

    if (empty($migrations)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No migrations found.') . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migrationKeys = $form_state->get('migration_keys') ?? [];
    $sourceLinks = [];

    foreach ($migrationKeys as $safeKey => $migrationId) {
      $value = trim((string) $form_state->getValue('source_link_' . $safeKey));
      if ($value !== '') {
        $sourceLinks[$migrationId] = $value;
      }
    }

    $this->config('migrate_source_field.settings')
      ->set('source_links', $sourceLinks)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
