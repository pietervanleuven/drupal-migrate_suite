<?php

/**
 * @file
 * Views data hooks for Migrate Views.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function migrate_views_views_data(): array {
  $data = [];

  $data['migrate_suite_run_log'] = [
    'table' => [
      'group' => t('Migration Run Log'),
      'base' => [
        'field' => 'id',
        'title' => t('Migration Run Log'),
        'help' => t('Stores import and rollback run history for migrations.'),
      ],
    ],
    'id' => [
      'title' => t('Run ID'),
      'help' => t('The unique run log entry ID.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
      'argument' => ['id' => 'numeric'],
    ],
    'migration_id' => [
      'title' => t('Migration ID'),
      'help' => t('The migration plugin ID.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'migrate_views_migration_id'],
      'argument' => ['id' => 'string'],
    ],
    'operation' => [
      'title' => t('Operation'),
      'help' => t('The operation type: import or rollback.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'status' => [
      'title' => t('Status'),
      'help' => t('The run status: running, completed, or failed.'),
      'field' => ['id' => 'migrate_views_status'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'started' => [
      'title' => t('Started'),
      'help' => t('Timestamp when the run started.'),
      'field' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
    ],
    'finished' => [
      'title' => t('Finished'),
      'help' => t('Timestamp when the run finished.'),
      'field' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
    ],
    'items_processed' => [
      'title' => t('Items processed'),
      'help' => t('Number of items processed.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'items_created' => [
      'title' => t('Items created'),
      'help' => t('Number of items created.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'items_updated' => [
      'title' => t('Items updated'),
      'help' => t('Number of items updated.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'items_failed' => [
      'title' => t('Items failed'),
      'help' => t('Number of items that failed.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'items_deleted' => [
      'title' => t('Items deleted'),
      'help' => t('Number of items deleted (rollback).'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'source_count' => [
      'title' => t('Source count'),
      'help' => t('Source item count at time of run.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'source_hash' => [
      'title' => t('Source hash'),
      'help' => t('SHA-256 fingerprint of source data.'),
      'field' => ['id' => 'standard'],
    ],
  ];

  return $data;
}
