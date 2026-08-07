<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

/**
 * Session-bootstrap tools. Thin: delegates to ContextService.
 */
class ContextToolProvider extends McpToolProviderBase {

  public function __construct(
    protected ContextService $context,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return [
      [
        'name' => 'get_project_context',
        'description' => 'Hydrate a session in one call: project brief, open tasks, recent activity, milestones, wiki page index, and relevant memories. Summary detail is capped (~3k tokens); truncated lists say so — page with the list_* tools rather than assuming completeness. Wiki bodies are never inlined; fetch with get_wiki_page.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['project_id'],
          'properties' => [
            'project_id' => ['type' => 'integer'],
            'detail' => ['type' => 'string', 'enum' => ['summary', 'full'], 'default' => 'summary'],
          ],
        ],
      ],
      [
        'name' => 'get_my_work',
        'description' => 'Today\'s focus set: in-progress tasks plus anything due within 7 days (overdue flagged). Optional user_id narrows to tasks assigned to you or unassigned.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_id' => ['type' => 'integer'],
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
      'get_project_context' => $this->context->getProjectContext((int) ($args['project_id'] ?? 0), $args['detail'] ?? 'summary'),
      'get_my_work' => $this->context->getMyWork(isset($args['user_id']) ? (int) $args['user_id'] : NULL),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
