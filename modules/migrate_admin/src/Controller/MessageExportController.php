<?php

declare(strict_types=1);

namespace Drupal\migrate_admin\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\migrate_suite\Service\MigrateMessageQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for exporting migration messages to CSV.
 */
class MessageExportController extends ControllerBase {

  public function __construct(
    protected readonly MigrateMessageQuery $messageQuery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('migrate_suite.message_query'),
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
   */
  public function exportCsv(string $migration_id, Request $request): StreamedResponse {
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
          implode(', ', $sourceIds),
          $levelLabels[(int) $row->level] ?? 'Unknown',
          $row->message ?? '',
        ]);
      }

      fclose($handle);
    });

    $filename = 'messages-' . $migration_id . '-' . date('Y-m-d') . '.csv';
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

}
