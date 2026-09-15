<?php

declare(strict_types=1);

namespace Drupal\migrate_admin;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ListenableEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Migrate executable that stops itself after a bounded number of rows.
 *
 * Core's \Drupal\migrate\MigrateExecutable::import() and ::rollback() each
 * process their entire source/map in one uninterrupted loop; there is no
 * "process N items and return" API. The only lever core provides is
 * \Drupal\migrate\Plugin\MigrationInterface::interruptMigration(), which
 * sets the migration status to MigrationInterface::STATUS_STOPPING. Both
 * loops check that status after every row (import()) or every map entry
 * (rollback()) and, when set, break out and return whatever result was
 * passed to interruptMigration() (see the `STATUS_STOPPING` checks in
 * core's MigrateExecutable::import()/rollback(), and
 * \Drupal\migrate\Plugin\Migration::interruptMigration(), which is what
 * actually flips the status).
 *
 * This class counts rows via the MigratePostRowSave/PostRowDelete events
 * (the only per-row hook points core dispatches during those loops) and
 * calls interruptMigration() once a caller-supplied limit is reached, so a
 * batch operation can safely call import()/rollback() repeatedly, a fixed
 * number of rows at a time, instead of in a single unbounded call.
 *
 * Rows that are already present in the migration's ID map are skipped by
 * the source plugin on the next call (see
 * \Drupal\migrate\Plugin\migrate\source\SourcePluginBase::next(), which
 * only accepts a row when it has no map entry, needs update, is above the
 * high water mark, or has changed) — so re-running import() from the start
 * of the source on each chunk does not redo already-imported work.
 */
class MigrateBatchExecutable extends MigrateExecutable {

  /**
   * Number of rows processed during the most recent import()/rollback().
   */
  protected int $itemsProcessed = 0;

  /**
   * Constructs a MigrateBatchExecutable.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration to run.
   * @param \Drupal\migrate\MigrateMessageInterface $message
   *   The migrate message service.
   * @param int $itemLimit
   *   The maximum number of rows to process before interrupting the
   *   migration and returning control to the caller.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface|null $eventDispatcher
   *   (optional) The event dispatcher.
   */
  public function __construct(
    MigrationInterface $migration,
    MigrateMessageInterface $message,
    protected int $itemLimit,
    ?EventDispatcherInterface $eventDispatcher = NULL,
  ) {
    parent::__construct($migration, $message, $eventDispatcher);
  }

  /**
   * {@inheritdoc}
   */
  public function import() {
    $this->itemsProcessed = 0;
    $dispatcher = $this->getListenableEventDispatcher();
    $dispatcher?->addListener(MigrateEvents::POST_ROW_SAVE, [$this, 'onItemProcessed']);
    try {
      return parent::import();
    }
    finally {
      $dispatcher?->removeListener(MigrateEvents::POST_ROW_SAVE, [$this, 'onItemProcessed']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rollback() {
    $this->itemsProcessed = 0;
    $dispatcher = $this->getListenableEventDispatcher();
    $dispatcher?->addListener(MigrateEvents::POST_ROW_DELETE, [$this, 'onItemProcessed']);
    try {
      return parent::rollback();
    }
    finally {
      $dispatcher?->removeListener(MigrateEvents::POST_ROW_DELETE, [$this, 'onItemProcessed']);
    }
  }

  /**
   * Gets the event dispatcher, if listeners can be registered on it.
   *
   * Core types both the $eventDispatcher property and getEventDispatcher()
   * as \Symfony\Contracts\EventDispatcher\EventDispatcherInterface, which
   * declares only dispatch() — addListener() and removeListener() belong to
   * the Component interface. At runtime the container always supplies
   * \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher, which
   * does implement the Component interface, but narrowing it explicitly
   * keeps static analysis honest.
   *
   * If a dispatcher without listener registration were ever injected, the
   * item limit simply would not apply: import() would run to completion and
   * return a terminal result, which the batch operation already treats as
   * "finished". That degrades to the previous unchunked behaviour rather
   * than looping.
   *
   * @return \Symfony\Component\EventDispatcher\EventDispatcherInterface|null
   *   The dispatcher, or NULL if listeners cannot be registered on it.
   */
  protected function getListenableEventDispatcher(): ?ListenableEventDispatcherInterface {
    $dispatcher = $this->getEventDispatcher();
    return $dispatcher instanceof ListenableEventDispatcherInterface ? $dispatcher : NULL;
  }

  /**
   * Reacts to a single row having been saved or deleted.
   *
   * Counts the row and, once the configured item limit is reached,
   * interrupts the migration so the in-progress import()/rollback() call
   * returns after the current row instead of continuing to the end of the
   * source or map.
   */
  public function onItemProcessed(): void {
    $this->itemsProcessed++;
    if ($this->itemsProcessed >= $this->itemLimit) {
      $this->migration->interruptMigration(MigrationInterface::RESULT_INCOMPLETE);
    }
  }

  /**
   * Gets the number of rows processed during the most recent call.
   *
   * @return int
   *   The number of rows processed by the last import() or rollback() call.
   */
  public function getItemsProcessed(): int {
    return $this->itemsProcessed;
  }

}
