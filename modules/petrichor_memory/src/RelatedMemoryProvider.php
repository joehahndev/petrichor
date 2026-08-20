<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\petrichor\Related\RelatedItem;
use Drupal\petrichor\Related\RelatedProviderInterface;
use Drupal\petrichor\Related\RelatedSet;

/**
 * Shows a task the decisions and preferences that mention it.
 *
 * This closes a gap rather than opening a feature. `get_task` has returned
 * `related_memories` to agents since the memory module shipped; the same task
 * opened in a browser showed nothing. In a suite whose central claim is that
 * the human and agent surfaces call one service layer and cannot drift, an
 * agent knowing more about a task than the person looking at it is the drift.
 *
 * The matching is not re-implemented or re-tuned here. MemoryService::
 * relatedTo() is called exactly as the MCP path calls it, so whatever the two
 * surfaces show, they show the same thing for the same reason.
 *
 * Tasks are named by string, not by class: this module has no dependency on
 * petrichor_tasks and must stay installable without it, so it asks for an
 * entity type id and gets nothing when tasks are absent.
 */
class RelatedMemoryProvider implements RelatedProviderInterface {

  use StringTranslationTrait;

  /**
   * The entity type this applies to.
   */
  protected const TASK_ENTITY_TYPE = 'petrichor_task';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly MemoryService $memory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityInterface $entity): bool {
    return $entity->getEntityTypeId() === self::TASK_ENTITY_TYPE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelated(ContentEntityInterface $entity, int $limit): RelatedSet {
    $set = new RelatedSet();
    // Any memory could become related by being written or retitled, and the
    // match runs against the task's title, which the registry already tags.
    $set->getCacheability()->addCacheTags(['petrichor_memory_list']);

    $project_id = NULL;
    if ($entity->hasField('project') && !$entity->get('project')->isEmpty()) {
      $project_id = (int) $entity->get('project')->target_id;
    }

    // Left at the service's own default cap rather than the section's. Three
    // is the number that was measured against real tasks; raising it here to
    // match the other groups would loosen a precision rule by accident.
    $related = $this->memory->relatedTo((string) $entity->label(), $project_id);
    if ($related === []) {
      return $set;
    }

    // relatedTo() returns ids and titles only — it is built for a wire format
    // that deliberately omits bodies. Loading the entities here is what gets
    // canonical URLs to link to and cache tags to depend on, and it costs one
    // query for at most three rows.
    $memories = $this->entityTypeManager
      ->getStorage('petrichor_memory')
      ->loadMultiple(array_column($related, 'id'));

    $items = [];
    // Iterating $related, not $memories, preserves the ranking the service
    // computed; loadMultiple() returns rows in whatever order storage likes.
    foreach ($related as $row) {
      $memory = $memories[$row['id']] ?? NULL;
      if ($memory === NULL) {
        continue;
      }
      $set->getCacheability()->addCacheableDependency($memory);
      $items[] = RelatedItem::fromEntity($memory, $this->memoryType($memory));
    }

    // No note. The MCP response carries one telling an agent to call recall
    // for the full text before acting; on a page where the titles are links
    // that would be instructions for the wrong reader.
    $set->addGroup(
      'related_memories',
      $this->t('Decisions and preferences'),
      $items,
    );

    return $set;
  }

  /**
   * The memory's type as a display label, e.g. "decision".
   *
   * Read off the field's allowed values rather than printed raw, so it stays
   * correct if a site relabels them.
   */
  protected function memoryType(ContentEntityInterface $memory): ?string {
    if (!$memory->hasField('type') || $memory->get('type')->isEmpty()) {
      return NULL;
    }
    $value = $memory->get('type')->value;
    $allowed = $memory->get('type')->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values');
    return isset($allowed[$value]) ? (string) $allowed[$value] : (string) $value;
  }

}
