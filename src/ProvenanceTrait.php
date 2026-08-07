<?php

declare(strict_types=1);

namespace Drupal\petrichor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Builds provenance metadata for tool responses.
 *
 * Threat-model requirement: provenance labels live in the response envelope,
 * derived from entity metadata — never parsed from or embedded in content
 * bodies, so content cannot spoof authorship.
 */
trait ProvenanceTrait {

  /**
   * Common stub fields for any Petrichor entity: id, title, provenance.
   */
  protected function baseStub(EntityInterface $entity): array {
    $stub = [
      'id' => (int) $entity->id(),
      'title' => $entity->label(),
    ];
    if ($entity instanceof EntityOwnerInterface && ($owner = $entity->getOwner())) {
      $is_agent = (bool) array_filter($owner->getRoles(), fn(string $role) => str_starts_with($role, 'agent_'));
      // Anonymous owns anything created by drush/install code; an empty name
      // reads as missing data rather than "not a person".
      $name = $owner->getAccountName();
      if ($name === '') {
        $name = $owner->isAnonymous() ? 'system' : 'uid-' . $owner->id();
      }
      $stub['author'] = [
        'name' => $name,
        'is_agent' => $is_agent,
      ];
    }
    // No 'created' here: on a 25-row list it is pure weight, and 'changed'
    // is the timestamp callers actually reason about. Singular reads add it
    // back via withCreated().
    if ($entity->hasField('changed') && !$entity->get('changed')->isEmpty()) {
      $stub['changed'] = date('c', (int) $entity->get('changed')->value);
    }
    return $stub;
  }

  /**
   * Adds the creation timestamp — for singular reads, not lists.
   */
  protected function withCreated(array $stub, EntityInterface $entity): array {
    if ($entity->hasField('created') && !$entity->get('created')->isEmpty()) {
      $stub['created'] = date('c', (int) $entity->get('created')->value);
    }
    return $stub;
  }

  /**
   * Wraps a list with truncation metadata so agents never assume completeness.
   *
   * When more rows exist, the note names the exact offset to pass next — a
   * truncated list is useless if the caller can't reach the rest.
   */
  protected function listResult(array $items, int $total, int $offset = 0): array {
    $result = ['items' => $items, 'total' => $total];
    $shown = count($items);

    // Hoist a uniform author. Repeating an identical provenance block on
    // every row is most of a list's weight, but dropping authorship outright
    // would lose the human-vs-agent signal the threat model relies on — so
    // state it once for the whole list instead.
    $authors = array_filter(array_column($items, 'author'));
    if ($shown > 1 && count($authors) === $shown
      && count(array_unique(array_map('serialize', $authors))) === 1) {
      foreach ($result['items'] as &$item) {
        unset($item['author']);
      }
      unset($item);
      $result['author'] = reset($authors);
      $result['author_note'] = 'every listed item has this author';
    }

    if ($offset > 0 || $shown < $total) {
      $result['offset'] = $offset;
      $end = $offset + $shown;
      $result['note'] = sprintf('showing %d-%d of %d', $shown ? $offset + 1 : $offset, $end, $total);
      if ($end < $total) {
        $result['next_offset'] = $end;
        $result['note'] .= sprintf('; pass offset=%d for more', $end);
      }
    }
    return $result;
  }

  /**
   * Normalizes a paging offset from tool arguments.
   */
  protected function offset(array $filters): int {
    return max(0, (int) ($filters['offset'] ?? 0));
  }

}
