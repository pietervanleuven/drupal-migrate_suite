<?php

declare(strict_types=1);

namespace Drupal\migrate_health\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for migration health thresholds.
 */
class HealthSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['migrate_health.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_health_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('migrate_health.settings');

    $form['stale_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Stale threshold (days)'),
      '#description' => $this->t('Number of days after which a migration is considered stale if it has not been run.'),
      '#default_value' => $config->get('stale_threshold') ?? 7,
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $form['failure_rate_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failure rate threshold (%)'),
      '#description' => $this->t('Percentage of failed items above which a migration is considered failing.'),
      '#default_value' => $config->get('failure_rate_threshold') ?? 5,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('migrate_health.settings')
      ->set('stale_threshold', (int) $form_state->getValue('stale_threshold'))
      ->set('failure_rate_threshold', (float) $form_state->getValue('failure_rate_threshold'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
