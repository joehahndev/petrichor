<?php

declare(strict_types=1);

namespace Drupal\petrichor\Related;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Groups of related things, plus what they depend on for caching.
 *
 * The cacheability travels with the data rather than being reassembled by
 * whoever renders it. A related section is built entirely out of entities the
 * host page does not own, so the dependency list is the whole correctness
 * story: get it wrong and the page caches without knowing it depends on those
 * rows, edits stop invalidating it, and the resulting stale-HTML bug looks
 * like it belongs to some unrelated part of the site.
 */
final class RelatedSet implements CacheableDependencyInterface {

  /**
   * Groups, in the order providers contributed them.
   *
   * @var array<int, array{id: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup|string, items: \Drupal\petrichor\Related\RelatedItem[], note: string|null}>
   */
  protected array $groups = [];

  /**
   * What the groups depend on, for the render array that ends up holding them.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cacheability;

  public function __construct(?CacheableMetadata $cacheability = NULL) {
    $this->cacheability = $cacheability ?? new CacheableMetadata();
  }

  /**
   * Adds a group. A group with no items is dropped, not rendered empty.
   *
   * Dropping here rather than at render time means every consumer — the twig
   * component, the MCP tool, a test — sees the same thing, and none of them
   * has to re-learn that an empty group is not worth a heading.
   *
   * @param string $id
   *   Stable machine name, e.g. 'tag_siblings:petrichor_task'. Used as a
   *   render-array key and returned over the wire, so it must not change
   *   between requests for the same kind of group.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $label
   *   The heading. Translatable — this is user-facing.
   * @param \Drupal\petrichor\Related\RelatedItem[] $items
   *   The rows, already ranked and capped by the provider.
   * @param string|null $note
   *   Truncation or provenance note, e.g. "showing 5 of 12".
   */
  public function addGroup(string $id, TranslatableMarkup|string $label, array $items, ?string $note = NULL): static {
    if ($items === []) {
      return $this;
    }
    $this->groups[] = [
      'id' => $id,
      'label' => $label,
      'items' => array_values($items),
      'note' => $note,
    ];
    return $this;
  }

  /**
   * Folds another set into this one, groups and cacheability together.
   *
   * Used by the registry to merge providers. Order is contribution order:
   * whichever provider ran first keeps the top of the section.
   */
  public function merge(RelatedSet $other): static {
    foreach ($other->groups as $group) {
      $this->groups[] = $group;
    }
    $this->cacheability = $this->cacheability->merge($other->getCacheability());
    return $this;
  }

  /**
   * The groups, still holding RelatedItem objects.
   */
  public function getGroups(): array {
    return $this->groups;
  }

  /**
   * TRUE when there is nothing worth rendering a heading for.
   */
  public function isEmpty(): bool {
    return $this->groups === [];
  }

  /**
   * The mutable cacheability, for a provider to add dependencies to.
   */
  public function getCacheability(): CacheableMetadata {
    return $this->cacheability;
  }

  /**
   * Groups as plain arrays — the twig prop and MCP wire shape.
   *
   * Labels are cast to string here because both destinations want text: twig
   * would render the TranslatableMarkup object fine, but the MCP tool would
   * serialise it to `{}`.
   */
  public function toArray(): array {
    $out = [];
    foreach ($this->groups as $group) {
      $row = [
        'id' => $group['id'],
        'label' => (string) $group['label'],
        'items' => array_map(
          static fn(RelatedItem $item): array => $item->toArray(),
          $group['items'],
        ),
      ];
      if ($group['note'] !== NULL) {
        $row['note'] = $group['note'];
      }
      $out[] = $row;
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability->getCacheMaxAge();
  }

}
