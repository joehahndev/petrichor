<?php

declare(strict_types=1);

namespace Drupal\petrichor_milestones;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for milestones. Thin: delegates to MilestoneService.
 */
class MilestoneToolProvider extends McpToolProviderBase {

  public function __construct(
    protected MilestoneService $milestones,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    $fields = [
      'title' => ['type' => 'string'],
      'status' => ['type' => 'string', 'enum' => ['planned', 'active', 'done']],
      'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
      'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
      'color' => ['type' => 'string', 'description' => 'Hex color for boards/timelines, e.g. #1f6feb'],
    ];
    return [
      [
        'name' => 'list_milestones',
        'description' => 'List milestones sorted by end date. Filters: project_id, status.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'project_id' => ['type' => 'integer'],
            'status' => $fields['status'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many results (see next_offset)'],
          ],
        ],
      ],
      [
        'name' => 'get_milestone',
        'description' => 'Get one milestone including its color and the tasks assigned to it.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'create_milestone',
        'description' => 'Create a milestone in a project.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['title', 'project_id'],
          'properties' => ['project_id' => ['type' => 'integer']] + $fields,
        ],
      ],
      [
        'name' => 'update_milestone',
        'description' => 'Update fields on a milestone.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']] + $fields,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function callTool(string $name, array $args): mixed {
    return match ($name) {
      'list_milestones' => $this->milestones->listMilestones($args),
      'get_milestone' => $this->milestones->getMilestone((int) ($args['id'] ?? 0)),
      'create_milestone' => $this->milestones->createMilestone($args),
      'update_milestone' => $this->milestones->updateMilestone((int) ($args['id'] ?? 0), $args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
