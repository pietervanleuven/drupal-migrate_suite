<?php

declare(strict_types=1);

namespace Drupal\migrate_permissions\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\user\RoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a matrix form for managing per-migration permissions.
 */
class PermissionMatrixForm extends FormBase {

  /**
   * Constructs a PermissionMatrixForm object.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected MigrationPluginManagerInterface $migrationPluginManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'migrate_permissions_matrix_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Request $request = NULL): array {
    $groupFilter = $request ? $request->query->get('group', '') : '';

    $migrations = $this->migrationPluginManager->createInstances([]);

    if (empty($migrations)) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No migrations are currently configured.') . '</p>',
      ];
      return $form;
    }

    // Group migrations.
    $groupedMigrations = [];
    $allGroups = [];
    foreach ($migrations as $migrationId => $migration) {
      $definition = $migration->getPluginDefinition();
      $group = $definition['migration_group'] ?? 'default';
      $allGroups[$group] = $group;

      if ($groupFilter !== '' && $group !== $groupFilter) {
        continue;
      }

      $groupedMigrations[$group][$migrationId] = $migration->label() ?: $migrationId;
    }
    ksort($allGroups);
    ksort($groupedMigrations);

    // Group filter.
    $groupOptions = ['' => $this->t('- All groups -')];
    foreach ($allGroups as $group) {
      $groupOptions[$group] = $group;
    }
    $form['group_filter'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['migrate-matrix-filter']],
      'form' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'method' => 'get',
          'class' => ['migrate-dashboard-filter-form'],
        ],
        'group' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-item']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#attributes' => ['for' => 'edit-group'],
            '#value' => $this->t('Migration group'),
          ],
          'select' => [
            '#type' => 'html_tag',
            '#tag' => 'select',
            '#attributes' => [
              'name' => 'group',
              'id' => 'edit-group',
              'class' => ['form-select'],
            ],
            'options' => $this->buildSelectOptions($groupOptions, $groupFilter),
          ],
        ],
        'actions' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-actions']],
          'submit' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'submit',
              'value' => $this->t('Filter'),
              'class' => ['button', 'button--primary'],
            ],
          ],
        ],
      ],
    ];

    // Load roles (exclude anonymous).
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    unset($roles[RoleInterface::ANONYMOUS_ID]);
    /** @var \Drupal\user\RoleInterface[] $roles */

    // Permission types.
    $permissionTypes = [
      'view' => $this->t('View'),
      'run' => $this->t('Run'),
      'rollback' => $this->t('Rollback'),
    ];

    // Build header: Migration | Role1 (view/run/rollback) | Role2 ...
    $header = [$this->t('Migration')];
    foreach ($roles as $role) {
      $header[] = [
        'data' => $role->label(),
        'colspan' => 3,
        'class' => ['migrate-matrix-role-header'],
      ];
    }

    // Sub-header row with view/run/rollback labels per role.
    $subHeader = [''];
    foreach ($roles as $role) {
      foreach ($permissionTypes as $label) {
        $subHeader[] = [
          'data' => $label,
          'class' => ['migrate-matrix-perm-header'],
        ];
      }
    }

    // Build rows per migration group.
    $rows = [];
    foreach ($groupedMigrations as $group => $groupMigrationList) {
      // Group header row.
      $colspan = 1 + (count($roles) * 3);
      $rows[] = [
        'data' => [
          [
            'data' => $this->t('Group: @group', ['@group' => $group]),
            'colspan' => $colspan,
            'class' => ['migrate-matrix-group-header'],
            'header' => TRUE,
          ],
        ],
        'class' => ['migrate-matrix-group-row'],
      ];

      foreach ($groupMigrationList as $migrationId => $label) {
        $safeId = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $migrationId));
        $row = [['data' => $label, 'class' => ['migrate-matrix-migration-label']]];

        foreach ($roles as $roleId => $role) {
          // Skip admin role - it has all permissions.
          $isAdmin = $role->isAdmin();

          foreach ($permissionTypes as $permType => $permLabel) {
            $permName = "$permType migration $safeId";
            $elementName = "permissions[$roleId][$permName]";

            if ($isAdmin) {
              $row[] = [
                'data' => [
                  '#type' => 'checkbox',
                  '#default_value' => TRUE,
                  '#disabled' => TRUE,
                  '#attributes' => ['title' => $this->t('Admin role has all permissions')],
                ],
                'class' => ['migrate-matrix-cell'],
              ];
            }
            else {
              $hasPermission = $role->hasPermission($permName);
              $row[] = [
                'data' => [
                  '#type' => 'checkbox',
                  '#default_value' => $hasPermission,
                  '#name' => $elementName,
                  '#return_value' => 1,
                ],
                'class' => ['migrate-matrix-cell'],
              ];
            }
          }
        }

        $rows[] = $row;
      }
    }

    // Store metadata for form submission.
    $migrationIds = [];
    foreach ($groupedMigrations as $groupMigrationList) {
      foreach ($groupMigrationList as $migrationId => $label) {
        $migrationIds[] = $migrationId;
      }
    }
    $form['migration_ids'] = [
      '#type' => 'value',
      '#value' => $migrationIds,
    ];

    $roleIds = [];
    foreach ($roles as $roleId => $role) {
      if (!$role->isAdmin()) {
        $roleIds[] = $roleId;
      }
    }
    $form['role_ids'] = [
      '#type' => 'value',
      '#value' => $roleIds,
    ];

    $form['matrix'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => [],
      '#attributes' => ['class' => ['migrate-permission-matrix']],
      '#empty' => $this->t('No migrations match the selected filter.'),
    ];

    // Add sub-header row.
    $form['matrix']['#rows'][] = [
      'data' => array_map(function ($cell) {
        return is_array($cell) ? $cell : ['data' => $cell];
      }, $subHeader),
      'class' => ['migrate-matrix-subheader'],
    ];

    // Add data rows.
    foreach ($rows as $row) {
      $form['matrix']['#rows'][] = $row;
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save permissions'),
        '#button_type' => 'primary',
      ],
    ];

    $form['#attached'] = [
      'library' => ['migrate_permissions/matrix'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $migrationIds = $form_state->getValue('migration_ids', []);
    $roleIds = $form_state->getValue('role_ids', []);
    $userInput = $form_state->getUserInput();
    $permissions = $userInput['permissions'] ?? [];

    $permissionTypes = ['view', 'run', 'rollback'];

    foreach ($roleIds as $roleId) {
      $role = $this->entityTypeManager->getStorage('user_role')->load($roleId);
      if ($role === NULL || $role->isAdmin()) {
        continue;
      }

      foreach ($migrationIds as $migrationId) {
        $safeId = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $migrationId));

        foreach ($permissionTypes as $permType) {
          $permName = "$permType migration $safeId";
          $granted = !empty($permissions[$roleId][$permName]);

          if ($granted && !$role->hasPermission($permName)) {
            $role->grantPermission($permName);
          }
          elseif (!$granted && $role->hasPermission($permName)) {
            $role->revokePermission($permName);
          }
        }
      }

      $role->save();
    }

    $this->logger('migrate_permissions')->notice('Migration permissions updated by @user.', [
      '@user' => $this->currentUser()->getAccountName(),
    ]);

    $this->messenger()->addStatus($this->t('Migration permissions have been saved.'));
  }

  /**
   * Builds select option elements.
   *
   * @param array $options
   *   The options array.
   * @param string $selectedValue
   *   The currently selected value.
   *
   * @return array
   *   A render array of option elements.
   */
  protected function buildSelectOptions(array $options, string $selectedValue): array {
    $elements = [];
    foreach ($options as $value => $label) {
      $attributes = ['value' => $value];
      if ((string) $value === $selectedValue) {
        $attributes['selected'] = 'selected';
      }
      $elements[] = [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#attributes' => $attributes,
        '#value' => $label,
      ];
    }
    return $elements;
  }

}
