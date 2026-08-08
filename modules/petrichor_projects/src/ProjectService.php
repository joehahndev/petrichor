<?php

declare(strict_types=1);

namespace Drupal\petrichor_projects;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\ProvenanceTrait;

/**
 * Domain service for projects.
 *
 * The single implementation behind MCP tools, UI endpoints, and drush alike.
 */
class ProjectService {

  use ProvenanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Storage handler for projects.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_project');
  }

  /**
   * Projects matching the filters, newest-changed first.
   *
   * The `query` filter is how a caller gets from a name to an id. Without it
   * the only route is listing every project and guessing from titles, which
   * is what an agent opening an unfamiliar repository has to do — and the
   * whole of project-scoped memory hangs off having the right id.
   *
   * Title and client are both matched because a repository folder is as
   * likely to be named after the client as after the engagement.
   */
  public function listProjects(array $filters = [], int $limit = 100): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    if (!empty($filters['status'])) {
      $query->condition('status', $filters['status']);
    }
    if (!empty($filters['query'])) {
      // escapeLike, or a caller searching for a literal '%' matches the whole
      // table and reads the result as "that is every project I have".
      $like = '%' . $this->database->escapeLike((string) $filters['query']) . '%';
      $query->condition($query->orConditionGroup()
        ->condition('label', $like, 'LIKE')
        ->condition('client', $like, 'LIKE'));
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    return $this->listResult($items, $total, $offset);
  }

  /**
   * One project, plus the description that lists leave out.
   *
   * A project that does not exist and one the caller may not view raise the
   * same error, so an id probe cannot tell them apart.
   */
  public function getProject(int $id): array {
    $project = $this->storage()->load($id);
    if (!$project || !$project->access('view')) {
      throw new \InvalidArgumentException('Project not found.');
    }
    $stub = $this->withCreated($this->stub($project), $project);
    $stub['description'] = $project->get('description')->value;
    return $stub;
  }

  /**
   * Creates a project. Only the title is required; status defaults to open.
   */
  public function createProject(array $values): array {
    $project = $this->storage()->create([
      'label' => $values['title'] ?? throw new \InvalidArgumentException('title is required.'),
      'status' => $values['status'] ?? 'open',
      'client' => $values['client'] ?? NULL,
      'description' => isset($values['description'])
        ? ['value' => $values['description'], 'format' => 'markdown']
        : NULL,
    ]);
    if (!$project->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create projects.');
    }
    $project->save();
    return $this->stub($project);
  }

  /**
   * Applies a partial update: only keys present in $values are written.
   *
   * Presence is tested with array_key_exists() rather than isset() so that an
   * explicit NULL clears a field instead of reading as "not supplied".
   */
  public function updateProject(int $id, array $values): array {
    $project = $this->storage()->load($id);
    if (!$project || !$project->access('update')) {
      throw new \InvalidArgumentException('Project not found or not editable.');
    }
    foreach (['title' => 'label', 'status' => 'status', 'client' => 'client'] as $arg => $field) {
      if (array_key_exists($arg, $values)) {
        $project->set($field, $values[$arg]);
      }
    }
    if (array_key_exists('description', $values)) {
      $project->set('description', ['value' => $values['description'], 'format' => 'markdown']);
    }
    $project->save();
    return $this->stub($project);
  }

  /**
   * The list shape of a project: base stub plus status and client.
   */
  protected function stub($project): array {
    return $this->baseStub($project) + [
      'status' => $project->get('status')->value,
      'client' => $project->get('client')->value,
    ];
  }

}
