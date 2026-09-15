<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\migrate_permissions\MigrateAccessCheck;
use Drupal\migrate_suite\Service\MigrateMessageQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for exporting migration messages to CSV.
 */
class MessageExportController extends ControllerBase {

  /**
   * Leading characters that spreadsheet applications treat as formulas.
   *
   * Excel, LibreOffice Calc and Google Sheets all interpret a cell value
   * starting with any of these characters as a formula. Migration messages
   * can contain attacker-influenced source data, so every exported field
   * is checked against this list before being written out.
   */
  protected const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

  /**
   * Constructs a MessageExportController object.
   *
   * @param \Drupal\migrate_suite\Service\MigrateMessageQuery $messageQuery
   *   The migrate message query service.
   * @param \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\migrate_permissions\MigrateAccessCheck|null $migrateAccessCheck
   *   The access check, or NULL if migrate_permissions is not installed.
   */
  public function __construct(
    protected readonly MigrateMessageQuery $messageQuery,
    protected readonly MigrationPluginManagerInterface $migrationPluginManager,
    protected readonly ?MigrateAccessCheck $migrateAccessCheck,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $migrateAccessCheck = $container->get('module_handler')->moduleExists('migrate_permissions')
      ? $container->get('migrate_permissions.access_check')
      : NULL;

    return new static(
      $container->get('migrate_suite.message_query'),
      $container->get('plugin.manager.migration'),
      $migrateAccessCheck,
    );
  }

  /**
   * Exports migration messages as a CSV download.
   *
   * @param string $migration_id
   *   The migration plugin ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   A streamed CSV response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown if the migration ID does not resolve to a migration plugin.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown if the current user cannot view the migration.
   */
  public function exportCsv(string $migration_id, Request $request): StreamedResponse {
    $migrations = $this->migrationPluginManager->createInstances([$migration_id]);
    if (empty($migrations[$migration_id])) {
      throw new NotFoundHttpException();
    }

    // Apply the same per-migration view permission the messages page
    // itself enforces, so a user who cannot view a migration cannot export
    // its messages either. Degrades to no additional check (beyond the
    // route's base permission) when migrate_permissions is not installed,
    // matching MigrationDetailController::loadMigration().
    if ($this->migrateAccessCheck !== NULL && !$this->migrateAccessCheck->canViewMigration($this->currentUser(), $migration_id)) {
      throw new AccessDeniedHttpException();
    }

    $severityFilter = $request->query->get('severity', '');
    $severityMap = ['notice' => 6, 'warning' => 4, 'error' => 3];
    $severity = $severityMap[$severityFilter] ?? NULL;

    $levelLabels = [3 => 'Error', 4 => 'Warning', 6 => 'Notice'];

    $messageQuery = $this->messageQuery;

    $response = new StreamedResponse(function () use ($migration_id, $severity, $levelLabels, $messageQuery) {
      $handle = fopen('php://output', 'w');
      fputcsv($handle, ['Source ID(s)', 'Severity', 'Message']);

      foreach ($messageQuery->getAllMessages($migration_id, $severity) as $row) {
        $sourceIds = [];
        for ($i = 1; $i <= 9; $i++) {
          $col = 'src_' . $i;
          if (isset($row->$col) && $row->$col !== NULL) {
            $sourceIds[] = $row->$col;
          }
        }

        fputcsv($handle, [
          $this->sanitizeCsvField(implode(', ', $sourceIds)),
          $this->sanitizeCsvField($levelLabels[(int) $row->level] ?? 'Unknown'),
          $this->sanitizeCsvField((string) ($row->message ?? '')),
        ]);
      }

      fclose($handle);
    });

    // Route the raw (potentially derivative, colon-containing) migration ID
    // through makeDisposition() rather than hand-building the header, so it
    // cannot inject extra header directives via quotes or newlines.
    $filename = 'messages-' . $migration_id . '-' . date('Y-m-d') . '.csv';
    $disposition = $response->headers->makeDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename,
      'messages.csv',
    );

    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', $disposition);

    return $response;
  }

  /**
   * Neutralizes potential CSV formula injection in a field value.
   *
   * A value beginning with =, +, -, @, a tab, or a carriage return is
   * interpreted as a formula by Excel, LibreOffice, and Google Sheets.
   * Prefixing it with a single quote forces it to be treated as inert text
   * when the file is opened in a spreadsheet application.
   *
   * @param string $value
   *   The raw field value.
   *
   * @return string
   *   The sanitized field value.
   */
  protected function sanitizeCsvField(string $value): string {
    if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, TRUE)) {
      return "'" . $value;
    }
    return $value;
  }

}
