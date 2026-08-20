<?php

declare(strict_types=1);

namespace Drupal\petrichor\Related;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Contributes one kind of relatedness to an entity's Related section.
 *
 * Implementations are tagged `petrichor.related_provider` and collected by
 * RelatedRegistry. The base module has to be able to render a Related section
 * on a task without knowing tasks exist — it depends on nothing, by design —
 * so every answer about what relates to what comes from a submodule through
 * this interface. Uninstall the submodule and its groups stop appearing, with
 * no error and no orphaned markup.
 *
 * The same collector shape is already used twice in this suite, by
 * McpToolRegistry and MarkdownExtensionRegistry.
 */
interface RelatedProviderInterface {

  /**
   * Whether this provider has anything to say about this entity.
   *
   * Cheap checks only — this runs for every provider on every entity view.
   * Answering "does this entity even have a tags field" belongs here;
   * running the query to find out whether the result is empty does not.
   */
  public function applies(ContentEntityInterface $entity): bool;

  /**
   * Groups related to the given entity, ranked and capped.
   *
   * Only called when applies() returned TRUE. Implementations must add every
   * entity they read — and the list cache tag of every entity type they
   * queried — to the returned set's cacheability, or the host page will cache
   * without knowing it depends on them.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being viewed.
   * @param int $limit
   *   Maximum items per group. A provider may return fewer; it must not
   *   return more.
   */
  public function getRelated(ContentEntityInterface $entity, int $limit): RelatedSet;

}
