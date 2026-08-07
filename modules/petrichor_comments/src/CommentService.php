<?php

declare(strict_types=1);

namespace Drupal\petrichor_comments;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\petrichor_tasks\TaskService;

/**
 * Domain service for the task worklog.
 *
 * Comments are core Comment entities on the petrichor_task `worklog` field.
 * The value of that over a custom entity is everything core already gives us:
 * threading, access, the admin overview, and a render pipeline. What this
 * class adds is the narrow shape the MCP tools and the UI both want — a task
 * id, some markdown, and who said it.
 */
class CommentService {

  protected const FIELD = 'worklog';
  protected const COMMENT_TYPE = 'petrichor_worklog';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TaskService $tasks,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Storage handler for core Comment entities.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('comment');
  }

  /**
   * Appends a worklog entry to a task.
   *
   * Reads the task through TaskService first, so a comment can never be
   * attached to something the caller cannot see.
   */
  public function addComment(int $task_id, string $body, ?string $subject = NULL): array {
    if (trim($body) === '') {
      throw new \InvalidArgumentException('body is required.');
    }
    // Throws if missing or not viewable.
    $this->tasks->getTask($task_id);

    $comment = $this->storage()->create([
      'entity_type' => 'petrichor_task',
      'entity_id' => $task_id,
      'field_name' => self::FIELD,
      'comment_type' => self::COMMENT_TYPE,
      'subject' => $subject !== NULL && trim($subject) !== ''
        ? mb_substr(trim($subject), 0, 64)
        : $this->subjectFrom($body),
      'comment_body' => ['value' => $body, 'format' => 'markdown'],
      'status' => 1,
    ]);
    if (!$comment->access('create')) {
      throw new \InvalidArgumentException('Not allowed to comment.');
    }
    $comment->save();
    return $this->stub($comment);
  }

  /**
   * The worklog for one task, oldest first — a log reads forwards.
   */
  public function getComments(int $task_id, int $limit = 50, int $offset = 0): array {
    $this->tasks->getTask($task_id);

    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('entity_type', 'petrichor_task')
      ->condition('entity_id', $task_id)
      ->condition('field_name', self::FIELD);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('created')->range(max(0, $offset), $limit)->execute();

    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    $result = ['items' => $items, 'total' => $total, 'task_id' => $task_id];
    if ($offset > 0 || count($items) < $total) {
      $end = $offset + count($items);
      $result['offset'] = $offset;
      $result['note'] = sprintf('showing %d-%d of %d', $items ? $offset + 1 : $offset, $end, $total);
      if ($end < $total) {
        $result['next_offset'] = $end;
      }
    }
    return $result;
  }

  /**
   * Recent worklog entries across tasks, newest first.
   *
   * This is what makes a project's activity feed real rather than a list of
   * `changed` timestamps, which say something moved but never what or why.
   */
  public function recentForTasks(array $task_ids, int $limit = 10): array {
    if (!$task_ids) {
      return [];
    }
    $ids = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('entity_type', 'petrichor_task')
      ->condition('field_name', self::FIELD)
      ->condition('entity_id', $task_ids, 'IN')
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    return array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
  }

  /**
   * First line of the body, for a subject nobody had to write.
   */
  protected function subjectFrom(string $body): string {
    $first = strtok(trim($body), "\n") ?: 'Note';
    $first = trim(preg_replace('/^#+\s*/', '', $first));
    return mb_strimwidth($first, 0, 64, '…');
  }

  /**
   * The wire shape of a worklog entry.
   *
   * Authorship is read off the comment's owner, never out of the body — a
   * worklog is evidence of who did what, so content must not be able to
   * claim its own author. ProvenanceTrait states the same rule for entities
   * that carry it; comments build the block by hand because they do not.
   */
  protected function stub($comment): array {
    $owner = $comment->getOwner();
    $name = $owner ? $owner->getAccountName() : '';
    if ($name === '') {
      $name = $owner && $owner->isAnonymous() ? 'system' : 'uid-' . $comment->getOwnerId();
    }
    return [
      'id' => (int) $comment->id(),
      'task_id' => (int) $comment->getCommentedEntityId(),
      'subject' => $comment->getSubject(),
      'body' => $comment->get('comment_body')->value,
      'author' => [
        'name' => $name,
        'is_agent' => (bool) array_filter(
          $owner ? $owner->getRoles() : [],
          static fn(string $role): bool => str_starts_with($role, 'agent_'),
        ),
      ],
      'created' => date('c', (int) $comment->getCreatedTime()),
    ];
  }

}
