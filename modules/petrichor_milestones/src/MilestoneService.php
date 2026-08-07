<?php

declare(strict_types=1);

namespace Drupal\petrichor_milestones;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\ProvenanceTrait;

/**
 * Domain service for milestones.
 */
class MilestoneService {

  use ProvenanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Storage handler for milestones.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_milestone');
  }

  /**
   * Milestones matching the filters, earliest end date first.
   *
   * Sorted by end date rather than by 'changed' like every other list here: a
   * milestone list is read as a schedule, and recency says nothing about when
   * something is due.
   */
  public function listMilestones(array $filters = [], int $limit = 50): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    if (!empty($filters['project_id'])) {
      $query->condition('project', (int) $filters['project_id']);
    }
    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('end_date')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    return $this->listResult($items, $total, $offset);
  }

  /**
   * One milestone, plus its colour and the first 50 tasks under it.
   *
   * The task list is omitted entirely when petrichor_tasks is not installed —
   * milestones stand on their own, and the dependency runs the other way.
   */
  public function getMilestone(int $id): array {
    $milestone = $this->storage()->load($id);
    if (!$milestone || !$milestone->access('view')) {
      throw new \InvalidArgumentException('Milestone not found.');
    }
    $stub = $this->withCreated($this->stub($milestone), $milestone);
    $stub['color'] = $milestone->get('color')->value;
    if ($this->entityTypeManager->hasDefinition('petrichor_task')) {
      $task_storage = $this->entityTypeManager->getStorage('petrichor_task');
      $ids = $task_storage->getQuery()->accessCheck(TRUE)
        ->condition('milestone', $id)->range(0, 50)->execute();
      $stub['tasks'] = array_map(
        fn($task) => [
          'id' => (int) $task->id(),
          'title' => $task->label(),
          'status' => $task->get('status')->value,
        ],
        array_values($task_storage->loadMultiple($ids))
      );
    }
    return $stub;
  }

  /**
   * Creates a milestone. Title and project_id are required, dates are not.
   */
  public function createMilestone(array $values): array {
    if (empty($values['title']) || empty($values['project_id'])) {
      throw new \InvalidArgumentException('title and project_id are required.');
    }
    $milestone = $this->storage()->create([
      'label' => $values['title'],
      'project' => (int) $values['project_id'],
      'status' => $values['status'] ?? 'planned',
      'start_date' => $values['start_date'] ?? NULL,
      'end_date' => $values['end_date'] ?? NULL,
      'color' => $values['color'] ?? NULL,
    ]);
    if (!$milestone->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create milestones.');
    }
    $milestone->save();
    return $this->stub($milestone);
  }

  /**
   * Applies a partial update: only keys present in $values are written.
   *
   * Presence is tested with array_key_exists() rather than isset() so that an
   * explicit NULL clears a date or the colour instead of reading as "not
   * supplied".
   */
  public function updateMilestone(int $id, array $values): array {
    $milestone = $this->storage()->load($id);
    if (!$milestone || !$milestone->access('update')) {
      throw new \InvalidArgumentException('Milestone not found or not editable.');
    }
    $map = [
      'title' => 'label',
      'status' => 'status',
      'start_date' => 'start_date',
      'end_date' => 'end_date',
      'color' => 'color',
    ];
    foreach ($map as $arg => $field) {
      if (array_key_exists($arg, $values)) {
        $milestone->set($field, $values[$arg]);
      }
    }
    $milestone->save();
    return $this->stub($milestone);
  }

  /**
   * The list shape of a milestone: base stub plus project, status, and dates.
   */
  protected function stub($milestone): array {
    return $this->baseStub($milestone) + [
      'project_id' => (int) $milestone->get('project')->target_id,
      'status' => $milestone->get('status')->value,
      'start_date' => $milestone->get('start_date')->value,
      'end_date' => $milestone->get('end_date')->value,
    ];
  }

}
