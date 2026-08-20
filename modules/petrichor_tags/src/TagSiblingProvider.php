<?php

declare(strict_types=1);

namespace Drupal\petrichor_tags;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\petrichor\Entity\PetrichorEntityBase;
use Drupal\petrichor\Related\RelatedItem;
use Drupal\petrichor\Related\RelatedProviderInterface;
use Drupal\petrichor\Related\RelatedSet;

/**
 * Relates records that carry the same tags.
 *
 * The strict half of "related", and deliberately the only mechanism: a tag is
 * a person or an agent asserting a connection, so a shared tag is evidence
 * rather than a guess. Nothing here matches on titles or bodies. That is not
 * caution for its own sake — petrichor_memory's README records what happened
 * when the same suite tried a looser rule for related memories, which matched
 * 15 of 19 real tasks and was wrong on nearly all of them. A field that is
 * usually wrong is worse than no field, because it teaches you to skip it.
 *
 * The consequence is that an untagged record has no related section at all.
 * That is the intended answer, not a degraded one.
 */
class TagSiblingProvider implements RelatedProviderInterface {

  use StringTranslationTrait;

  /**
   * Extra conditions applied when searching a given entity type.
   *
   * Memories are the one type with a visibility state that matters here. A
   * `proposed` memory is one an agent wrote and nobody has confirmed; the
   * whole point of the default recording policy is that it stays invisible
   * until someone signs off, and surfacing it in a related list would be a
   * back door into exactly the influence that policy exists to prevent.
   * Archived memories are equally out — `forget` is supposed to mean
   * something.
   *
   * Tasks are deliberately not filtered by status. A done task is useful
   * context: "this was already built" is worth as much as "this is in
   * progress", and often more.
   */
  protected const ENTITY_TYPE_CONDITIONS = [
    'petrichor_memory' => ['status' => 'active'],
  ];

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly TagResolver $tags,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityInterface $entity): bool {
    return $entity->hasField('tags') && !$entity->get('tags')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function getRelated(ContentEntityInterface $entity, int $limit): RelatedSet {
    $set = new RelatedSet();
    $term_ids = $this->tags->idsFor($entity);
    if ($term_ids === []) {
      return $set;
    }

    // The terms themselves are a dependency: renaming one changes every row
    // that names it, and deleting one changes who is related to whom.
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids) as $term) {
      $set->getCacheability()->addCacheableDependency($term);
    }

    foreach ($this->taggedEntityTypes() as $entity_type_id => $definition) {
      // Any record of this type could become related by being tagged, so the
      // list tag goes on whether or not this pass found anything.
      $set->getCacheability()->addCacheTags([$entity_type_id . '_list']);

      $result = $this->tags->entitiesSharing(
        $term_ids,
        $entity_type_id,
        // Exclude self only within its own type: a wiki page and a task may
        // share an id and are not the same record.
        $entity_type_id === $entity->getEntityTypeId() ? (int) $entity->id() : NULL,
        self::ENTITY_TYPE_CONDITIONS[$entity_type_id] ?? [],
        $limit,
      );
      if ($result['entities'] === []) {
        continue;
      }

      $items = [];
      foreach ($result['entities'] as $related) {
        $set->getCacheability()->addCacheableDependency($related);
        $shared = $result['overlap'][$related->id()] ?? 0;
        // Cast because a related row's meta is plain text by contract — it
        // goes to twig and to the MCP wire unchanged, and the wire would
        // serialise a markup object to {}.
        $items[] = RelatedItem::fromEntity($related, (string) $this->formatPlural(
          $shared,
          '1 shared tag',
          '@count shared tags',
        ));
      }

      $set->addGroup(
        'tag_siblings:' . $entity_type_id,
        $definition->getCollectionLabel(),
        $items,
        $this->truncationNote(count($items), $result['total']),
      );
    }

    return $set;
  }

  /**
   * Petrichor entity types that carry a tags field on this site.
   *
   * Read off the field map rather than the module's own target-type constant,
   * so an à-la-carte install where only two of the three are enabled searches
   * two and not three. Restricted to entity types in this suite so a `tags`
   * field some other module put on a node can never be mistaken for one of
   * ours.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   *   Definitions keyed by entity type id.
   */
  protected function taggedEntityTypes(): array {
    $map = $this->entityFieldManager->getFieldMap();
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (!isset($map[$entity_type_id]['tags'])) {
        continue;
      }
      if (!is_subclass_of($definition->getClass(), PetrichorEntityBase::class)) {
        continue;
      }
      $types[$entity_type_id] = $definition;
    }
    return $types;
  }

  /**
   * The honesty line under a truncated group, or nothing when it is complete.
   */
  protected function truncationNote(int $shown, int $total): ?string {
    if ($shown >= $total) {
      return NULL;
    }
    return (string) $this->t('showing @shown of @total', [
      '@shown' => $shown,
      '@total' => $total,
    ]);
  }

}
