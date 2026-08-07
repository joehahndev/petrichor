<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\ProvenanceTrait;
use Drupal\petrichor_comments\CommentService;
use Drupal\petrichor_memory\MemoryService;
use Drupal\petrichor_tasks\TaskService;

/**
 * Builds context bundles: one call to hydrate an agent session.
 *
 * Degrades gracefully per the à-la-carte packaging rule — each section only
 * appears when its entity module is enabled. Size budget (PRODUCT.md):
 * summary detail targets ~3k tokens — capped lists, 14-day activity window,
 * wiki as id+title only, top 10 memories. Truncation is always announced.
 */
class ContextService {

  use ProvenanceTrait;

  // Caps are deliberately small now that every list is pageable: a bundle
  // that blows the context window is worse than one that says "there are 38,
  // here are 12, pass offset for more".
  protected const TASK_CAP = 12;
  protected const WIKI_CAP = 10;
  protected const MEMORY_CAP = 8;
  protected const ACTIVITY_DAYS = 14;

  /**
   * Constructs a ContextService.
   *
   * The last three arguments are injected with '@?' in services.yml, which
   * resolves to NULL when the submodule that provides them is not installed.
   * That is the whole à-la-carte contract: this class returns a smaller
   * context bundle rather than erroring, and the NULL checks below are load
   * bearing. Do not "tidy" them into required arguments.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected ?CommentService $comments = NULL,
    protected ?MemoryService $memories = NULL,
    protected ?TaskService $tasks = NULL,
  ) {}

  /**
   * Context bundle for one project — a whole agent session in one call.
   *
   * Detail 'full' only raises the task cap to 100; every other section keeps
   * its summary caps either way.
   *
   * @throws \InvalidArgumentException
   *   If the project does not exist or the user cannot view it.
   */
  public function getProjectContext(int $project_id, string $detail = 'summary'): array {
    $projects = $this->entityTypeManager->getStorage('petrichor_project');
    $project = $projects->load($project_id);
    if (!$project || !$project->access('view')) {
      throw new \InvalidArgumentException('Project not found.');
    }

    $context = [
      'project' => $this->baseStub($project) + [
        'status' => $project->get('status')->value,
        'client' => $project->get('client')->value,
        'brief' => $project->get('description')->value,
      ],
    ];

    if ($this->entityTypeManager->hasDefinition('petrichor_task')) {
      $storage = $this->entityTypeManager->getStorage('petrichor_task');
      $query = $storage->getQuery()->accessCheck(TRUE)
        ->condition('project', $project_id)
        ->condition('status', 'done', '<>');
      $total = (int) (clone $query)->count()->execute();
      $cap = $detail === 'full' ? 100 : self::TASK_CAP;
      $ids = $query->sort('changed', 'DESC')->range(0, $cap)->execute();
      $items = array_map(fn($t) => $this->baseStub($t) + [
        'status' => $t->get('status')->value,
        'priority' => $t->get('priority')->value,
        'due_date' => $t->get('due_date')->value,
      ], array_values($storage->loadMultiple($ids)));
      $context['open_tasks'] = $this->listResult($items, $total);

      $since = $this->time->getRequestTime() - self::ACTIVITY_DAYS * 86400;
      $recent = $storage->getQuery()->accessCheck(TRUE)
        ->condition('project', $project_id)
        ->condition('changed', $since, '>=')
        ->sort('changed', 'DESC')->range(0, 10)->execute();
      $context['recent_activity'] = [
        'window_days' => self::ACTIVITY_DAYS,
        'items' => array_map(fn($t) => [
          'id' => (int) $t->id(),
          'title' => $t->label(),
          'status' => $t->get('status')->value,
          'changed' => date('c', (int) $t->get('changed')->value),
        ], array_values($storage->loadMultiple($recent))),
      ];

      // Worklog entries say what happened and why; `changed` only says that
      // something moved — and after a bulk import it says that about
      // everything at once. When comments are available they are the better
      // activity signal, so they ride alongside.
      if ($this->comments) {
        $all_ids = $storage->getQuery()->accessCheck(TRUE)
          ->condition('project', $project_id)->execute();
        $worklog = $this->comments
          ->recentForTasks(array_map(intval(...), array_values($all_ids)), 8);
        if ($worklog) {
          $context['recent_worklog'] = $worklog;
        }
      }
    }

    if ($this->entityTypeManager->hasDefinition('petrichor_milestone')) {
      $storage = $this->entityTypeManager->getStorage('petrichor_milestone');
      $ids = $storage->getQuery()->accessCheck(TRUE)
        ->condition('project', $project_id)
        ->condition('status', 'done', '<>')
        ->sort('end_date')->range(0, 10)->execute();
      $context['milestones'] = array_map(fn($m) => [
        'id' => (int) $m->id(),
        'title' => $m->label(),
        'status' => $m->get('status')->value,
        'end_date' => $m->get('end_date')->value,
      ], array_values($storage->loadMultiple($ids)));
    }

    if ($this->entityTypeManager->hasDefinition('petrichor_wiki_page')) {
      $storage = $this->entityTypeManager->getStorage('petrichor_wiki_page');
      $query = $storage->getQuery()->accessCheck(TRUE)->condition('project', $project_id);
      $total = (int) (clone $query)->count()->execute();
      $ids = $query->sort('changed', 'DESC')->range(0, self::WIKI_CAP)->execute();
      // Bodies are never inlined — fetch via get_wiki_page.
      $items = array_map(fn($p) => ['id' => (int) $p->id(), 'title' => $p->label()], array_values($storage->loadMultiple($ids)));
      $context['wiki_pages'] = $this->listResult($items, $total);
    }

    if ($this->memories) {
      // include_global: a fact that applies everywhere is relevant here too.
      $context['memories'] = $this->memories
        ->listMemories([
          'project_id' => $project_id,
          'include_global' => TRUE,
        ], self::MEMORY_CAP);
    }

    return $context;
  }

  /**
   * Today's focus set.
   *
   * The implementation lives in TaskService so the board's Today view and this
   * tool cannot drift apart; petrichor_tasks cannot depend on petrichor_mcp,
   * which already depends on it, so the shared code had to move down.
   */
  public function getMyWork(?int $user_id = NULL): array {
    if (!$this->tasks) {
      return ['note' => 'Task module not enabled.'];
    }
    return $this->tasks->getFocusSet($user_id, self::TASK_CAP);
  }

}
