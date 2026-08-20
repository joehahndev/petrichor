<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\petrichor\Entity\PetrichorEntityBase;
use Drupal\petrichor\Related\RelatedRegistry;

/**
 * The `get_related` tool: the agent-facing half of the Related section.
 *
 * A new tool rather than a `related` key bolted onto get_task and
 * get_wiki_page. Those two schemas are the ones agents already read — get_task
 * has returned `related_memories` since the memory module shipped — and
 * crowding a working wire format to save a round trip is a poor trade when the
 * round trip is optional. Agents that never call this see no change at all.
 *
 * One tool covers every entity type because the registry does: relatedness is
 * the same question on a task, a memory and a wiki page, and answering it in
 * one place is what stops the browser and the agent seeing different answers.
 */
class RelatedToolProvider extends McpToolProviderBase {

  public function __construct(
    protected ?RelatedRegistry $related,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    // Nothing to advertise when no submodule contributes relatedness — the
    // tool would be structurally incapable of a non-empty answer, and the
    // rule everywhere else in this server is that the tool list is exactly
    // what is enabled. A null registry is the same answer for a stronger
    // reason: the base module providing it is not even present.
    if ($this->related === NULL || !$this->related->hasProviders()) {
      return [];
    }

    $types = array_keys($this->relatableEntityTypes());
    return [
      [
        'name' => 'get_related',
        'description' => 'Records connected to one record: things sharing its tags, and for a task the decisions and preferences that mention it. Returns titles and ids only — fetch anything worth reading with get_task, get_wiki_page or recall. Empty groups mean nothing is related, which for an untagged record is the normal answer rather than an error.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['entity_type', 'id'],
          'properties' => [
            'entity_type' => [
              'type' => 'string',
              'enum' => $types,
              'description' => 'Which kind of record to look up.',
            ],
            'id' => ['type' => 'integer'],
            'limit' => [
              'type' => 'integer',
              'description' => 'Maximum rows per group. Defaults to ' . RelatedRegistry::DEFAULT_LIMIT . '.',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function callTool(string $name, array $args): mixed {
    if ($name !== 'get_related' || $this->related === NULL) {
      throw new \InvalidArgumentException("Unknown tool: $name");
    }

    $entity_type_id = (string) ($args['entity_type'] ?? '');
    $allowed = $this->relatableEntityTypes();
    if (!isset($allowed[$entity_type_id])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown entity_type: %s. Expected one of: %s.',
        $entity_type_id === '' ? '(missing)' : $entity_type_id,
        implode(', ', array_keys($allowed)),
      ));
    }

    $id = (int) ($args['id'] ?? 0);
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException("No $entity_type_id with id $id.");
    }
    // Load is not access-checked; the read has to be. The lists inside are
    // filtered separately by the providers' own queries.
    if (!$entity->access('view')) {
      throw new \InvalidArgumentException("No $entity_type_id with id $id.");
    }

    $limit = isset($args['limit'])
      ? max(1, (int) $args['limit'])
      : RelatedRegistry::DEFAULT_LIMIT;

    return ['groups' => $this->related->getRelated($entity, $limit)->toArray()];
  }

  /**
   * Petrichor entity types that can be asked about, keyed by id.
   *
   * Every entity type in the suite is offered rather than only the tagged
   * ones. Which of them actually returns groups depends on what providers are
   * installed and on whether the record carries tags, and both can change
   * without the tool's schema changing — an enum that narrowed to today's
   * answer would be wrong tomorrow.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   *   Entity type definitions, keyed by entity type id.
   */
  protected function relatableEntityTypes(): array {
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if (is_subclass_of($definition->getClass(), PetrichorEntityBase::class)) {
        $types[$entity_type_id] = $definition;
      }
    }
    return $types;
  }

}
