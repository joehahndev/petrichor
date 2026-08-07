<?php

declare(strict_types=1);

namespace Drupal\petrichor;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Shared access control for all Petrichor entities.
 *
 * Suite-wide permissions for now; per-project membership access is on the
 * roadmap (see ARCHITECTURE.md).
 *
 * Delete is a separate permission from manage on purpose: agent roles never
 * receive it, which enforces the no-hard-delete guarantee at the access
 * layer — regardless of transport (MCP, JSON:API on a host site, UI).
 */
class PetrichorEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view petrichor content'),
      'update' => AccessResult::allowedIfHasPermission($account, 'manage petrichor content'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete petrichor content'),

      // Core's revision routes ask for these four operations by name. Without
      // arms here the match falls through to neutral and every revision route
      // 403s with nothing logged anywhere — the entity looks revisionable and
      // the history is simply unreachable.
      //
      // Reverting is an edit (it writes a new revision, it destroys nothing);
      // discarding a revision is a delete, so it lands behind the same
      // permission agent roles never receive and the no-hard-delete guarantee
      // extends to history. Core already forbids both on the default revision.
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermission($account, 'view petrichor content'),
      'revert' => AccessResult::allowedIfHasPermission($account, 'manage petrichor content'),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete petrichor content'),

      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'manage petrichor content');
  }

}
