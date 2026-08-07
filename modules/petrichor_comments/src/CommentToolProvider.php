<?php

declare(strict_types=1);

namespace Drupal\petrichor_comments;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for the task worklog. Thin: delegates to CommentService.
 */
class CommentToolProvider extends McpToolProviderBase {

  public function __construct(
    protected CommentService $comments,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return [
      [
        'name' => 'add_comment',
        'description' => 'Append a note to a task worklog. Use this to record what you did and why while you work — the next session, and any other agent, reads it back with get_comments.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['task_id', 'body'],
          'properties' => [
            'task_id' => ['type' => 'integer'],
            'body' => ['type' => 'string', 'description' => 'Markdown'],
            'subject' => [
              'type' => 'string',
              'description' => 'Optional one-line summary; derived from the first line of the body when omitted',
            ],
          ],
        ],
      ],
      [
        'name' => 'get_comments',
        'description' => 'Read a task worklog, oldest first. Page with offset when the response carries next_offset.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['task_id'],
          'properties' => [
            'task_id' => ['type' => 'integer'],
            'offset' => ['type' => 'integer'],
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
      'add_comment' => $this->comments->addComment(
        (int) ($args['task_id'] ?? 0),
        (string) ($args['body'] ?? ''),
        isset($args['subject']) ? (string) $args['subject'] : NULL,
      ),
      'get_comments' => $this->comments->getComments(
        (int) ($args['task_id'] ?? 0),
        50,
        (int) ($args['offset'] ?? 0),
      ),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
