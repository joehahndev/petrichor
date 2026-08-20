<?php

declare(strict_types=1);

namespace Drupal\petrichor\Related;

use Drupal\Core\Entity\EntityInterface;

/**
 * One thing worth going to next, from some entity's point of view.
 *
 * Deliberately the same key names `petrichor_wiki:wiki_tree` nodes use — id,
 * title, url, meta. The wiki proved that shape covers a crumb trail, a
 * backlink list and a wanted-page report, and a related list is the same kind
 * of thing: places you can go next. Reusing the names means an agent reading
 * `get_related` parses rows it already knows from the wiki tools.
 */
final class RelatedItem {

  /**
   * Constructs a RelatedItem.
   *
   * @param int $id
   *   Entity id, for the caller that wants to fetch the whole thing.
   * @param string $title
   *   The entity label, as plain text.
   * @param string|null $url
   *   Where to go. Null when the viewer cannot reach it, in which case the
   *   title still renders — knowing a thing exists is worth something even
   *   when you may not open it.
   * @param string|null $meta
   *   One short fact about why this is here, e.g. "2 shared tags". Plain
   *   text, never markup.
   */
  public function __construct(
    public readonly int $id,
    public readonly string $title,
    public readonly ?string $url = NULL,
    public readonly ?string $meta = NULL,
  ) {}

  /**
   * Builds an item from an entity, resolving its canonical URL if it has one.
   *
   * Access is not re-checked here — callers query with accessCheck(TRUE), and
   * a second check on an already-filtered result is cost without a guarantee.
   * The null-url branch covers entity types with no canonical link template,
   * not entities the viewer is forbidden.
   */
  public static function fromEntity(EntityInterface $entity, ?string $meta = NULL): self {
    $url = NULL;
    if ($entity->hasLinkTemplate('canonical')) {
      $url = $entity->toUrl('canonical')->toString();
    }
    return new self(
      (int) $entity->id(),
      (string) $entity->label(),
      $url,
      $meta,
    );
  }

  /**
   * The render and wire shape, identical for twig and for MCP.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'title' => $this->title,
      'url' => $this->url,
      'meta' => $this->meta,
    ];
  }

}
