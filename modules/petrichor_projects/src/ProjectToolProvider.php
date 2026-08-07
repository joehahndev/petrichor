<?php

declare(strict_types=1);

namespace Drupal\petrichor_projects;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for projects. Thin: every tool delegates to ProjectService.
 */
class ProjectToolProvider extends McpToolProviderBase {

  public function __construct(
    protected ProjectService $projects,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return [
      [
        'name' => 'list_projects',
        'description' => 'List projects (id, title, status, client, author). Optional status filter.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => ['type' => 'string', 'enum' => ['open', 'on_hold', 'closed', 'archived']],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many results (see next_offset)'],
          ],
        ],
      ],
      [
        'name' => 'get_project',
        'description' => 'Get one project including its description.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'create_project',
        'description' => 'Create a project.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['title'],
          'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string', 'description' => 'Markdown'],
            'status' => ['type' => 'string'],
            'client' => ['type' => 'string'],
          ],
        ],
      ],
      [
        'name' => 'update_project',
        'description' => 'Update fields on a project.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'client' => ['type' => 'string'],
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
      'list_projects' => $this->projects->listProjects($args),
      'get_project' => $this->projects->getProject((int) ($args['id'] ?? 0)),
      'create_project' => $this->projects->createProject($args),
      'update_project' => $this->projects->updateProject((int) ($args['id'] ?? 0), $args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
