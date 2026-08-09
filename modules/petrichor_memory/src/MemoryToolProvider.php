<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for agent memory. Thin: delegates to MemoryService.
 */
class MemoryToolProvider extends McpToolProviderBase {

  public function __construct(
    protected MemoryService $memory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    $type = ['type' => 'string', 'enum' => ['decision', 'preference', 'reference', 'learning']];
    return [
      [
        'name' => 'remember',
        'description' => 'Store a fact for future sessions (yours and other agents\'). Scope to a project when project-specific; leave global otherwise. The site decides whether it becomes active immediately or waits for the user to confirm — the response says which happened and what to do about it, so do not assume.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['title', 'fact'],
          'properties' => [
            'title' => ['type' => 'string', 'description' => 'Short summary of the fact'],
            'fact' => ['type' => 'string', 'description' => 'The fact, markdown'],
            'type' => $type,
            'project_id' => ['type' => 'integer'],
            'tags' => [
              'type' => 'array',
              'items' => ['type' => 'string'],
              'description' => 'Shared across memories, tasks and wiki pages — reuse an existing name rather than a near-duplicate.',
            ],
            'requested' => [
              'type' => 'boolean',
              'description' => 'Set only when the user actually asked you to remember this. Sites configured to record on demand reject anything else.',
            ],
          ],
        ],
      ],
      [
        'name' => 'confirm_memory',
        'description' => 'Approve a proposed memory so it becomes recallable. Only for memories the user has agreed to keep — find them with list_memories status: proposed.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'recall',
        'description' => 'Search active memories by keyword. With project_id, project-scoped matches rank above globals.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['query'],
          'properties' => [
            'query' => ['type' => 'string'],
            'project_id' => ['type' => 'integer'],
          ],
        ],
      ],
      [
        'name' => 'forget',
        'description' => 'Archive a memory (recoverable by a human; nothing is hard-deleted).',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'list_memories',
        'description' => 'List memories. Filters: project_id, type, status (active|archived, default active). include_global + project_id also returns globals.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'project_id' => ['type' => 'integer'],
            'include_global' => ['type' => 'boolean'],
            'type' => $type,
            'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many results (see next_offset)'],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function callTool(string $name, array $args): mixed {
    return match ($name) {
      'remember' => $this->memory->remember($args),
      'confirm_memory' => $this->memory->confirmMemory((int) ($args['id'] ?? 0)),
      'recall' => $this->memory->recall((string) ($args['query'] ?? ''), isset($args['project_id']) ? (int) $args['project_id'] : NULL),
      'forget' => $this->memory->forget((int) ($args['id'] ?? 0)),
      'list_memories' => $this->memory->listMemories($args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
