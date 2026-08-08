<?php

declare(strict_types=1);

namespace Drupal\petrichor_tasks;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\ConcurrencyGuardTrait;
use Drupal\petrichor\ProvenanceTrait;
use Drupal\petrichor_memory\MemoryService;

/**
 * Domain service for tasks — single implementation behind MCP, UI, drush.
 */
class TaskService {

  use ConcurrencyGuardTrait;
  use ProvenanceTrait;

  /**
   * Constructs a TaskService.
   *
   * $memory is injected with '@?' in services.yml and is NULL when
   * petrichor_memory is not installed — this module does not depend on it and
   * must stay installable without it. The NULL check in getTask() is load
   * bearing; do not promote the argument to '@'.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected TimeInterface $time,
    protected ?MemoryService $memory = NULL,
  ) {}

  /**
   * Storage handler for tasks.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_task');
  }

  /**
   * Allowed status values, straight off the base field definition.
   *
   * Single source of truth for board columns, MCP enums, and validation.
   * Read from the field rather than restated here, because a second copy of
   * the list is a copy that drifts. Declaration order is the column order:
   * backlog, todo, in_progress, review, done.
   *
   * @return array<string, string>
   *   Machine name => human label.
   */
  public function statusOptions(): array {
    $fields = $this->entityFieldManager->getBaseFieldDefinitions('petrichor_task');
    return array_map(strval(...), $fields['status']->getSetting('allowed_values'));
  }

  /**
   * Tasks matching every supplied filter, newest-changed first.
   *
   * An omitted filter and an empty one mean the same thing, so a caller can
   * pass its whole argument array through unfiltered. 'blocked' is the one
   * exception: FALSE there is a real request for unblocked work, so it is
   * tested against NULL rather than for emptiness.
   */
  public function listTasks(array $filters = [], int $limit = 100): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    if (!empty($filters['ids'])) {
      $query->condition('id', array_map('intval', (array) $filters['ids']), 'IN');
    }
    if (!empty($filters['project_id'])) {
      $query->condition('project', (int) $filters['project_id']);
    }
    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    if (!empty($filters['assignee'])) {
      $query->condition('assignee', (int) $filters['assignee']);
    }
    if (!empty($filters['due_before'])) {
      $query->condition('due_date', $filters['due_before'], '<=');
    }
    if (!empty($filters['milestone_id'])) {
      $query->condition('milestone', (int) $filters['milestone_id']);
    }
    if (!empty($filters['parent_id'])) {
      $query->condition('parent', (int) $filters['parent_id']);
    }
    if (array_key_exists('blocked', $filters) && $filters['blocked'] !== NULL) {
      $query->condition('blocked', (int) (bool) $filters['blocked']);
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    return $this->listResult($items, $total, $offset);
  }

  /**
   * Memory types surfaced on a task read.
   *
   * Constraints only. A decision or a preference is something you can violate
   * without noticing, which is what makes it worth carrying uninvited;
   * 'learning' and 'reference' are material you go and look up, and putting
   * them on every task read would be noise charged to every caller.
   */
  protected const RELATED_MEMORY_TYPES = ['decision', 'preference'];

  /**
   * How many related memories ride along. Small on purpose — see getTask().
   */
  protected const RELATED_MEMORY_CAP = 3;

  /**
   * One task, plus the fields lists leave out: description, milestone, parent.
   *
   * A task that does not exist and one the caller may not view raise the same
   * error, so an id probe cannot tell them apart.
   *
   * When petrichor_memory is installed the response also carries the ids and
   * titles of active memories matching the task, scoped to its project. This
   * is the point where retrieval actually pays: get_task is read *before* the
   * work, so a decision already made lands in front of the caller in time to
   * change what they do, rather than being available to anyone who thought to
   * search — which is exactly what nobody does.
   */
  public function getTask(int $id): array {
    $task = $this->storage()->load($id);
    if (!$task || !$task->access('view')) {
      throw new \InvalidArgumentException('Task not found.');
    }
    $stub = $this->withCreated($this->stub($task), $task);
    $stub['description'] = $task->get('description')->value;
    if ($task->hasField('milestone') && $task->get('milestone')->target_id) {
      $stub['milestone_id'] = (int) $task->get('milestone')->target_id;
    }
    if ($task->get('parent')->target_id) {
      $stub['parent_id'] = (int) $task->get('parent')->target_id;
    }
    if ($this->memory) {
      // Titles only, and the key is omitted when nothing matches: this rides
      // on one of the most-called tools in the suite, and an empty array on
      // every task read is pure weight.
      $related = $this->memory->relatedTo(
        (string) $task->label(),
        (int) $task->get('project')->target_id,
        self::RELATED_MEMORY_TYPES,
        self::RELATED_MEMORY_CAP,
      );
      if ($related) {
        $stub['related_memories'] = $related;
        $stub['related_memories_note'] = 'Decisions and preferences that mention this task; call recall for the full text before working against one.';
      }
    }
    return $stub;
  }

  /**
   * Creates a task. Only title and project_id are required.
   *
   * The milestone is set after create() rather than with the other values
   * because petrichor_milestones is optional — the field is simply absent
   * when it is not installed.
   */
  public function createTask(array $values): array {
    if (empty($values['title']) || empty($values['project_id'])) {
      throw new \InvalidArgumentException('title and project_id are required.');
    }
    if (!empty($values['parent_id'])) {
      $this->assertParentAllowed((int) $values['parent_id'], (int) $values['project_id']);
    }
    $task = $this->storage()->create([
      'label' => $values['title'],
      'project' => (int) $values['project_id'],
      'status' => $values['status'] ?? 'backlog',
      'priority' => $values['priority'] ?? 'medium',
      'assignee' => $values['assignee'] ?? NULL,
      'due_date' => $values['due_date'] ?? NULL,
      'tags' => $values['tags'] ?? [],
      'parent' => $values['parent_id'] ?? NULL,
      'estimate_hours' => $values['estimate_hours'] ?? NULL,
      'blocked' => !empty($values['blocked']),
      'blocked_note' => $values['blocked_note'] ?? NULL,
      'description' => isset($values['description'])
        ? ['value' => $values['description'], 'format' => 'markdown']
        : NULL,
    ]);
    if (!empty($values['milestone_id']) && $task->hasField('milestone')) {
      $task->set('milestone', (int) $values['milestone_id']);
    }
    if (!$task->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create tasks.');
    }
    $task->save();
    return $this->stub($task);
  }

  /**
   * Applies a partial update: only keys present in $values are written.
   *
   * Presence is tested with array_key_exists() rather than isset() so that an
   * explicit NULL clears a field instead of reading as "not supplied" — that
   * is the only way a caller can unassign or unschedule a task.
   *
   * if_unchanged_since carries an ISO timestamp from a prior read; when it is
   * older than the task's own 'changed' the update is refused rather than
   * silently overwriting whoever edited in between.
   */
  public function updateTask(int $id, array $values): array {
    $task = $this->storage()->load($id);
    if (!$task || !$task->access('update')) {
      throw new \InvalidArgumentException('Task not found or not editable.');
    }
    $this->assertUnchangedSince(
      (int) $task->get('changed')->value,
      $values['if_unchanged_since'] ?? NULL,
      'task',
    );
    // Validated before anything is written: a rejected parent must not leave
    // the other fields of a partial update already applied.
    if (!empty($values['parent_id'])) {
      $this->assertParentAllowed(
        (int) $values['parent_id'],
        (int) $task->get('project')->target_id,
        $id,
      );
    }
    $map = [
      'title' => 'label',
      'status' => 'status',
      'priority' => 'priority',
      'due_date' => 'due_date',
      'assignee' => 'assignee',
      'tags' => 'tags',
      'parent_id' => 'parent',
      'estimate_hours' => 'estimate_hours',
      'blocked' => 'blocked',
      'blocked_note' => 'blocked_note',
    ];
    foreach ($map as $arg => $field) {
      if (array_key_exists($arg, $values)) {
        $task->set($field, $values[$arg]);
      }
    }
    if (array_key_exists('milestone_id', $values) && $task->hasField('milestone')) {
      $task->set('milestone', $values['milestone_id']);
    }
    if (array_key_exists('description', $values)) {
      $task->set('description', ['value' => $values['description'], 'format' => 'markdown']);
    }
    $task->save();
    return $this->stub($task);
  }

  /**
   * Walk stop for the ancestor chain.
   *
   * The cycle check below cannot loop forever on its own — it carries a
   * visited set — but a chain this deep is already meaningless as a subtask
   * hierarchy, and refusing beats walking ten thousand rows to say so.
   */
  private const MAX_PARENT_DEPTH = 32;

  /**
   * Refuses a parent that would break the hierarchy.
   *
   * `parent` is an entity reference with no constraint behind it, and it is
   * writable over MCP, so every rule worth having has to be enforced here.
   * Two things can go wrong and neither is visible afterwards:
   *
   * - A parent in another project puts a subtask on a board its parent is not
   *   on. `list_tasks` filtered by project then returns a task whose parent it
   *   never mentions, and the board shows a card with no visible relationship.
   * - A parent that is already a descendant creates a loop reachable from no
   *   root. Both tasks drop out of every parent-filtered query and stay that
   *   way until someone edits the database.
   *
   * @param int $parent_id
   *   The proposed parent.
   * @param int $project_id
   *   The project the child belongs to.
   * @param int|null $child_id
   *   The task being re-parented, or NULL when creating — a task that does not
   *   exist yet cannot be its own ancestor, so only the project check applies.
   *
   * @throws \InvalidArgumentException
   *   If the parent is missing, not viewable, in another project, or would
   *   close a cycle.
   */
  protected function assertParentAllowed(int $parent_id, int $project_id, ?int $child_id = NULL): void {
    $parent = $this->storage()->load($parent_id);
    // Same message for missing and unviewable, so an id probe cannot tell a
    // task that does not exist from one the caller may not see.
    if (!$parent || !$parent->access('view')) {
      throw new \InvalidArgumentException(sprintf('Parent task %d not found.', $parent_id));
    }
    if ((int) $parent->get('project')->target_id !== $project_id) {
      throw new \InvalidArgumentException(sprintf(
        'Parent task %d belongs to a different project; a subtask must sit in the same project as its parent.',
        $parent_id,
      ));
    }
    if ($child_id === NULL) {
      return;
    }
    if ($parent_id === $child_id) {
      throw new \InvalidArgumentException(sprintf('Task %d cannot be its own parent.', $child_id));
    }
    // Walk up from the proposed parent: if the child appears anywhere above
    // it, attaching would close the loop.
    $seen = [];
    $current = $parent_id;
    $depth = 0;
    while ($current !== NULL && !isset($seen[$current]) && $depth < self::MAX_PARENT_DEPTH) {
      if ($current === $child_id) {
        throw new \InvalidArgumentException(sprintf(
          'Task %d is a descendant of task %d; re-parenting there would create a cycle.',
          $parent_id,
          $child_id,
        ));
      }
      $seen[$current] = TRUE;
      $node = $this->storage()->load($current);
      $next = $node ? $node->get('parent')->target_id : NULL;
      $current = $next ? (int) $next : NULL;
      $depth++;
    }
  }

  /**
   * Today's focus set.
   *
   * Deliberately does NOT require due dates: a solo user rarely sets them, and
   * an empty "what should I do today" is worse than a slightly generous one.
   * In-progress work, anything due soon or overdue, and high/urgent items all
   * qualify; ranking puts overdue first, then in-progress, then due date, then
   * priority.
   *
   * $user_id matches tasks assigned to that user OR unassigned — on a solo
   * site assignee is usually empty, and a strict filter renders nothing.
   *
   * The ranking is not expressible as an entity-query sort, so the whole
   * non-done focus set is loaded before sorting and slicing. Fine at current
   * scale, a cliff at a few thousand open tasks.
   *
   * @return array
   *   listResult() shape plus 'as_of'.
   */
  public function getFocusSet(?int $user_id = NULL, int $limit = 12): array {
    $today = date('Y-m-d');
    $horizon = date('Y-m-d', $this->time->getRequestTime() + 7 * 86400);

    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('status', ['done'], 'NOT IN');
    $focus = $query->orConditionGroup()
      ->condition('status', 'in_progress')
      ->condition('due_date', $horizon, '<=')
      ->condition('priority', ['high', 'urgent'], 'IN');
    $query->condition($focus);
    if ($user_id !== NULL) {
      $mine = $query->orConditionGroup()
        ->condition('assignee', $user_id)
        ->notExists('assignee');
      $query->condition($mine);
    }
    $total = (int) (clone $query)->count()->execute();
    $tasks = array_values($this->storage()->loadMultiple($query->execute()));

    $priority_rank = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    usort($tasks, function ($a, $b) use ($today, $priority_rank) {
      $rank = function ($task) use ($today, $priority_rank) {
        $due = $task->get('due_date')->value;
        return [
          // Blocked sinks below everything, however overdue or urgent it is:
          // the point of this list is what you can act on, and you cannot act
          // on work that is waiting for someone else.
          $task->get('blocked')->value ? 1 : 0,
          $due !== NULL && $due < $today ? 0 : 1,
          $task->get('status')->value === 'in_progress' ? 0 : 1,
          $due ?? '9999-12-31',
          $priority_rank[$task->get('priority')->value] ?? 9,
        ];
      };
      return $rank($a) <=> $rank($b);
    });
    $tasks = array_slice($tasks, 0, $limit);

    $items = array_map(fn($t) => $this->baseStub($t) + [
      'project_id' => (int) $t->get('project')->target_id,
      'project_title' => $t->get('project')->entity?->label(),
      'status' => $t->get('status')->value,
      'priority' => $t->get('priority')->value,
      'assignee' => $t->get('assignee')->target_id ? (int) $t->get('assignee')->target_id : NULL,
      'due_date' => $t->get('due_date')->value,
      'overdue' => $t->get('due_date')->value !== NULL && $t->get('due_date')->value < $today,
      'blocked' => (bool) $t->get('blocked')->value,
      'blocked_note' => $t->get('blocked_note')->value,
      'reason' => $this->focusReason($t, $today, $horizon),
    ], $tasks);
    return $this->listResult($items, $total) + ['as_of' => $today];
  }

  /**
   * Every task id in one board column, newest-changed first.
   *
   * Ids only and deliberately uncapped: the board must choose its page by
   * manual order, and manual order lives in a side table this service cannot
   * join to. Selecting the page by `changed` instead would let a card the
   * user pinned to the top of a long column fall off the board entirely.
   * An id-only entity query stays cheap well past the scale where that
   * matters.
   *
   * @return int[]
   *   Every matching task id, newest-changed first.
   */
  public function columnTaskIds(int $project_id, string $status): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('project', $project_id)
      ->condition('status', $status)
      ->sort('changed', 'DESC')
      ->execute();
    return array_map(intval(...), array_values($ids));
  }

  /**
   * Why a task made the focus set — agents shouldn't have to infer it.
   */
  protected function focusReason($task, string $today, string $horizon): string {
    $due = $task->get('due_date')->value;
    if ($task->get('blocked')->value) {
      $note = $task->get('blocked_note')->value;
      return $note ? 'blocked on ' . $note : 'blocked';
    }
    if ($due !== NULL && $due < $today) {
      return 'overdue';
    }
    if ($task->get('status')->value === 'in_progress') {
      return 'in progress';
    }
    if ($due !== NULL && $due <= $horizon) {
      return 'due soon';
    }
    return $task->get('priority')->value . ' priority';
  }

  /**
   * The list-and-card shape of a task: base stub plus its board fields.
   *
   * The blocked_note and tags keys appear only when they hold something. On a
   * list of mostly unblocked, untagged rows the empty keys are most of the
   * payload, and their absence reads the same as their emptiness.
   */
  protected function stub($task): array {
    $stub = $this->baseStub($task) + [
      'project_id' => (int) $task->get('project')->target_id,
      'status' => $task->get('status')->value,
      'priority' => $task->get('priority')->value,
      'assignee' => $task->get('assignee')->target_id ? (int) $task->get('assignee')->target_id : NULL,
      'due_date' => $task->get('due_date')->value,
      'blocked' => (bool) $task->get('blocked')->value,
    ];
    if ($stub['blocked'] && $task->get('blocked_note')->value) {
      $stub['blocked_note'] = $task->get('blocked_note')->value;
    }
    $tags = array_column($task->get('tags')->getValue(), 'value');
    if ($tags) {
      $stub['tags'] = $tags;
    }
    return $stub;
  }

}
