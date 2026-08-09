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
