<?php

declare(strict_types=1);

namespace Drupal\petrichor_tags;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Turns tag names into term references, and back again.
 *
 * The one place `tags: ["foo", "bar"]` (the MCP-facing shape, unchanged
 * since before this module existed) meets real taxonomy terms. Callers —
 * TaskService, MemoryService — never see a term id: they hand this class
 * names and get ids back, or hand it an entity and get names back. That
 * boundary is what let the wire-facing tool schemas stay exactly as they
 * were; an agent that already knew how to tag a task keeps working
 * unchanged once petrichor_tags is enabled.
 */
class TagResolver {

  const VOCABULARY = 'petrichor_tags';

  /**
   * Rows fetched before shared-term ranking happens in PHP.
   *
   * Matches MemoryService::matchMemories(), which caps its candidate set at
   * the same number for the same reason: the ranking cannot be expressed as a
   * query sort, so something has to bound what gets loaded to do it.
   */
  const CANDIDATE_CAP = 100;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves tag names to term ids, creating any that do not exist yet.
   *
   * Matched case-insensitively so "Bug" and "bug" become one term rather
   * than two — the whole reason to prefer this over the free-text field it
   * replaced. The vocabulary is loaded once per call and reused for every
   * name, not once per name: this suite's actual scale is tens of tags, so
   * the whole vocabulary comfortably fits in memory, and one query beats N.
   *
   * @param string[] $names
   *   Tag names as an agent or a human typed them. Blank entries are
   *   dropped rather than rejected — an empty string in a tags array is
   *   never a meaningful tag.
   *
   * @return int[]
   *   Term ids, deduplicated, in first-seen order.
   */
  public function resolveNames(array $names): array {
    $names = array_values(array_filter(
      array_map(static fn(mixed $name): string => trim((string) $name), $names),
      static fn(string $name): bool => $name !== '',
    ));
    if ($names === []) {
      return [];
    }

    $by_lower_name = $this->existingTermsByLowerName();
    $ids = [];
    foreach ($names as $name) {
      $key = mb_strtolower($name);
      if (!isset($by_lower_name[$key])) {
        $term = $this->termStorage()->create([
          'vid' => self::VOCABULARY,
          'name' => $name,
        ]);
        $term->save();
        $by_lower_name[$key] = (int) $term->id();
      }
      $ids[] = $by_lower_name[$key];
    }
    return array_values(array_unique($ids));
  }

  /**
   * Term ids whose name contains the given keyword.
   *
   * The bridge a free-text search needs: entity queries can filter a
   * reference field by target id (`condition('tags', $ids, 'IN')`), but
   * have no way to reach across the reference to LIKE-match the term's own
   * name column directly. Find the matching terms here first; the caller
   * conditions on their ids.
   */
  public function idsMatching(string $keyword): array {
    $keyword = trim($keyword);
    if ($keyword === '') {
      return [];
    }
    $storage = $this->termStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->condition('name', $keyword, 'CONTAINS')
      ->execute();
    return $ids ? array_map('intval', array_values($ids)) : [];
  }

  /**
   * The tag names on one entity's `tags` field, in stored order.
   *
   * Empty for an entity with no `tags` field at all — the caller decides
   * whether that means "nothing to show" or "not supported here"; this
   * class only answers what the field itself holds.
   */
  public function namesFor(ContentEntityInterface $entity): array {
    if (!$entity->hasField('tags') || $entity->get('tags')->isEmpty()) {
      return [];
    }
    $names = [];
    foreach ($entity->get('tags')->referencedEntities() as $term) {
      $names[] = (string) $term->label();
    }
    return $names;
  }

  /**
   * The term ids on one entity's `tags` field, in stored order.
   *
   * The id-shaped counterpart to namesFor(). Relatedness compares ids, never
   * names: two entities are related because they point at the same term, and
   * going back out to names to compare them would reintroduce exactly the
   * case-sensitivity this class exists to remove.
   */
  public function idsFor(ContentEntityInterface $entity): array {
    if (!$entity->hasField('tags') || $entity->get('tags')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($entity->get('tags') as $item) {
      $ids[] = (int) $item->target_id;
    }
    return array_values(array_unique($ids));
  }

  /**
   * Entities of one type carrying at least one of the given terms.
   *
   * The third edge of this class. resolveNames() is names to ids and
   * namesFor() is entity to names; until this existed nothing could ask the
   * question a Related section is made of — *which records carry this term*.
   *
   * One query per entity type, by necessity rather than by oversight: `tags`
   * is a base field on three separate entity types with three separate
   * storage tables and no shared index, so there is no single query that
   * spans them. At this suite's scale — tens of tags, thousands of rows — the
   * three lookups are cheaper than the index that would avoid them.
   *
   * Ranking is done in PHP because an entity query cannot order by "how many
   * of these targets matched". The candidate set is capped before that, so
   * the sort is over at most CANDIDATE_CAP rows however large the site gets;
   * the reported total is the honest count, not the capped one.
   *
   * @param int[] $term_ids
   *   Terms to match. An empty array matches nothing and returns early —
   *   never "everything", which is the failure mode that would put an
   *   unrelated list on every untagged page in the suite.
   * @param string $entity_type_id
   *   The entity type to search.
   * @param int|null $exclude_id
   *   An entity to leave out, normally the one being viewed.
   * @param array $conditions
   *   Extra field conditions as name => value, e.g. ['status' => 'active'].
   * @param int $limit
   *   Maximum entities to return.
   *
   * @return array{entities: \Drupal\Core\Entity\ContentEntityInterface[], total: int, overlap: array<int, int>}
   *   Entities ranked by shared-term count then recency; the total number that
   *   matched before the limit was applied; and the shared-term count per
   *   entity id, which the caller needs to say *why* a row is in the list.
   */
  public function entitiesSharing(array $term_ids, string $entity_type_id, ?int $exclude_id = NULL, array $conditions = [], int $limit = 5): array {
    $empty = ['entities' => [], 'total' => 0, 'overlap' => []];
    if ($term_ids === [] || $limit < 1) {
      return $empty;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $query = $storage->getQuery()
      // Access-checked: a related list must never disclose the existence of
      // something the viewer cannot open.
      ->accessCheck(TRUE)
      ->condition('tags', $term_ids, 'IN');
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    if ($exclude_id !== NULL) {
      $query->condition($storage->getEntityType()->getKey('id'), $exclude_id, '<>');
    }

    $total = (int) (clone $query)->count()->execute();
    if ($total === 0) {
      return $empty;
    }

    $ids = $query
      ->sort('changed', 'DESC')
      ->range(0, self::CANDIDATE_CAP)
      ->execute();
    if (!$ids) {
      return ['entities' => [], 'total' => $total, 'overlap' => []];
    }

    $entities = $storage->loadMultiple($ids);
    $overlap = [];
    foreach ($entities as $id => $entity) {
      $overlap[$id] = count(array_intersect($this->idsFor($entity), $term_ids));
    }

    uasort($entities, function ($a, $b) use ($overlap): int {
      return ($overlap[$b->id()] <=> $overlap[$a->id()])
        ?: ((int) $b->get('changed')->value <=> (int) $a->get('changed')->value);
    });

    return [
      'entities' => array_slice(array_values($entities), 0, $limit),
      'total' => $total,
      'overlap' => $overlap,
    ];
  }

  /**
   * Every term in the vocabulary, keyed by lowercased name.
   */
  protected function existingTermsByLowerName(): array {
    $storage = $this->termStorage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', self::VOCABULARY)
      ->execute();
    $by_lower_name = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $term) {
      $by_lower_name[mb_strtolower((string) $term->label())] = (int) $term->id();
    }
    return $by_lower_name;
  }

  /**
   * Storage handler for taxonomy terms.
   */
  protected function termStorage() {
    return $this->entityTypeManager->getStorage('taxonomy_term');
  }

}
