<?php

declare(strict_types=1);

namespace Drupal\petrichor_timesheets;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\ProvenanceTrait;

/**
 * Domain service for time tracking.
 */
class TimesheetService {

  use ProvenanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Storage handler for timesheet entries.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_timesheet');
  }

  /**
   * Records a work session against a task; the date defaults to today.
   */
  public function logTime(array $values): array {
    if (empty($values['task_id']) || empty($values['hours'])) {
      throw new \InvalidArgumentException('task_id and hours are required.');
    }
    $entry = $this->storage()->create([
      'label' => $values['summary'] ?? 'Work session',
      'task' => (int) $values['task_id'],
      'hours' => $values['hours'],
      'date' => $values['date'] ?? date('Y-m-d'),
      'kind' => $values['kind'] ?? 'development',
    ]);
    if (!$entry->access('create')) {
      throw new \InvalidArgumentException('Not allowed to log time.');
    }
    $entry->save();
    return $this->stub($entry);
  }

  /**
   * Lists entries, most recent date first.
   *
   * `listed_hours` totals only the rows returned, not the whole filtered set,
   * and the note says so when the list is truncated: a partial total that
   * looks complete is worse than no total at all.
   */
  public function listTimesheets(array $filters = [], int $limit = 100): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    if (!empty($filters['task_id'])) {
      $query->condition('task', (int) $filters['task_id']);
    }
    if (!empty($filters['since'])) {
      $query->condition('date', $filters['since'], '>=');
    }
    if (!empty($filters['until'])) {
      $query->condition('date', $filters['until'], '<=');
    }
    if (!empty($filters['project_id']) && $this->entityTypeManager->hasDefinition('petrichor_task')) {
      $task_ids = $this->entityTypeManager->getStorage('petrichor_task')->getQuery()
        ->accessCheck(TRUE)->condition('project', (int) $filters['project_id'])->execute();
      // No tasks in the project means no entries — an empty IN would match all.
      $query->condition('task', $task_ids ?: [0], 'IN');
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('date', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    $result = $this->listResult($items, $total, $offset);
    $result['listed_hours'] = round(array_sum(array_column($items, 'hours')), 2);
    if (count($items) < $total) {
      $result['note'] = ($result['note'] ?? '') . '; listed_hours covers only the listed entries';
    }
    return $result;
  }

  /**
   * The wire shape of a timesheet entry.
   *
   * Hours are cast to float so the response carries a number rather than the
   * decimal field's string, which callers would otherwise have to add up.
   */
  protected function stub($entry): array {
    return $this->baseStub($entry) + [
      'task_id' => (int) $entry->get('task')->target_id,
      'date' => $entry->get('date')->value,
      'hours' => (float) $entry->get('hours')->value,
      'kind' => $entry->get('kind')->value,
    ];
  }

}
