<?php

declare(strict_types=1);

namespace Drupal\migrate_schedule\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for configuring per-migration schedules.
 */
class ScheduleSettingsForm extends ConfigFormBase {

  /**
   * The migration plugin manager.
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
    return ['migrate_schedule.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_schedule_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('migrate_schedule.settings');
    $schedules = $config->get('schedules') ?? [];

    $intervalOptions = [
      'disabled' => $this->t('Disabled'),
      'hourly' => $this->t('Hourly'),
      'daily' => $this->t('Daily'),
      'weekly' => $this->t('Weekly'),
    ];

    try {
      $migrations = $this->migrationPluginManager->createInstances([]);
    }
    catch (\Exception $e) {
      $migrations = [];
    }

    if (empty($migrations)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No migrations found.') . '</p>',
      ];
      return parent::buildForm($form, $form_state);
    }

    $form['schedules'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Migration'),
        $this->t('Interval'),
        $this->t('Skip if unchanged'),
      ],
    ];

    // Migration IDs can contain characters (such as '.' and ':') that are
    // not safe to use as form array keys, and are not necessarily unique
    // once naively sanitized (e.g. 'a.b' and 'a__b' would collide). Map each
    // migration to an unambiguous generated key instead, and stash the
    // mapping on the form so submitForm() can resolve the real IDs back.
    $migrationKeys = [];

    foreach ($migrations as $migrationId => $migration) {
      $safeKey = 'm' . md5($migrationId);
      $migrationKeys[$safeKey] = $migrationId;
      $schedule = $schedules[$migrationId] ?? [];

      $form['schedules'][$safeKey]['label'] = [
        '#plain_text' => $migration->label() ?: $migrationId,
      ];

      $form['schedules'][$safeKey]['interval'] = [
        '#type' => 'select',
        '#options' => $intervalOptions,
        '#default_value' => $schedule['interval'] ?? 'disabled',
      ];

      $form['schedules'][$safeKey]['skip_if_no_changes'] = [
        '#type' => 'checkbox',
        '#default_value' => $schedule['skip_if_no_changes'] ?? FALSE,
      ];
    }

    $form['#migration_keys'] = $migrationKeys;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $scheduleValues = $form_state->getValue('schedules') ?? [];
    $schedules = [];

    // Resolve the real migration IDs from the same key mapping that
    // buildForm() generated, rather than trying to reverse the safe key.
    $migrationKeys = $form['#migration_keys'] ?? [];

    foreach ($migrationKeys as $safeKey => $migrationId) {
      $row = $scheduleValues[$safeKey] ?? [];

      $interval = $row['interval'] ?? 'disabled';
      if ($interval !== 'disabled') {
        $schedules[$migrationId] = [
          'interval' => $interval,
          'skip_if_no_changes' => !empty($row['skip_if_no_changes']),
        ];
      }
    }

    $this->config('migrate_schedule.settings')
      ->set('schedules', $schedules)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
