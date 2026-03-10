<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_health\Service\MigrationHealthAnalyzer;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the migration dashboard page.
 */
class MigrationDashboardController extends ControllerBase {

  /**
   * Constructs a MigrationDashboardController object.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly Connection $database,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
    protected readonly ?MigrationHealthAnalyzer $healthAnalyzer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $moduleHandler = $container->get('module_handler');
    $migrateAccessCheck = $moduleHandler->moduleExists('migrate_permissions')
      ? $container->get('migrate_permissions.access_check')
      : NULL;
    $healthAnalyzer = $moduleHandler->moduleExists('migrate_health')
      ? $container->get('migrate_health.analyzer')
      : NULL;

    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('database'),
      $container->get('date.formatter'),
      $moduleHandler,
      $migrateAccessCheck,
      $healthAnalyzer,
    );
  }

  /**
   * Builds the migration dashboard page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function dashboard(Request $request): array {
    $search = $request->query->get('search', '');
    $statusFilter = $request->query->get('status', '');
    $groupFilter = $request->query->get('group', '');

    $migrations = $this->migrationPluginManager->createInstances([]);

    if (empty($migrations)) {
      return [
        '#markup' => '<p>' . $this->t('No migrations are currently configured. Configure migrations using migration YAML files or Drush commands.') . '</p>',
      ];
    }

    // Collect all groups for the filter dropdown.
    $allGroups = [];
    $groupedMigrations = [];

    $account = $this->currentUser();
    $permissionsModuleEnabled = $this->migrateAccessCheck !== NULL;

    foreach ($migrations as $migrationId => $migration) {
      // Apply per-migration view permission filter.
      if ($permissionsModuleEnabled && !$this->migrateAccessCheck->canViewMigration($account, $migrationId)) {
        continue;
      }

      $label = $migration->label() ?: $migrationId;
      $definition = $migration->getPluginDefinition();
      $group = $definition['migration_group'] ?? 'default';
      $allGroups[$group] = $group;

      // Apply text search filter.
      if ($search !== '' && stripos($migrationId, $search) === FALSE && stripos((string) $label, $search) === FALSE) {
        continue;
      }

      // Get migration status.
      $status = $this->getMigrationStatus($migration);

      // Apply status filter.
      if ($statusFilter !== '' && $status !== $statusFilter) {
        continue;
      }

      // Apply group filter.
      if ($groupFilter !== '' && $group !== $groupFilter) {
        continue;
      }

      // Get counts from map table.
      $counts = $this->getItemCounts($migrationId);

      // Get source count.
      $sourceCount = $this->getSourceCount($migration);

      // Get last run timestamp.
      $lastRun = $this->getLastRunTimestamp($migrationId);

      // Determine run/rollback access.
      $canRun = FALSE;
      $canRollback = FALSE;
      if ($permissionsModuleEnabled) {
        $canRun = $this->migrateAccessCheck->canRunMigration($account, $migrationId);
        $canRollback = $this->migrateAccessCheck->canRollbackMigration($account, $migrationId);
      }
      elseif ($account->hasPermission('administer migrations') || $account->hasPermission('administer site configuration')) {
        $canRun = TRUE;
        $canRollback = TRUE;
      }

      $groupedMigrations[$group][$migrationId] = [
        'label' => $label,
        'group' => $group,
        'status' => $status,
        'source_count' => $sourceCount,
        'imported_count' => $counts['imported'],
        'failed_count' => $counts['failed'],
        'last_run' => $lastRun,
        'can_run' => $canRun,
        'can_rollback' => $canRollback,
      ];
    }

    ksort($allGroups);

    // Compute health statuses if migrate_health is enabled.
    $healthModuleEnabled = $this->healthAnalyzer !== NULL;
    if ($healthModuleEnabled) {
      $allMigrationIds = [];
      foreach ($groupedMigrations as $groupMigrations) {
        $allMigrationIds = array_merge($allMigrationIds, array_keys($groupMigrations));
      }
      $healthStatuses = $this->healthAnalyzer->getHealthForMultiple($allMigrationIds);

      // Add health data to each migration entry.
      foreach ($groupedMigrations as $group => &$groupMigrations) {
        foreach ($groupMigrations as $migrationId => &$data) {
          $data['health'] = $healthStatuses[$migrationId] ?? MigrationHealthAnalyzer::HEALTHY;
        }
      }
      unset($groupMigrations, $data);
    }

    $build = [];

    // Aggregate health summary at top of dashboard.
    if ($healthModuleEnabled && !empty($groupedMigrations)) {
      $summary = $this->healthAnalyzer->getAggregateSummary($healthStatuses);
      $build['health_summary'] = $this->buildHealthSummary($summary);
    }

    // Filter form.
    $build['filters'] = $this->buildFilterForm($search, $statusFilter, $groupFilter, $allGroups);

    // Build grouped tables.
    foreach ($groupedMigrations as $group => $groupMigrations) {
      $build['group_' . $group] = $this->buildGroupTable($group, $groupMigrations, $healthModuleEnabled);
    }

    if (empty($groupedMigrations)) {
      if ($permissionsModuleEnabled && $search === '' && $statusFilter === '' && $groupFilter === '') {
        $build['empty'] = [
          '#markup' => '<p>' . $this->t('You do not have permission to view any migrations. Contact your site administrator to grant you per-migration view permissions.') . '</p>',
        ];
      }
      else {
        $build['empty'] = [
          '#markup' => '<p>' . $this->t('No migrations match the current filters.') . '</p>',
        ];
      }
    }

    $libraries = ['migrate_admin/dashboard'];
    if ($healthModuleEnabled) {
      $libraries[] = 'migrate_health/health';
    }
    $build['#attached'] = [
      'library' => $libraries,
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args'],
      'tags' => ['migration_plugins'],
    ];

    return $build;
  }

  /**
   * Gets the current status of a migration.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration plugin instance.
   *
   * @return string
   *   The status string: idle, importing, rolling_back, completed, or failed.
   */
  protected function getMigrationStatus($migration): string {
    $status = $migration->getStatusLabel();

    // Map Drupal's status labels to our internal statuses.
    $statusString = (string) $status;
    $statusMap = [
      'Idle' => 'idle',
      'Importing' => 'importing',
      'Rolling back' => 'rolling_back',
      'Stopping' => 'idle',
      'Disabled' => 'idle',
    ];

    $migrationStatus = $statusMap[$statusString] ?? 'idle';

    // Check run log for completed/failed status if currently idle.
    if ($migrationStatus === 'idle') {
      $migrationId = $migration->id();
      $lastRun = $this->database->select('migrate_suite_run_log', 'r')
        ->fields('r', ['status'])
        ->condition('migration_id', $migrationId)
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($lastRun === 'completed') {
        $migrationStatus = 'completed';
      }
      elseif ($lastRun === 'failed') {
        $migrationStatus = 'failed';
      }
    }

    return $migrationStatus;
  }

  /**
   * Gets item counts from the map table.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return array
   *   Associative array with 'imported', 'needs_update', 'failed' counts.
   */
  protected function getItemCounts(string $migrationId): array {
    $counts = [
      'imported' => 0,
      'needs_update' => 0,
      'failed' => 0,
    ];

    $table = 'migrate_map_' . $migrationId;
    if (!$this->database->schema()->tableExists($table)) {
      return $counts;
    }

    $results = $this->database->select($table, 'map')
      ->fields('map', ['source_row_status'])
      ->groupBy('source_row_status')
      ->addExpression('COUNT(*)', 'count')
      ->execute()
      ->fetchAllKeyed();

    // Status constants: 0 = imported, 1 = needs_update, 2 = failed.
    $statusMap = [
      '0' => 'imported',
      '1' => 'needs_update',
      '2' => 'failed',
    ];

    foreach ($results as $status => $count) {
      $key = $statusMap[(string) $status] ?? NULL;
      if ($key !== NULL) {
        $counts[$key] = (int) $count;
      }
    }

    return $counts;
  }

  /**
   * Gets the source count for a migration.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration plugin instance.
   *
   * @return int|string
   *   The source count or 'N/A' if unavailable.
   */
  protected function getSourceCount($migration): int|string {
    try {
      $source = $migration->getSourcePlugin();
      $count = $source->count();
      return $count === -1 ? 'N/A' : $count;
    }
    catch (\Exception $e) {
      return 'N/A';
    }
  }

  /**
   * Gets the last run timestamp for a migration.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return int|null
   *   The timestamp of the last run, or NULL if never run.
   */
  protected function getLastRunTimestamp(string $migrationId): ?int {
    $result = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r', ['started'])
      ->condition('migration_id', $migrationId)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $result ? (int) $result : NULL;
  }

  /**
   * Builds the filter form.
   *
   * @param string $search
   *   Current search value.
   * @param string $statusFilter
   *   Current status filter.
   * @param string $groupFilter
   *   Current group filter.
   * @param array $allGroups
   *   All available migration groups.
   *
   * @return array
   *   A render array for the filter form.
   */
  protected function buildFilterForm(string $search, string $statusFilter, string $groupFilter, array $allGroups): array {
    $statusOptions = [
      '' => $this->t('- All statuses -'),
      'idle' => $this->t('Idle'),
      'importing' => $this->t('Importing'),
      'rolling_back' => $this->t('Rolling back'),
      'completed' => $this->t('Completed'),
      'failed' => $this->t('Failed'),
    ];

    $groupOptions = ['' => $this->t('- All groups -')];
    foreach ($allGroups as $group) {
      $groupOptions[$group] = $group;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['migrate-dashboard-filters']],
      'form' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'method' => 'get',
          'class' => ['migrate-dashboard-filter-form'],
        ],
        'search' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-item']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#attributes' => ['for' => 'edit-search'],
            '#value' => $this->t('Search'),
          ],
          'input' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'text',
              'name' => 'search',
              'id' => 'edit-search',
              'value' => $search,
              'placeholder' => $this->t('Migration ID or label'),
              'class' => ['form-text'],
            ],
          ],
        ],
        'status' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-item']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#attributes' => ['for' => 'edit-status'],
            '#value' => $this->t('Status'),
          ],
          'select' => [
            '#type' => 'html_tag',
            '#tag' => 'select',
            '#attributes' => [
              'name' => 'status',
              'id' => 'edit-status',
              'class' => ['form-select'],
            ],
            'options' => $this->buildSelectOptions($statusOptions, $statusFilter),
          ],
        ],
        'group' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-item']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#attributes' => ['for' => 'edit-group'],
            '#value' => $this->t('Group'),
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

  /**
   * Builds a migration group table.
   *
   * @param string $group
   *   The group name.
   * @param array $migrations
   *   The migrations in this group.
   *
   * @return array
   *   A render array for the group.
   */
  protected function buildGroupTable(string $group, array $migrations, bool $showHealth = FALSE): array {
    $rows = [];
    $showActions = FALSE;
    foreach ($migrations as $migrationId => $data) {
      $statusBadge = $this->buildStatusBadge($data['status']);

      $lastRun = $data['last_run']
        ? $this->dateFormatter->format($data['last_run'], 'short')
        : $this->t('Never');

      // Build action links.
      $actions = [];
      if ($data['can_run']) {
        $showActions = TRUE;
        $actions[] = Link::fromTextAndUrl($this->t('Run'), Url::fromRoute('migrate_admin.migration_run_confirm', ['migration_id' => $migrationId]))->toString();
      }
      if ($data['can_rollback']) {
        $showActions = TRUE;
        $actions[] = Link::fromTextAndUrl($this->t('Rollback'), Url::fromRoute('migrate_admin.migration_rollback_confirm', ['migration_id' => $migrationId]))->toString();
      }
      $actionsMarkup = $actions ? implode(' | ', $actions) : '';

      $row = [
        Link::fromTextAndUrl($data['label'], Url::fromRoute('migrate_admin.migration_detail', ['migration_id' => $migrationId]))->toString(),
        $data['group'],
        $statusBadge,
      ];

      if ($showHealth) {
        $row[] = $this->buildHealthBadge($data['health'] ?? MigrationHealthAnalyzer::HEALTHY);
      }

      $row[] = $data['source_count'];
      $row[] = $data['imported_count'];
      $row[] = $data['failed_count'];
      $row[] = $lastRun;
      $row[] = ['data' => ['#markup' => $actionsMarkup]];
      $rows[] = $row;
    }

    $header = [
      $this->t('Migration'),
      $this->t('Group'),
      $this->t('Status'),
    ];

    if ($showHealth) {
      $header[] = $this->t('Health');
    }

    $header[] = $this->t('Source count');
    $header[] = $this->t('Imported');
    $header[] = $this->t('Failed');
    $header[] = $this->t('Last run');
    $header[] = $this->t('Actions');

    return [
      '#type' => 'details',
      '#title' => $this->t('Group: @group (@count)', [
        '@group' => $group,
        '@count' => count($migrations),
      ]),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No migrations in this group.'),
      ],
    ];
  }

  /**
   * Builds a colored status badge.
   *
   * @param string $status
   *   The migration status.
   *
   * @return array|string
   *   A markup string with the status badge.
   */
  protected function buildStatusBadge(string $status): array|string {
    $labels = [
      'idle' => $this->t('Idle'),
      'importing' => $this->t('Importing'),
      'rolling_back' => $this->t('Rolling back'),
      'completed' => $this->t('Completed'),
      'failed' => $this->t('Failed'),
    ];

    $colors = [
      'idle' => 'color--success',
      'importing' => 'color--primary',
      'rolling_back' => 'color--warning',
      'completed' => 'color--success',
      'failed' => 'color--error',
    ];

    $label = $labels[$status] ?? $status;
    $colorClass = $colors[$status] ?? '';

    return [
      'data' => [
        '#markup' => '<span class="badge ' . $colorClass . '">' . $label . '</span>',
      ],
    ];
  }

  /**
   * Builds a health badge.
   *
   * @param string $health
   *   The health status: healthy, stale, or failing.
   *
   * @return array
   *   A render array for the health badge.
   */
  protected function buildHealthBadge(string $health): array {
    $labels = [
      MigrationHealthAnalyzer::HEALTHY => $this->t('Healthy'),
      MigrationHealthAnalyzer::STALE => $this->t('Stale'),
      MigrationHealthAnalyzer::FAILING => $this->t('Failing'),
    ];

    $classes = [
      MigrationHealthAnalyzer::HEALTHY => 'health--healthy',
      MigrationHealthAnalyzer::STALE => 'health--stale',
      MigrationHealthAnalyzer::FAILING => 'health--failing',
    ];

    $label = $labels[$health] ?? $health;
    $class = $classes[$health] ?? '';

    return [
      'data' => [
        '#markup' => '<span class="health-badge ' . $class . '">' . $label . '</span>',
      ],
    ];
  }

  /**
   * Builds the aggregate health summary bar.
   *
   * @param array $summary
   *   Array with keys: healthy, stale, failing (counts).
   *
   * @return array
   *   A render array for the health summary.
   */
  protected function buildHealthSummary(array $summary): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['migrate-health-summary']],
      'healthy' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['migrate-health-summary-item']],
        '#value' => '<span class="health-badge health--healthy">' . $summary[MigrationHealthAnalyzer::HEALTHY] . '</span> ' . $this->t('healthy'),
      ],
      'stale' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['migrate-health-summary-item']],
        '#value' => '<span class="health-badge health--stale">' . $summary[MigrationHealthAnalyzer::STALE] . '</span> ' . $this->t('stale'),
      ],
      'failing' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['migrate-health-summary-item']],
        '#value' => '<span class="health-badge health--failing">' . $summary[MigrationHealthAnalyzer::FAILING] . '</span> ' . $this->t('failing'),
      ],
    ];
  }

}
