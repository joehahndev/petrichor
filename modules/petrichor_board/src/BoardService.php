<?php

declare(strict_types=1);

namespace Drupal\petrichor_board;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\petrichor\ConcurrencyGuardTrait;
use Drupal\petrichor_projects\ProjectService;
use Drupal\petrichor_tasks\TaskService;

/**
 * Domain service for the board — columns, card order, and drops.
 *
 * Owns manual order and nothing else. Every task read and write goes through
 * TaskService, which is what keeps this layer and the controller above it
 * free of entity queries. Order lives in the petrichor_board_order side
 * table, so a pure reorder never saves the entity and never churns `changed`
 * — the timestamp list_tasks sorts by and if_unchanged_since compares.
 */
final class BoardService {

  use ConcurrencyGuardTrait;

  /**
   * Gap left between adjacent weights.
   *
   * Midpoint insertion can halve a 1024 gap ten times before it closes, so a
   * column rebalance costs a rewrite of at most COLUMN_CAP rows and fires
   * roughly one drop in ten thousand.
   */
  private const SPACING = 1024;

  /**
   * Cards loaded per column.
   *
   * Order therefore applies to the loaded page only; the surplus is announced
   * through the column note rather than silently dropped.
   */
  private const COLUMN_CAP = 25;

  public function __construct(
    protected TaskService $tasks,
    protected Connection $database,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected ProjectService $projects,
  ) {}

  /**
   * One column per status, each capped and truncation-annotated.
   *
   * @return array
   *   ['project' => ['id' => int, 'title' => string], 'columns' => list of
   *   ['id' => string, 'label' => string, 'cards' => list, 'total' => int,
   *   'note' => string|null]].
   */
  public function getBoard(int $project_id): array {
    // Read through ProjectService rather than entity storage so this class
    // keeps its no-entity-query guarantee. petrichor_tasks hard-depends on
    // petrichor_projects, so the service is always present.
    $project = $this->projects->getProject($project_id);
    $title = (string) $project['title'];
    $today = date('Y-m-d');

    $columns = [];
    foreach ($this->tasks->statusOptions() as $status => $label) {
      $ordered = $this->orderedIds($project_id, $status);
      $total = count($ordered);
      $page = array_slice($ordered, 0, self::COLUMN_CAP);
      $cards = array_map(
        fn(array $task): array => $this->card($task, $title, $today),
        $this->loadInOrder($page),
      );
      $columns[] = [
        'id' => $status,
        'label' => $label,
        'cards' => $cards,
        'total' => $total,
        'note' => $total > count($cards)
          ? sprintf('showing %d of %d', count($cards), $total)
          : NULL,
      ];
    }

    return [
      'project' => ['id' => (int) $project['id'], 'title' => $title],
      'columns' => $columns,
    ];
  }

  /**
   * Persists a card drop or a keyboard move.
   *
   * A status change delegates to TaskService::updateTask(), which re-checks
   * entity access and honours if_unchanged_since. Ordering writes only the
   * side table, so a reorder within one column never touches the entity.
   *
   * @throws \InvalidArgumentException
   *   Task missing, not editable, or changed since if_unchanged_since.
   * @throws \DomainException
   *   Status is not one of TaskService::statusOptions().
   *
   * @return array
   *   ['task' => array, 'status' => string, 'order' => list<int>,
   *   'changed' => string].
   */
  public function moveCard(
    int $task_id,
    string $status,
    ?int $after_id = NULL,
    ?int $before_id = NULL,
    ?string $if_unchanged_since = NULL,
  ): array {
    $task = $this->tasks->getTask($task_id);

    // updateTask() sets fields with no whitelist and never calls validate(),
    // and SQLite has no CHECK constraint behind a list_string — without this
    // gate a hand-crafted POST persists a status no column can render.
    if (!array_key_exists($status, $this->tasks->statusOptions())) {
      throw new \DomainException(sprintf('Unknown status "%s".', $status));
    }

    $project_id = (int) $task['project_id'];

    // The status save and the weight write share one transaction. Splitting
    // them lets a failed weight write leave a card whose status moved but
    // whose order row was already dropped by hook_petrichor_task_update() —
    // the server would say "done" while the client rolled the card back, and
    // every reload would disagree with the user.
    $transaction = $this->database->startTransaction();
    try {
      if ($status !== $task['status']) {
        $this->tasks->updateTask($task_id, ['status' => $status]
          + ($if_unchanged_since !== NULL ? ['if_unchanged_since' => $if_unchanged_since] : []));
      }
      else {
        // A pure reorder skips updateTask() and would otherwise skip its guard
        // with it. Same shared helper, so callers get one conflict shape
        // whichever path ran — and a no-op when the token is absent.
        $this->assertUnchangedSince($task['changed'], $if_unchanged_since, 'task');
      }

      $ids = array_values(array_filter(
        $this->orderedIds($project_id, $status),
        static fn(int $id): bool => $id !== $task_id,
      ));

      // Neighbours are client-supplied. Anything outside this column is
      // discarded rather than trusted: weights() reads the side table
      // directly, so an id from a project the caller cannot even view would
      // otherwise seed the arithmetic and let them place a card at a chosen
      // position — or probe whether a foreign task has been ordered.
      $after_id = $after_id !== NULL && in_array($after_id, $ids, TRUE) ? $after_id : NULL;
      $before_id = $before_id !== NULL && in_array($before_id, $ids, TRUE) ? $before_id : NULL;

      $neighbours = array_values(array_filter([$after_id, $before_id]));
      $weights = $this->weights($neighbours);
      $exhausted = $after_id !== NULL && $before_id !== NULL
        && isset($weights[$after_id], $weights[$before_id])
        && $weights[$before_id] - $weights[$after_id] <= 1;
      if (count($weights) !== count($neighbours) || $exhausted) {
        // A neighbour with no row is unplaced, and an unplaced card sorts
        // above every placed one — inserting relative to it is meaningless
        // until the whole column is numbered.
        $this->ensureWeights($ids);
        $weights = $this->weights($neighbours);
      }

      // A neighbour still unresolved after the rebalance is one the client
      // named from another column or a card since deleted; degrading it to
      // "no neighbour on that side" beats writing arithmetic on a null.
      $after = $after_id !== NULL ? ($weights[$after_id] ?? NULL) : NULL;
      $before = $before_id !== NULL ? ($weights[$before_id] ?? NULL) : NULL;
      if ($after === NULL && $before === NULL) {
        $column = $this->weights($ids);
        $weight = $column ? min($column) - self::SPACING : self::SPACING;
      }
      elseif ($after === NULL) {
        $weight = $before - self::SPACING;
      }
      elseif ($before === NULL) {
        $weight = $after + self::SPACING;
      }
      else {
        $weight = intdiv($after + $before, 2);
      }

      // Written after updateTask() on purpose: hook_petrichor_task_update()
      // drops the order row on a status change, and it must not delete the
      // row this move just placed.
      $this->database->merge('petrichor_board_order')
        ->key('task_id', $task_id)
        ->fields(['weight' => $weight])
        ->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    // SQLite's write lock is database-wide; release it before the read-back
    // so an in-flight MCP write is not queued behind board rendering.
    unset($transaction);

    $this->cacheTagsInvalidator->invalidateTags(['petrichor_board_order']);

    $fresh = $this->tasks->getTask($task_id);
    return [
      'task' => $fresh,
      'status' => $status,
      'order' => $this->columnOrder($project_id, $status),
      'changed' => $fresh['changed'],
    ];
  }

  /**
   * The visible page of one column, in board order.
   *
   * Capped like the rendered column so the client reconciles against exactly
   * what it can see.
   */
  public function columnOrder(int $project_id, string $status): array {
    return array_slice($this->orderedIds($project_id, $status), 0, self::COLUMN_CAP);
  }

  /**
   * Every task id in one column, unplaced first then ascending by weight.
   *
   * Ordering happens over the whole column, never over a page: picking the
   * page first (by recency, the only thing an entity query can sort on here)
   * would drop exactly the card a user had pinned to the top of a long
   * column, and would let ensureWeights() renumber a recency slice while
   * off-page cards kept old weights that then interleave arbitrarily.
   *
   * @return int[]
   *   Task ids in board order, uncapped — callers page it themselves.
   */
  private function orderedIds(int $project_id, string $status): array {
    $ids = $this->tasks->columnTaskIds($project_id, $status);
    $weights = $this->weights($ids);

    // Unplaced cards keep the query's changed-DESC order and sort above every
    // placed one, so a card an agent just flipped into this status surfaces
    // at the top rather than at a stale historical position.
    $unplaced = [];
    $placed = [];
    foreach ($ids as $id) {
      if (isset($weights[$id])) {
        $placed[$id] = $weights[$id];
      }
      else {
        $unplaced[] = $id;
      }
    }
    asort($placed);
    return array_merge($unplaced, array_map(intval(...), array_keys($placed)));
  }

  /**
   * Loads task stubs for the given ids, preserving the given order.
   */
  private function loadInOrder(array $ids): array {
    if (!$ids) {
      return [];
    }
    $result = $this->tasks->listTasks(['ids' => $ids], count($ids));
    $by_id = [];
    foreach ($result['items'] as $item) {
      $by_id[(int) $item['id']] = $item;
    }
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($by_id[$id])) {
        $ordered[] = $by_id[$id];
      }
    }
    return $ordered;
  }

  /**
   * Card props for one task stub.
   *
   * `overdue` is resolved here against a server-side 'Y-m-d' so no layer
   * downstream — Twig or JS — has to parse a date.
   */
  private function card(array $task, string $project_title, string $today): array {
    $due = $task['due_date'] ?? NULL;
    return [
      'id' => (int) $task['id'],
      'title' => $task['title'],
      'url' => Url::fromRoute('entity.petrichor_task.canonical', ['petrichor_task' => $task['id']])->toString(),
      'move_url' => Url::fromRoute('petrichor_board.move', ['petrichor_task' => $task['id']])->toString(),
      'status' => $task['status'],
      'priority' => $task['priority'],
      'assignee' => $task['assignee'] ?? NULL,
      'due_date' => $due,
      'overdue' => $due !== NULL && $due < $today,
      'blocked' => !empty($task['blocked']),
      'blocked_note' => $task['blocked_note'] ?? NULL,
      'changed' => $task['changed'],
      'project_title' => $project_title,
      'reason' => $task['reason'] ?? NULL,
    ];
  }

  /**
   * Weights for the given task IDs. IDs with no row are simply absent.
   */
  private function weights(array $task_ids): array {
    if (!$task_ids) {
      return [];
    }
    $rows = $this->database->select('petrichor_board_order', 'o')
      ->fields('o', ['task_id', 'weight'])
      ->condition('task_id', $task_ids, 'IN')
      ->execute()
      ->fetchAllKeyed();
    return array_map(intval(...), $rows);
  }

  /**
   * Numbers a whole column in display order, closing every gap.
   *
   * Called only from inside moveCard()'s transaction and only for one column
   * — SQLite locks the whole database for a write, so a board-wide renumber
   * here would stall every concurrent agent call.
   */
  private function ensureWeights(array $ordered_ids): void {
    foreach (array_values($ordered_ids) as $index => $task_id) {
      $this->database->merge('petrichor_board_order')
        ->key('task_id', (int) $task_id)
        ->fields(['weight' => ($index + 1) * self::SPACING])
        ->execute();
    }
  }

}
