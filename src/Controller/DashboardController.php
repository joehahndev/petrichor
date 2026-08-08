<?php

declare(strict_types=1);

namespace Drupal\petrichor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Petrichor PM dashboard.
 *
 * This is the first page anybody sees after installing the suite, which makes
 * its empty state the most important thing on it. The version this replaced
 * listed every enabled entity type with a count — accurate, and no help at
 * all to somebody who had just run `drush en` and had nothing yet.
 *
 * The base module cannot name a route belonging to petrichor_board or
 * petrichor_wiki, because on a site that did not install them that is a crash
 * rather than a missing link. So every URL here is decided before rendering
 * and the component only draws what it is handed.
 */
class DashboardController extends ControllerBase {

  /**
   * How many projects the dashboard shows before it points at the full list.
   */
  protected const PROJECT_CAP = 12;

  /**
   * Renders the dashboard.
   */
  public function dashboard(): array {
    // petrichor_projects is optional like everything else. Without it there is
    // no project to scope anything to, so say that rather than render an empty
    // grid that looks like a site with no data.
    if (!$this->entityTypeManager()->hasDefinition('petrichor_project')) {
      return [
        '#markup' => $this->t('Enable the Petrichor Projects module to get started. A project is the container every other part of the suite hangs off.'),
        '#cache' => ['contexts' => ['user.permissions']],
      ];
    }

    $storage = $this->entityTypeManager()->getStorage('petrichor_project');
    $has_tasks = $this->entityTypeManager()->hasDefinition('petrichor_task');
    $has_board = $this->moduleHandler()->moduleExists('petrichor_board');

    $query = $storage->getQuery()->accessCheck(TRUE);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->range(0, self::PROJECT_CAP)->execute();

    $projects = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      $status = (string) $project->get('status')->value;
      $allowed = $project->get('status')->getFieldDefinition()->getSetting('allowed_values');
      $row = [
        'id' => (int) $project->id(),
        'title' => $project->label(),
        'url' => $project->toUrl()->toString(),
        'client' => $project->get('client')->value,
        'status' => isset($allowed[$status]) ? (string) $allowed[$status] : $status,
        'status_id' => $status,
        // NULL, not 0: "no open tasks" and "this site has no tasks module"
        // are different facts and the template renders them differently.
        'open_tasks' => $has_tasks ? $this->openTaskCount((int) $project->id()) : NULL,
        'actions' => [],
      ];
      if ($has_board) {
        $row['actions'][] = [
          // Cast: the prop schema says string, and TranslatableMarkup is an
          // object. Same boundary conversion BoardController does for column
          // labels off the status field.
          'title' => (string) $this->t('Board'),
          'url' => Url::fromRoute('petrichor_board.board', [
            'petrichor_project' => $project->id(),
          ])->toString(),
        ];
      }
      $projects[] = $row;
    }

    $note = NULL;
    if ($total > count($projects)) {
      $note = $this->t('Showing @shown of @total projects.', [
        '@shown' => count($projects),
        '@total' => $total,
      ]);
    }

    return [
      'dashboard' => [
        '#type' => 'component',
        '#component' => 'petrichor:dashboard',
        '#props' => [
          'projects' => $projects,
          'create_url' => $this->createProjectUrl(),
          'links' => $this->workspaceLinks(),
          'note' => $note ? (string) $note : NULL,
          'all_projects_url' => Url::fromRoute('entity.petrichor_project.collection')->toString(),
        ],
      ],
      '#cache' => [
        // The list cache tags of everything counted above. The previous
        // version invented a 'petrichor_dashboard' tag that nothing ever
        // invalidated, so the counts on this page were correct exactly once
        // and then cached until somebody cleared caches by hand.
        'tags' => $this->cacheTags($has_tasks),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Open (not done) tasks in one project, respecting access.
   */
  protected function openTaskCount(int $project_id): int {
    return (int) $this->entityTypeManager()->getStorage('petrichor_task')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('project', $project_id)
      ->condition('status', 'done', '<>')
      ->count()
      ->execute();
  }

  /**
   * The project add form, or NULL when this user may not create one.
   *
   * NULL suppresses every call to action in the template, so a read-only user
   * is never shown a button that would 403 — which on an otherwise empty first
   * run would be the only thing on the page.
   */
  protected function createProjectUrl(): ?string {
    $access = $this->entityTypeManager()
      ->getAccessControlHandler('petrichor_project')
      ->createAccess();
    return $access ? Url::fromRoute('entity.petrichor_project.add_form')->toString() : NULL;
  }

  /**
   * Links to the parts of the workspace that are actually installed.
   */
  protected function workspaceLinks(): array {
    $links = [];
    if ($this->moduleHandler()->moduleExists('petrichor_board')) {
      $links[] = [
        'title' => $this->t('Today'),
        'description' => $this->t('In progress, overdue, and due soon'),
        'url' => Url::fromRoute('petrichor_board.today')->toString(),
      ];
    }
    if ($this->entityTypeManager()->hasDefinition('petrichor_wiki_page')) {
      $links[] = [
        'title' => $this->t('Knowledge base'),
        'description' => $this->t('Notes, decisions and documentation'),
        'url' => Url::fromRoute('entity.petrichor_wiki_page.collection')->toString(),
      ];
    }
    if ($this->entityTypeManager()->hasDefinition('petrichor_memory')) {
      $links[] = [
        'title' => $this->t('Memories'),
        'description' => $this->t('What assistants have recorded, and anything waiting for your review'),
        'url' => Url::fromRoute('entity.petrichor_memory.collection')->toString(),
      ];
    }
    // Strings, not TranslatableMarkup: the component's prop schema says
    // string, and SDC validates props against it.
    return array_map(static fn(array $link): array => [
      'title' => (string) $link['title'],
      'description' => (string) $link['description'],
      'url' => $link['url'],
    ], $links);
  }

  /**
   * List cache tags for every entity type this page reads.
   */
  protected function cacheTags(bool $has_tasks): array {
    $tags = $this->entityTypeManager()->getDefinition('petrichor_project')->getListCacheTags();
    if ($has_tasks) {
      $tags = array_merge($tags, $this->entityTypeManager()->getDefinition('petrichor_task')->getListCacheTags());
    }
    return $tags;
  }

}
