<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\petrichor\ProvenanceTrait;

/**
 * Domain service for agent memory.
 */
class MemoryService {

  use ProvenanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * Storage handler for memory entities.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_memory');
  }

  /**
   * Stores a fact, project-scoped when a project id is given.
   */
  public function remember(array $values): array {
    if (empty($values['title']) || empty($values['fact'])) {
      throw new \InvalidArgumentException('title and fact are required.');
    }
    $memory = $this->storage()->create([
      'label' => $values['title'],
      'fact' => ['value' => $values['fact'], 'format' => 'markdown'],
      'project' => $values['project_id'] ?? NULL,
      'type' => $values['type'] ?? 'learning',
      'tags' => $values['tags'] ?? [],
      'status' => 'active',
    ]);
    if (!$memory->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create memories.');
    }
    $memory->save();
    return $this->stub($memory);
  }

  /**
   * Keyword recall: scope matches rank first, then recall/changed recency.
   */
  public function recall(string $keywords, ?int $project_id = NULL, int $limit = 10): array {
    if (trim($keywords) === '') {
      throw new \InvalidArgumentException('query is required.');
    }
    $like = '%' . $this->database->escapeLike($keywords) . '%';
    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('status', 'active');
    $group = $query->orConditionGroup()
      ->condition('label', $like, 'LIKE')
      ->condition('fact', $like, 'LIKE')
      ->condition('tags', $like, 'LIKE');
    $query->condition($group);
    if ($project_id !== NULL) {
      // Project-scoped recall includes globals; scope match ranks first below.
      $scope = $query->orConditionGroup()
        ->condition('project', $project_id)
        ->notExists('project');
      $query->condition($scope);
    }
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->range(0, 100)->execute();
    $memories = array_values($this->storage()->loadMultiple($ids));

    $recalled = $this->lastRecalledMap(array_map(fn($m) => (int) $m->id(), $memories));
    usort($memories, function ($a, $b) use ($project_id, $recalled) {
      if ($project_id !== NULL) {
        $scope = (int) ((bool) $b->get('project')->target_id) <=> (int) ((bool) $a->get('project')->target_id);
        if ($scope !== 0) {
          return $scope;
        }
      }
      $a_recency = max($recalled[(int) $a->id()] ?? 0, (int) $a->get('changed')->value);
      $b_recency = max($recalled[(int) $b->id()] ?? 0, (int) $b->get('changed')->value);
      return $b_recency <=> $a_recency;
    });
    $memories = array_slice($memories, 0, $limit);

    $this->touchRecalled(array_map(fn($m) => (int) $m->id(), $memories));
    $items = array_map(function ($memory) {
      $stub = $this->stub($memory);
      $stub['fact'] = $memory->get('fact')->value;
      return $stub;
    }, $memories);
    return $this->listResult($items, $total);
  }

  /**
   * Archives a memory. There is no hard delete on the wire.
   *
   * Maintainer-tier only: contributors remember and recall, but removing
   * facts from the active set is a deliberate promotion (threat model).
   */
  public function forget(int $id): array {
    if (!$this->currentUser->hasPermission('archive petrichor memories')) {
      throw new \InvalidArgumentException('forget requires the agent_maintainer role (archive petrichor memories permission).');
    }
    $memory = $this->storage()->load($id);
    if (!$memory || !$memory->access('update')) {
      throw new \InvalidArgumentException('Memory not found or not editable.');
    }
    $memory->set('status', 'archived');
    $memory->save();
    return ['id' => (int) $memory->id(), 'status' => 'archived'];
  }

  /**
   * Lists memories.
   *
   * With include_global, a project filter also returns global memories —
   * matching recall()'s semantics, so a fact that applies everywhere isn't
   * invisible to project-scoped callers.
   */
  public function listMemories(array $filters = [], int $limit = 50): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('status', $filters['status'] ?? 'active');
    if (!empty($filters['project_id'])) {
      $project_id = (int) $filters['project_id'];
      if (!empty($filters['include_global'])) {
        $scope = $query->orConditionGroup()
          ->condition('project', $project_id)
          ->notExists('project');
        $query->condition($scope);
      }
      else {
        $query->condition('project', $project_id);
      }
    }
    if (!empty($filters['type'])) {
      $query->condition('type', $filters['type']);
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    return $this->listResult($items, $total, $offset);
  }

  /**
   * Last-recall timestamps keyed by memory id, for the ids that have one.
   *
   * Recall recency lives in a plain table rather than on the entity, so that
   * reading a memory does not bump the `changed` timestamp that recall()
   * falls back to for memories nobody has recalled yet.
   */
  protected function lastRecalledMap(array $ids): array {
    if (!$ids) {
      return [];
    }
    return $this->database->select('petrichor_memory_recall', 'r')
      ->fields('r', ['memory_id', 'last_recalled'])
      ->condition('memory_id', $ids, 'IN')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Marks these memories as recalled now.
   *
   * Merge rather than insert: a memory is recalled repeatedly and only the
   * most recent time is ever read back.
   */
  protected function touchRecalled(array $ids): void {
    $now = $this->time->getRequestTime();
    foreach ($ids as $id) {
      $this->database->merge('petrichor_memory_recall')
        ->key('memory_id', $id)
        ->fields(['last_recalled' => $now])
        ->execute();
    }
  }

  /**
   * The wire shape of a memory: base stub plus scope, type, tags and status.
   *
   * The fact body is deliberately absent. recall() adds it back for the rows
   * it returns; listing stays light.
   */
  protected function stub($memory): array {
    return $this->baseStub($memory) + [
      'type' => $memory->get('type')->value,
      'project_id' => $memory->get('project')->target_id ? (int) $memory->get('project')->target_id : NULL,
      'tags' => array_column($memory->get('tags')->getValue(), 'value'),
      'status' => $memory->get('status')->value,
    ];
  }

}
