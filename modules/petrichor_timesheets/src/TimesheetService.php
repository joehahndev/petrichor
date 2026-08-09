<?php

declare(strict_types=1);

namespace Drupal\petrichor_timesheets;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
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
   * `listed_hours` totals only the rows on this page; `total_hours` covers
   * the whole filtered set regardless of paging, via a separate aggregate
   * query rather than loading every entity — "how many hours this period"
   * shouldn't require paging through hundreds of entries first to answer.
   * The note still flags when the two disagree, so a caller skimming just
   * `listed_hours` isn't misled into treating a partial figure as the total.
   */
  public function listTimesheets(array $filters = [], int $limit = 100): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    $this->applyFilters($query, $filters);
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('date', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    $result = $this->listResult($items, $total, $offset);
    $result['listed_hours'] = round(array_sum(array_column($items, 'hours')), 2);
    $result['total_hours'] = $this->sumHours($filters);
    if (count($items) < $total) {
      $result['note'] = ($result['note'] ?? '') . '; listed_hours covers only the listed entries, total_hours covers the whole filtered set';
    }
    return $result;
  }

  /**
   * Applies the shared task_id/project_id/since/until filters to a query.
   *
   * Shared between the paged listing query and the unpaged aggregate sum
   * below, so the two can never disagree about what "this period" means.
   */
  protected function applyFilters(QueryInterface $query, array $filters): void {
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
  }

  /**
   * Sums hours across the whole filtered set, independent of paging.
   */
  protected function sumHours(array $filters): float {
    $query = $this->storage()->getAggregateQuery()->accessCheck(TRUE);
    $this->applyFilters($query, $filters);
    $alias = NULL;
    $query->aggregate('hours', 'SUM', NULL, $alias);
    $result = $query->execute();
    return round((float) ($result[0][$alias] ?? 0), 2);
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
