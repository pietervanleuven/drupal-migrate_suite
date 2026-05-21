<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Drupal\migrate_suite\Service\MigrateMessageQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the migration detail page.
 */
class MigrationDetailController extends ControllerBase {

  /**
   * Number of items per page.
   */
  protected const ITEMS_PER_PAGE = 50;

  /**
   * Constructs a MigrationDetailController object.
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
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
    protected readonly MigrateMessageQuery $messageQuery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $migrateAccessCheck = $container->get('module_handler')->moduleExists('migrate_permissions')
      ? $container->get('migrate_permissions.access_check')
      : NULL;

    return new static(
      $container->get('plugin.manager.migration'),
      $container->get('database'),
      $container->get('date.formatter'),
      $migrateAccessCheck,
      $container->get('migrate_suite.message_query'),
    );
  }

  /**
   * Builds the migration detail page.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function detail(string $migration_id, Request $request): array {
    $migration = $this->loadMigration($migration_id);

    $definition = $migration->getPluginDefinition();
    $group = $definition['migration_group'] ?? 'default';
    $sourcePlugin = $definition['source']['plugin'] ?? $this->t('Unknown');
    $destinationPlugin = $definition['destination']['plugin'] ?? $this->t('Unknown');

    // Get status.
    $status = $this->getMigrationStatus($migration);

    // Get last run stats.
    $lastRun = $this->getLastRunStats($migration_id);

    // Build summary section.
    $build = [];

    $build['summary'] = [
      '#type' => 'details',
      '#title' => $this->t('Summary'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#rows' => [
          [$this->t('Migration'), $migration->label() ?: $migration_id],
          [$this->t('Group'), $group],
          [$this->t('Source plugin'), $sourcePlugin],
          [$this->t('Destination plugin'), $destinationPlugin],
          [$this->t('Status'), $this->buildStatusBadge($status)],
          [$this->t('Last run'), $lastRun ? $this->buildLastRunSummary($lastRun) : $this->t('Never')],
        ],
      ],
    ];

    // Build tabs.
    $build['tabs'] = $this->buildTabs($migration_id, 'items');

    // Build imported items section.
    $build['items'] = $this->buildImportedItemsSection($migration_id, $request);

    $build['#attached'] = [
      'library' => ['migrate_admin/dashboard'],
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'url.path'],
      'tags' => ['migration_plugins'],
    ];

    return $build;
  }

  /**
   * Builds the migration messages page.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function messages(string $migration_id, Request $request): array {
    $migration = $this->loadMigration($migration_id);

    $build = [];

    // Build tabs.
    $build['tabs'] = $this->buildTabs($migration_id, 'messages');

    // Build messages section.
    $build['messages'] = $this->buildMessagesSection($migration_id, $request);

    $build['#attached'] = [
      'library' => ['migrate_admin/dashboard'],
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'url.path'],
      'tags' => ['migration_plugins'],
    ];

    return $build;
  }

  /**
   * Builds the failed items page.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function failedItems(string $migration_id, Request $request): array {
    $migration = $this->loadMigration($migration_id);

    $build = [];

    // Build tabs.
    $build['tabs'] = $this->buildTabs($migration_id, 'failed');

    // Build failed items section.
    $build['failed'] = $this->buildFailedItemsSection($migration_id, $request);

    $build['#attached'] = [
      'library' => ['migrate_admin/dashboard'],
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'url.path'],
      'tags' => ['migration_plugins'],
    ];

    return $build;
  }

  /**
   * Builds the run history page.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function history(string $migration_id, Request $request): array {
    $migration = $this->loadMigration($migration_id);

    $build = [];
    $build['tabs'] = $this->buildTabs($migration_id, 'history');
    $build['history'] = $this->buildHistorySection($migration_id, $request);

    $build['#attached'] = [
      'library' => ['migrate_admin/dashboard'],
    ];
    $build['#cache'] = [
      'contexts' => ['url.query_args', 'url.path'],
      'tags' => ['migration_plugins'],
    ];

    return $build;
  }

  /**
   * Builds the run history section.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  protected function buildHistorySection(string $migrationId, Request $request): array {
    $page = max(0, (int) $request->query->get('page', 0));
    $offset = $page * self::ITEMS_PER_PAGE;

    $query = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', $migrationId)
      ->orderBy('id', 'DESC')
      ->range($offset, self::ITEMS_PER_PAGE);

    $rows = [];
    foreach ($query->execute()->fetchAll() as $run) {
      $started = $this->dateFormatter->format((int) $run->started, 'short');
      $finished = $run->finished ? $this->dateFormatter->format((int) $run->finished, 'short') : '-';

      $duration = '-';
      if ($run->finished && $run->started) {
        $seconds = (int) $run->finished - (int) $run->started;
        $duration = $seconds < 60 ? $this->t('@seconds', ['@seconds' => $seconds . 's']) : $this->t('@mins', ['@mins' => round($seconds / 60, 1) . 'm']);
      }

      $operation = ($run->operation ?? 'import') === 'rollback'
        ? $this->t('Rollback')
        : $this->t('Import');

      $rows[] = [
        $operation,
        $this->buildStatusBadge($run->status),
        $started,
        $finished,
        $duration,
        $run->items_processed ?? 0,
        $run->items_created ?? 0,
        $run->items_updated ?? 0,
        $run->items_failed ?? 0,
        $run->items_deleted ?? 0,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Operation'),
        $this->t('Status'),
        $this->t('Started'),
        $this->t('Finished'),
        $this->t('Duration'),
        $this->t('Processed'),
        $this->t('Created'),
        $this->t('Updated'),
        $this->t('Failed'),
        $this->t('Deleted'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No run history found for this migration.'),
    ];
  }

  /**
   * Builds the tab navigation for the detail page.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param string $activeTab
   *   The currently active tab ('items', 'messages', or 'failed').
   *
   * @return array
   *   A render array.
   */
  protected function buildTabs(string $migrationId, string $activeTab): array {
    $failedCount = $this->getFailedItemCount($migrationId);
    $failedLabel = $failedCount > 0
      ? $this->t('Failed Items (@count)', ['@count' => $failedCount])
      : $this->t('Failed Items');

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['migrate-detail-tabs']],
      'items_tab' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Imported Items'),
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.migration_detail', ['migration_id' => $migrationId])->toString(),
          'class' => array_filter(['migrate-tab', $activeTab === 'items' ? 'migrate-tab--active' : '']),
        ],
      ],
      'messages_tab' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Messages'),
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.migration_messages', ['migration_id' => $migrationId])->toString(),
          'class' => array_filter(['migrate-tab', $activeTab === 'messages' ? 'migrate-tab--active' : '']),
        ],
      ],
      'failed_tab' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $failedLabel,
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.migration_failed_items', ['migration_id' => $migrationId])->toString(),
          'class' => array_filter([
            'migrate-tab',
            $activeTab === 'failed' ? 'migrate-tab--active' : '',
            $failedCount > 0 ? 'migrate-tab--has-badge' : '',
          ]),
        ],
      ],
      'history_tab' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Run History'),
        '#attributes' => [
          'href' => Url::fromRoute('migrate_admin.migration_history', ['migration_id' => $migrationId])->toString(),
          'class' => array_filter(['migrate-tab', $activeTab === 'history' ? 'migrate-tab--active' : '']),
        ],
      ],
    ];
  }

  /**
   * Gets the count of failed items for a migration.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return int
   *   The number of failed items.
   */
  protected function getFailedItemCount(string $migrationId): int {
    $table = 'migrate_map_' . $migrationId;
    if (!$this->database->schema()->tableExists($table)) {
      return 0;
    }

    return (int) $this->database->select($table, 'map')
      ->condition('source_row_status', 2)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Builds the failed items section with filters and pager.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  protected function buildFailedItemsSection(string $migrationId, Request $request): array {
    $search = $request->query->get('search', '');
    $page = max(0, (int) $request->query->get('page', 0));

    $mapTable = 'migrate_map_' . $migrationId;
    if (!$this->database->schema()->tableExists($mapTable)) {
      return [
        '#markup' => '<p>' . $this->t('No map table exists for this migration. The migration may not have been run yet.') . '</p>',
      ];
    }

    // Query for failed items (source_row_status = 2).
    $query = $this->database->select($mapTable, 'map')
      ->fields('map')
      ->condition('source_row_status', 2);

    // Detect source ID columns.
    $sourceIdCols = [];
    for ($i = 1; $i <= 9; $i++) {
      if ($this->database->schema()->fieldExists($mapTable, 'sourceid' . $i)) {
        $sourceIdCols[] = 'sourceid' . $i;
      }
    }

    // Apply search filter on source IDs.
    if ($search !== '') {
      $or = $query->orConditionGroup();
      foreach ($sourceIdCols as $col) {
        $or->condition($col, '%' . $this->database->escapeLike($search) . '%', 'LIKE');
      }
      $query->condition($or);
    }

    // Count total for pager.
    $countQuery = clone $query;
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    // Apply pager.
    $query->range($page * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE);
    $query->orderBy('last_imported', 'DESC');
    $rows = $query->execute()->fetchAll();

    // Check if message table exists for error messages.
    $msgTable = 'migrate_message_' . $migrationId;
    $hasMsgTable = $this->database->schema()->tableExists($msgTable);

    // Detect message source ID columns.
    $msgSourceIdCols = [];
    if ($hasMsgTable) {
      for ($i = 1; $i <= 9; $i++) {
        $col = 'src_' . $i;
        if ($this->database->schema()->fieldExists($msgTable, $col)) {
          $msgSourceIdCols[] = $col;
        }
      }
    }

    // Build filter form.
    $build = [];
    $build['filters'] = $this->buildFailedItemFilterForm($migrationId, $search);

    // Check if user can reset failed items.
    $canReset = $this->canResetFailedItems($migrationId);

    // Build table rows.
    $tableRows = [];
    foreach ($rows as $row) {
      $sourceIds = [];
      foreach ($sourceIdCols as $col) {
        if (isset($row->$col) && $row->$col !== NULL) {
          $sourceIds[] = $row->$col;
        }
      }

      $sourceIdStr = implode(', ', $sourceIds);

      // Look up error message from message table.
      $errorMessage = '';
      if ($hasMsgTable && !empty($sourceIds)) {
        $errorMessage = $this->getErrorMessageForSourceIds($msgTable, $msgSourceIdCols, $sourceIds);
      }

      $lastImported = !empty($row->last_imported)
        ? $this->dateFormatter->format((int) $row->last_imported, 'short')
        : $this->t('Unknown');

      $rowData = [
        $sourceIdStr,
        $errorMessage,
        $lastImported,
      ];

      $tableRows[] = $rowData;
    }

    $header = [
      $this->t('Source ID(s)'),
      $this->t('Error message'),
      $this->t('Last attempt'),
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $tableRows,
      '#empty' => $this->t('No failed items found.'),
    ];

    // Add reset action link if user has permission and there are failed items.
    if ($canReset && $total > 0) {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['migrate-failed-actions']],
        'reset_link' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $this->t('Reset all failed items for retry'),
          '#attributes' => [
            'href' => Url::fromRoute('migrate_admin.migration_reset_failed', ['migration_id' => $migrationId])->toString(),
            'class' => ['button', 'button--danger'],
          ],
        ],
      ];
    }

    // Build pager.
    if ($total > self::ITEMS_PER_PAGE) {
      $build['pager'] = $this->buildPager($migrationId, $page, $total, $request, 'failed');
    }

    return $build;
  }

  /**
   * Gets the error message for a set of source IDs from the message table.
   *
   * @param string $msgTable
   *   The message table name.
   * @param array $msgSourceIdCols
   *   The source ID column names in the message table.
   * @param array $sourceIds
   *   The source ID values.
   *
   * @return string
   *   The error message, or empty string if not found.
   */
  protected function getErrorMessageForSourceIds(string $msgTable, array $msgSourceIdCols, array $sourceIds): string {
    try {
      $query = $this->database->select($msgTable, 'msg')
        ->fields('msg', ['message'])
        ->orderBy('msgid', 'DESC')
        ->range(0, 1);

      foreach ($msgSourceIdCols as $index => $col) {
        if (isset($sourceIds[$index])) {
          $query->condition($col, $sourceIds[$index]);
        }
      }

      $message = $query->execute()->fetchField();
      return $message !== FALSE ? (string) $message : '';
    }
    catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Checks if the current user can reset failed items for a migration.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return bool
   *   TRUE if the user can reset failed items.
   */
  protected function canResetFailedItems(string $migrationId): bool {
    $account = $this->currentUser();

    // If migrate_permissions is installed, check run permission.
    if ($this->migrateAccessCheck !== NULL) {
      return $this->migrateAccessCheck->canRunMigration($account, $migrationId);
    }

    // Fallback: check admin permissions.
    return $account->hasPermission('administer migrations') || $account->hasPermission('administer site configuration');
  }

  /**
   * Builds the filter form for failed items.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param string $search
   *   Current search value.
   *
   * @return array
   *   A render array.
   */
  protected function buildFailedItemFilterForm(string $migrationId, string $search): array {
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
            '#value' => $this->t('Source ID'),
          ],
          'input' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'text',
              'name' => 'search',
              'id' => 'edit-search',
              'value' => $search,
              'placeholder' => $this->t('Search source ID'),
              'class' => ['form-text'],
            ],
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
   * Loads a migration plugin or throws 404.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   *
   * @return \Drupal\migrate\Plugin\MigrationInterface
   *   The migration plugin instance.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  protected function loadMigration(string $migration_id): MigrationInterface {
    $migrations = $this->migrationPluginManager->createInstances([$migration_id]);
    if (empty($migrations[$migration_id])) {
      throw new NotFoundHttpException();
    }

    // Check per-migration view permission if migrate_permissions is enabled.
    if ($this->migrateAccessCheck !== NULL && !$this->migrateAccessCheck->canViewMigration($this->currentUser(), $migration_id)) {
      throw new AccessDeniedHttpException();
    }

    return $migrations[$migration_id];
  }

  /**
   * Gets the current status of a migration.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration plugin instance.
   *
   * @return string
   *   The status string.
   */
  protected function getMigrationStatus(MigrationInterface $migration): string {
    $statusString = (string) $migration->getStatusLabel();
    $statusMap = [
      'Idle' => 'idle',
      'Importing' => 'importing',
      'Rolling back' => 'rolling_back',
      'Stopping' => 'idle',
      'Disabled' => 'idle',
    ];

    $migrationStatus = $statusMap[$statusString] ?? 'idle';

    if ($migrationStatus === 'idle') {
      $lastRun = $this->database->select('migrate_suite_run_log', 'r')
        ->fields('r', ['status'])
        ->condition('migration_id', $migration->id())
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
   * Gets the last run stats from the run log.
   *
   * @param string $migrationId
   *   The migration ID.
   *
   * @return object|null
   *   The last run log row, or NULL if never run.
   */
  protected function getLastRunStats(string $migrationId): ?object {
    $result = $this->database->select('migrate_suite_run_log', 'r')
      ->fields('r')
      ->condition('migration_id', $migrationId)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $result ?: NULL;
  }

  /**
   * Builds a summary string for the last run.
   *
   * @param object $lastRun
   *   The last run log row.
   *
   * @return string
   *   A formatted summary string.
   */
  protected function buildLastRunSummary(object $lastRun): string {
    $started = $this->dateFormatter->format((int) $lastRun->started, 'short');
    $parts = [$this->t('Started: @date', ['@date' => $started])];

    if ($lastRun->finished) {
      $finished = $this->dateFormatter->format((int) $lastRun->finished, 'short');
      $parts[] = $this->t('Finished: @date', ['@date' => $finished]);
    }

    $parts[] = $this->t('Processed: @count', ['@count' => $lastRun->items_processed]);
    $parts[] = $this->t('Created: @count', ['@count' => $lastRun->items_created]);
    $parts[] = $this->t('Updated: @count', ['@count' => $lastRun->items_updated]);
    $parts[] = $this->t('Failed: @count', ['@count' => $lastRun->items_failed]);

    return implode(' | ', $parts);
  }

  /**
   * Builds a colored status badge.
   *
   * @param string $status
   *   The migration status.
   *
   * @return array
   *   A render array with the badge markup.
   */
  protected function buildStatusBadge(string $status): array {
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
   * Builds the imported items section with filters and pager.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  protected function buildImportedItemsSection(string $migrationId, Request $request): array {
    $search = $request->query->get('search', '');
    $statusFilter = $request->query->get('status', '');
    $page = max(0, (int) $request->query->get('page', 0));

    $table = 'migrate_map_' . $migrationId;
    if (!$this->database->schema()->tableExists($table)) {
      return [
        '#markup' => '<p>' . $this->t('No map table exists for this migration. The migration may not have been run yet.') . '</p>',
      ];
    }

    // Build the query.
    $query = $this->database->select($table, 'map')
      ->fields('map');

    // Apply status filter.
    $statusMap = [
      'imported' => 0,
      'needs_update' => 1,
      'failed' => 2,
    ];
    if ($statusFilter !== '' && isset($statusMap[$statusFilter])) {
      $query->condition('source_row_status', $statusMap[$statusFilter]);
    }

    // Apply search filter on source IDs.
    if ($search !== '') {
      $or = $query->orConditionGroup();
      // Search across sourceid columns (up to 5).
      for ($i = 1; $i <= 5; $i++) {
        $col = 'sourceid' . $i;
        if ($this->database->schema()->fieldExists($table, $col)) {
          $or->condition($col, '%' . $this->database->escapeLike($search) . '%', 'LIKE');
        }
      }
      $query->condition($or);
    }

    // Count total for pager.
    $countQuery = clone $query;
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    // Apply pager.
    $query->range($page * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE);
    $query->orderBy('last_imported', 'DESC');

    $rows = $query->execute()->fetchAll();

    // Detect source ID and destination ID columns.
    $sourceIdCols = [];
    $destIdCols = [];
    for ($i = 1; $i <= 9; $i++) {
      if ($this->database->schema()->fieldExists($table, 'sourceid' . $i)) {
        $sourceIdCols[] = 'sourceid' . $i;
      }
      if ($this->database->schema()->fieldExists($table, 'destid' . $i)) {
        $destIdCols[] = 'destid' . $i;
      }
    }

    // Build filter form.
    $build = [];
    $build['filters'] = $this->buildItemFilterForm($migrationId, $search, $statusFilter);

    // Build table rows.
    $tableRows = [];
    foreach ($rows as $row) {
      $sourceIds = [];
      foreach ($sourceIdCols as $col) {
        if (isset($row->$col) && $row->$col !== NULL) {
          $sourceIds[] = $row->$col;
        }
      }

      $destIds = [];
      foreach ($destIdCols as $col) {
        if (isset($row->$col) && $row->$col !== NULL) {
          $destIds[] = $row->$col;
        }
      }

      $destDisplay = implode(', ', $destIds) ?: $this->t('N/A');

      // Try to link to the entity if we have a single destination ID.
      if (count($destIds) === 1) {
        $destDisplay = $this->buildEntityLink($migrationId, (string) $destIds[0]) ?: $destDisplay;
      }

      $statusLabel = $this->getRowStatusLabel((int) $row->source_row_status);

      $lastImported = !empty($row->last_imported)
        ? $this->dateFormatter->format((int) $row->last_imported, 'short')
        : $this->t('Unknown');

      $tableRows[] = [
        implode(', ', $sourceIds),
        ['data' => ['#markup' => (string) $destDisplay]],
        $statusLabel,
        $lastImported,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Source ID(s)'),
        $this->t('Destination entity'),
        $this->t('Status'),
        $this->t('Last import'),
      ],
      '#rows' => $tableRows,
      '#empty' => $this->t('No imported items found.'),
    ];

    // Build pager.
    if ($total > self::ITEMS_PER_PAGE) {
      $build['pager'] = $this->buildPager($migrationId, $page, $total, $request);
    }

    return $build;
  }

  /**
   * Builds the messages section with filters and pager.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  protected function buildMessagesSection(string $migrationId, Request $request): array {
    $severityFilter = $request->query->get('severity', '');
    $grouped = $request->query->get('group', '') === '1';
    $page = max(0, (int) $request->query->get('page', 0));

    $table = 'migrate_message_' . $migrationId;
    if (!$this->database->schema()->tableExists($table)) {
      return [
        '#markup' => '<p>' . $this->t('No message table exists for this migration.') . '</p>',
      ];
    }

    $build = [];

    // Severity summary badges.
    $severityCounts = $this->messageQuery->getSeverityCounts($migrationId);
    $build['severity_summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['migrate-severity-summary']],
      'error' => [
        '#markup' => '<span class="migrate-badge migrate-badge--error">' . $this->t('Errors: @count', ['@count' => $severityCounts['error']]) . '</span> ',
      ],
      'warning' => [
        '#markup' => '<span class="migrate-badge migrate-badge--warning">' . $this->t('Warnings: @count', ['@count' => $severityCounts['warning']]) . '</span> ',
      ],
      'notice' => [
        '#markup' => '<span class="migrate-badge migrate-badge--notice">' . $this->t('Notices: @count', ['@count' => $severityCounts['notice']]) . '</span>',
      ],
    ];

    // Build severity filter.
    $build['filters'] = $this->buildMessageFilterForm($migrationId, $severityFilter);

    // View toggle.
    $toggleUrl = Url::fromRoute('migrate_admin.migration_messages', ['migration_id' => $migrationId], [
      'query' => ['group' => $grouped ? '0' : '1', 'severity' => $severityFilter],
    ]);
    $build['view_toggle'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      'link' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $grouped ? $this->t('Show individual messages') : $this->t('Group by message text'),
        '#attributes' => ['href' => $toggleUrl->toString()],
      ],
    ];

    if ($grouped) {
      $build['table'] = $this->buildGroupedMessagesTable($migrationId, $page);
    }
    else {
      $build['table'] = $this->buildIndividualMessagesTable($migrationId, $severityFilter, $page);
    }

    // Build pager.
    $severityMap = ['notice' => 6, 'warning' => 4, 'error' => 3];
    $countQuery = $this->database->select($table, 'msg');
    if ($severityFilter !== '' && isset($severityMap[$severityFilter])) {
      $countQuery->condition('level', $severityMap[$severityFilter]);
    }
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    if ($total > self::ITEMS_PER_PAGE) {
      $build['pager'] = $this->buildPager($migrationId, $page, $total, $request, 'messages');
    }

    return $build;
  }

  /**
   * Builds the individual (non-grouped) messages table.
   */
  protected function buildIndividualMessagesTable(string $migrationId, string $severityFilter, int $page): array {
    $table = 'migrate_message_' . $migrationId;

    $query = $this->database->select($table, 'msg')
      ->fields('msg');

    $severityMap = ['notice' => 6, 'warning' => 4, 'error' => 3];
    if ($severityFilter !== '' && isset($severityMap[$severityFilter])) {
      $query->condition('level', $severityMap[$severityFilter]);
    }

    $query->range($page * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE);
    $rows = $query->execute()->fetchAll();

    // Detect source ID columns.
    $sourceIdCols = [];
    for ($i = 1; $i <= 9; $i++) {
      $col = 'src_' . $i;
      if ($this->database->schema()->fieldExists($table, $col)) {
        $sourceIdCols[] = $col;
      }
    }

    $tableRows = [];
    foreach ($rows as $row) {
      $sourceIds = [];
      foreach ($sourceIdCols as $col) {
        if (isset($row->$col) && $row->$col !== NULL) {
          $sourceIds[] = $row->$col;
        }
      }

      $severityIcon = $this->getSeverityIcon((int) $row->level);

      $tableRows[] = [
        implode(', ', $sourceIds),
        ['data' => ['#markup' => $severityIcon]],
        $row->message ?? '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Source ID(s)'),
        $this->t('Severity'),
        $this->t('Message'),
      ],
      '#rows' => $tableRows,
      '#empty' => $this->t('No messages found.'),
    ];
  }

  /**
   * Builds the grouped messages table.
   */
  protected function buildGroupedMessagesTable(string $migrationId, int $page): array {
    $groups = $this->messageQuery->getGroupedMessages(
      $migrationId,
      self::ITEMS_PER_PAGE,
      $page * self::ITEMS_PER_PAGE,
    );

    $tableRows = [];
    foreach ($groups as $group) {
      $severityIcon = $this->getSeverityIcon((int) $group->level);

      $tableRows[] = [
        ['data' => ['#markup' => $severityIcon]],
        $group->message ?? '',
        $group->count,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Severity'),
        $this->t('Message'),
        $this->t('Count'),
      ],
      '#rows' => $tableRows,
      '#empty' => $this->t('No messages found.'),
    ];
  }

  /**
   * Builds the filter form for imported items.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param string $search
   *   Current search value.
   * @param string $statusFilter
   *   Current status filter.
   *
   * @return array
   *   A render array.
   */
  protected function buildItemFilterForm(string $migrationId, string $search, string $statusFilter): array {
    $statusOptions = [
      '' => $this->t('- All statuses -'),
      'imported' => $this->t('Imported'),
      'needs_update' => $this->t('Needs update'),
      'failed' => $this->t('Failed'),
    ];

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
            '#value' => $this->t('Source ID'),
          ],
          'input' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'text',
              'name' => 'search',
              'id' => 'edit-search',
              'value' => $search,
              'placeholder' => $this->t('Search source ID'),
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
   * Builds the filter form for messages.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param string $severityFilter
   *   Current severity filter.
   *
   * @return array
   *   A render array.
   */
  protected function buildMessageFilterForm(string $migrationId, string $severityFilter): array {
    $severityOptions = [
      '' => $this->t('- All severities -'),
      'notice' => $this->t('Notice'),
      'warning' => $this->t('Warning'),
      'error' => $this->t('Error'),
    ];

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
        'severity' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['form-item']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#attributes' => ['for' => 'edit-severity'],
            '#value' => $this->t('Severity'),
          ],
          'select' => [
            '#type' => 'html_tag',
            '#tag' => 'select',
            '#attributes' => [
              'name' => 'severity',
              'id' => 'edit-severity',
              'class' => ['form-select'],
            ],
            'options' => $this->buildSelectOptions($severityOptions, $severityFilter),
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
   * Builds a simple pager.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param int $currentPage
   *   The current page number (0-indexed).
   * @param int $total
   *   The total number of items.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $tab
   *   The current tab ('items' or 'messages').
   *
   * @return array
   *   A render array for the pager.
   */
  protected function buildPager(string $migrationId, int $currentPage, int $total, Request $request, string $tab = 'items'): array {
    $totalPages = (int) ceil($total / self::ITEMS_PER_PAGE);
    $routeMap = [
      'messages' => 'migrate_admin.migration_messages',
      'failed' => 'migrate_admin.migration_failed_items',
    ];
    $route = $routeMap[$tab] ?? 'migrate_admin.migration_detail';

    $queryParams = $request->query->all();
    $links = [];

    if ($currentPage > 0) {
      $queryParams['page'] = $currentPage - 1;
      $links['previous'] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Previous'),
        '#attributes' => [
          'href' => Url::fromRoute($route, ['migration_id' => $migrationId], ['query' => $queryParams])->toString(),
          'class' => ['button'],
        ],
      ];
    }

    $links['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Page @current of @total (@items items)', [
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
          'href' => Url::fromRoute($route, ['migration_id' => $migrationId], ['query' => $queryParams])->toString(),
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
   * Gets a human-readable status label for a row status.
   *
   * @param int $status
   *   The source_row_status value.
   *
   * @return string
   *   The status label.
   */
  protected function getRowStatusLabel(int $status): string {
    $labels = [
      0 => (string) $this->t('Imported'),
      1 => (string) $this->t('Needs update'),
      2 => (string) $this->t('Failed'),
    ];

    return $labels[$status] ?? (string) $this->t('Unknown');
  }

  /**
   * Gets a severity icon for a message level.
   *
   * @param int $level
   *   The RFC 5424 severity level.
   *
   * @return string
   *   HTML markup for the severity icon.
   */
  protected function getSeverityIcon(int $level): string {
    // RFC 5424 levels: 3=error, 4=warning, 5=notice, 6=info, 7=debug.
    if ($level <= 3) {
      return '<span class="badge color--error">' . $this->t('Error') . '</span>';
    }
    elseif ($level <= 4) {
      return '<span class="badge color--warning">' . $this->t('Warning') . '</span>';
    }
    return '<span class="badge color--success">' . $this->t('Notice') . '</span>';
  }

  /**
   * Attempts to build a link to the destination entity.
   *
   * @param string $migrationId
   *   The migration ID.
   * @param string $destId
   *   The destination entity ID.
   *
   * @return string|null
   *   The HTML link markup, or NULL if not possible.
   */
  protected function buildEntityLink(string $migrationId, string $destId): ?string {
    try {
      $migrations = $this->migrationPluginManager->createInstances([$migrationId]);
      if (empty($migrations[$migrationId])) {
        return NULL;
      }
      $migration = $migrations[$migrationId];
      $definition = $migration->getPluginDefinition();
      $destPlugin = $definition['destination']['plugin'] ?? '';

      // Extract entity type from destination plugin (e.g., 'entity:node').
      if (str_starts_with($destPlugin, 'entity:')) {
        $entityTypeId = substr($destPlugin, 7);
        $entityTypeManager = \Drupal::entityTypeManager();
        if ($entityTypeManager->hasDefinition($entityTypeId)) {
          $entity = $entityTypeManager->getStorage($entityTypeId)->load($destId);
          if ($entity && $entity->hasLinkTemplate('canonical')) {
            $url = $entity->toUrl()->toString();
            $label = method_exists($entity, 'label') ? $entity->label() : $destId;
            return '<a href="' . $url . '">' . htmlspecialchars((string) $label, ENT_QUOTES) . ' (' . $destId . ')</a>';
          }
        }
      }
    }
    catch (\Exception $e) {
      // Fall through to return NULL.
    }

    return NULL;
  }

}
