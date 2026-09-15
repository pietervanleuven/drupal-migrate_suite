<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_health\Service\MigrationHealthAnalyzer;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Drupal\migrate_schedule\Service\ScheduleManager;
use Drupal\migrate_suite\Service\MigrateTableNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the migration dashboard page.
 */
class MigrationDashboardController extends ControllerBase {

  /**
   * Number of migrations listed per page.
   */
  protected const ITEMS_PER_PAGE = 50;

  /**
   * Constructs a MigrationDashboardController object.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\migrate_suite\Service\MigrateTableNameResolver $tableNameResolver
   *   The migration table name resolver service.
   * @param \Drupal\migrate_permissions\MigrateAccessCheck|null $migrateAccessCheck
   *   The access check, or NULL if migrate_permissions is not installed.
   * @param \Drupal\migrate_health\Service\MigrationHealthAnalyzer|null $healthAnalyzer
   *   The health analyzer, or NULL if migrate_health is not installed.
   * @param \Drupal\migrate_schedule\Service\ScheduleManager|null $scheduleManager
   *   The schedule manager, or NULL if migrate_schedule is not installed.
   */
  public function __construct(
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly Connection $database,
    protected readonly DateFormatterInterface $dateFormatter,
    ModuleHandlerInterface $moduleHandler,
    protected readonly MigrateTableNameResolver $tableNameResolver,
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
    protected readonly ?MigrationHealthAnalyzer $healthAnalyzer,
    protected readonly ?ScheduleManager $scheduleManager,
  ) {
    $this->moduleHandler = $moduleHandler;
  }

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

    $scheduleManager = $moduleHandler->moduleExists('migrate_schedule')
      ? $container->get('migrate_schedule.manager')
      : NULL;

    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('database'),
      $container->get('date.formatter'),
      $moduleHandler,
      $container->get('migrate_suite.table_name_resolver'),
      $migrateAccessCheck,
      $healthAnalyzer,
      $scheduleManager,
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
    $page = max(0, (int) $request->query->get('page', 0));

    // Use the (cheap) plugin definitions rather than instantiating every
    // migration plugin up front: with migrate_drupal enabled there can be
    // hundreds of migrations, and createInstances() for all of them
    // (followed by a source count query each) is what makes this page
    // unusable at scale.
    $definitions = $this->migrationPluginManager->getDefinitions();

    if (empty($definitions)) {
      return [
        '#markup' => '<p>' . $this->t('No migrations are currently configured. Configure migrations using migration YAML files or Drush commands.') . '</p>',
      ];
    }

    // Collect all groups for the filter dropdown.
    $allGroups = [];
    // IDs that pass the cheap (definition-only) filters: permission, search,
    // group. Status still needs an instantiated migration to determine, so
    // it is applied separately below.
    $candidateIds = [];

    $account = $this->currentUser();
    $permissionsModuleEnabled = $this->migrateAccessCheck !== NULL;

    foreach ($definitions as $migrationId => $definition) {
      // Apply per-migration view permission filter.
      if ($permissionsModuleEnabled && !$this->migrateAccessCheck->canViewMigration($account, $migrationId)) {
        continue;
      }

      $label = $definition['label'] ?? $migrationId;
      $group = $definition['migration_group'] ?? 'default';
      $allGroups[$group] = $group;

      // Apply text search filter.
      if ($search !== '' && stripos($migrationId, $search) === FALSE && stripos((string) $label, $search) === FALSE) {
        continue;
      }

      // Apply group filter.
      if ($groupFilter !== '' && $group !== $groupFilter) {
        continue;
      }

      $candidateIds[] = $migrationId;
    }

    ksort($allGroups);

    // Determining status requires an instantiated migration plugin. Only pay
    // that cost for every candidate when the caller explicitly filters by
    // status; otherwise it is deferred until after pagination below so only
    // the migrations actually rendered on this page get instantiated.
    if ($statusFilter !== '') {
      $matchingIds = [];
      foreach ($this->migrationPluginManager->createInstances($candidateIds) as $migrationId => $migration) {
        if ($this->getMigrationStatus($migration) === $statusFilter) {
          $matchingIds[] = $migrationId;
        }
      }
    }
    else {
      $matchingIds = $candidateIds;
    }

    $totalMatching = count($matchingIds);
    $pageIds = array_slice($matchingIds, $page * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE);

    // Only the migrations on the current page are instantiated, and only
    // their map/run-log data is queried below, bounding the page cost
    // regardless of how many migrations are configured overall.
    $migrations = $this->migrationPluginManager->createInstances($pageIds);

    $groupedMigrations = [];
    foreach ($pageIds as $migrationId) {
      if (!isset($migrations[$migrationId])) {
        continue;
      }
      $migration = $migrations[$migrationId];
      $definition = $migration->getPluginDefinition();
      $label = $definition['label'] ?? $migrationId;
      $group = $definition['migration_group'] ?? 'default';

      $status = $this->getMigrationStatus($migration);

      // Get counts from map table.
      $counts = $this->getItemCounts($migrationId);

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
        'imported_count' => $counts['imported'],
        'failed_count' => $counts['failed'],
        'last_run' => $lastRun,
        'can_run' => $canRun,
        'can_rollback' => $canRollback,
      ];
    }

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

    // Build pager.
    if ($totalMatching > self::ITEMS_PER_PAGE) {
      $build['pager'] = $this->buildDashboardPager($page, $totalMatching, $request);
    }

    $libraries = ['migrate_admin/dashboard'];
    if ($healthModuleEnabled) {
      $libraries[] = 'migrate_health/health';
    }
    $build['#attached'] = [
      'library' => $libraries,
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'user.permissions'],
      'tags' => ['migration_plugins', 'migrate_suite:runs'],
    ];

    return $build;
  }

  /**
   * Builds a simple pager for the migration listing.
   *
   * @param int $currentPage
   *   The current page number (0-indexed).
   * @param int $total
   *   The total number of matching migrations.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array for the pager.
   */
  protected function buildDashboardPager(int $currentPage, int $total, Request $request): array {
    $totalPages = (int) ceil($total / self::ITEMS_PER_PAGE);
    $queryParams = $request->query->all();
    $links = [];

    if ($currentPage > 0) {
      $queryParams['page'] = $currentPage - 1;
      $links['previous'] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Previous'),
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.dashboard', [], ['query' => $queryParams])->toString(),
          'class' => ['button'],
        ],
      ];
    }

    $links['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Page @current of @total (@items migrations)', [
        '@current' => $currentPage + 1,
        '@total' => $totalPages,
        '@items' => $total,
      ]),
      '#attributes' => ['class' => ['migrate-pager-info']],
    ];

    if ($currentPage < $totalPages - 1) {
      $queryParams['page'] = $currentPage + 1;
      $links['next'] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Next'),
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.dashboard', [], ['query' => $queryParams])->toString(),
          'class' => ['button'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['migrate-pager']],
    ] + $links;
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
  protected function getMigrationStatus(MigrationInterface $migration): string {
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

    $table = $this->tableNameResolver->getMapTableName($migrationId);
    if (!$this->database->schema()->tableExists($table)) {
      return $counts;
    }

    $query = $this->database->select($table, 'map')
      ->fields('map', ['source_row_status'])
      ->groupBy('source_row_status');
    $query->addExpression('COUNT(*)', 'count');
    $results = $query->execute()->fetchAllKeyed();

    $statusMap = [
      (string) MigrateIdMapInterface::STATUS_IMPORTED => 'imported',
      (string) MigrateIdMapInterface::STATUS_NEEDS_UPDATE => 'needs_update',
      (string) MigrateIdMapInterface::STATUS_FAILED => 'failed',
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
   * @param bool $showHealth
   *   Whether to render the health column.
   *
   * @return array
   *   A render array for the group.
   */
  protected function buildGroupTable(string $group, array $migrations, bool $showHealth = FALSE): array {
    $rows = [];
    foreach ($migrations as $migrationId => $data) {
      $statusBadge = $this->buildStatusBadge($data['status']);

      $lastRun = $data['last_run']
        ? $this->dateFormatter->format($data['last_run'], 'short')
        : $this->t('Never');

      // Build action links.
      $actions = [];
      if ($data['can_run']) {
        $actions[] = Link::fromTextAndUrl($this->t('Run'), Url::fromRoute('migrate_admin.migration_run_confirm', ['migration_id' => $migrationId]))->toString();
      }
      if ($data['can_rollback']) {
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

      $row[] = $data['imported_count'];
      $row[] = $data['failed_count'];
      $row[] = $lastRun;

      $showSchedule = $this->scheduleManager !== NULL;
      if ($showSchedule) {
        $schedule = $this->scheduleManager->getSchedule($migrationId);
        $row[] = $schedule ? ucfirst($schedule['interval']) : $this->t('Not scheduled');
      }

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

    $header[] = $this->t('Imported');
    $header[] = $this->t('Failed');
    $header[] = $this->t('Last run');

    if ($this->scheduleManager !== NULL) {
      $header[] = $this->t('Schedule');
    }

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
