<?php

declare(strict_types=1);

namespace Drupal\petrichor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Petrichor PM dashboard.
 *
 * Placeholder overview page: lists every enabled Petrichor entity type with
 * a count and a link to its collection. Will grow into a real "My work"
 * dashboard (assigned tasks, due milestones, recent wiki edits).
 */
class DashboardController extends ControllerBase {

  /**
   * Renders the dashboard.
   */
  public function dashboard(): array {
    $items = [];
    foreach ($this->entityTypeManager()->getDefinitions() as $entity_type) {
      if (!str_starts_with($entity_type->getProvider(), 'petrichor_')) {
        continue;
      }
      if (!$entity_type->hasLinkTemplate('collection')) {
        continue;
      }
      $count = $this->entityTypeManager()
        ->getStorage($entity_type->id())
        ->getQuery()
        ->accessCheck(TRUE)
        ->count()
        ->execute();
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('@label (@count)', [
          '@label' => $entity_type->getCollectionLabel(),
          '@count' => $count,
        ]),
        '#url' => Url::fromRoute("entity.{$entity_type->id()}.collection"),
      ];
    }

    if (!$items) {
      return [
        '#markup' => $this->t('No Petrichor entity modules are enabled yet. Enable the Projects, Tasks, and Wiki submodules to get started.'),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Workspaces'),
      '#items' => $items,
      '#cache' => [
        'tags' => ['petrichor_dashboard'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
