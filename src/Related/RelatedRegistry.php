<?php

declare(strict_types=1);

namespace Drupal\petrichor\Related;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Collects related-content providers from all enabled Petrichor submodules.
 *
 * The one place the Related section is assembled, for every surface that shows
 * it: the entity view hook, the wiki controller (whose route bypasses that
 * hook), and the `get_related` MCP tool. Three callers, one answer — the same
 * arrangement that keeps every other pair of human and agent surfaces in this
 * suite from drifting.
 */
class RelatedRegistry {

  /**
   * Items per group, before truncation is disclosed.
   *
   * Five is a compromise between the two caps already in the suite:
   * RELATED_MEMORY_CAP is 3, because a passive suggestion that is wrong is
   * expensive; WikiController::BACKLINKS_INLINE is 10, because a backlink is
   * a fact rather than a guess. A shared tag is an explicit assertion, so it
   * sits nearer the confident end, but three groups of ten would bury the
   * page it is attached to.
   */
  public const DEFAULT_LIMIT = 5;

  /**
   * Providers, one per enabled submodule that contributes relatedness.
   *
   * @var \Drupal\petrichor\Related\RelatedProviderInterface[]
   */
  protected array $providers;

  public function __construct(iterable $providers) {
    $this->providers = is_array($providers) ? $providers : iterator_to_array($providers);
  }

  /**
   * Whether anything at all can contribute relatedness on this site.
   *
   * Lets a caller decide not to advertise a Related surface rather than
   * advertise one that is structurally always empty — the MCP tool list uses
   * this so an install with no providers never offers `get_related`.
   */
  public function hasProviders(): bool {
    return $this->providers !== [];
  }

  /**
   * Everything related to one entity, from every provider that applies.
   *
   * Never throws for an entity nobody has anything to say about: an empty set
   * is a correct answer, and callers render nothing rather than an empty box.
   */
  public function getRelated(ContentEntityInterface $entity, int $limit = self::DEFAULT_LIMIT): RelatedSet {
    $set = new RelatedSet();
    // The host entity itself. Editing a task must invalidate the tag-sibling
    // lists on the pages that mention it, and re-tagging the task changes its
    // own section — both follow from this one tag.
    $set->getCacheability()->addCacheableDependency($entity);
    // Access filtering decides what is in the lists, so two users with
    // different permissions must not share a cache entry.
    $set->getCacheability()->addCacheContexts(['user.permissions']);

    foreach ($this->providers as $provider) {
      if ($provider->applies($entity)) {
        $set->merge($provider->getRelated($entity, $limit));
      }
    }
    return $set;
  }

}
