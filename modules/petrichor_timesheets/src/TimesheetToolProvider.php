<?php

declare(strict_types=1);

namespace Drupal\petrichor_timesheets;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for timesheets. Thin: delegates to TimesheetService.
 */
class TimesheetToolProvider extends McpToolProviderBase {

  public function __construct(
    protected TimesheetService $timesheets,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return [
      [
        'name' => 'log_time',
        'description' => 'Log a time entry against a task.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['task_id', 'hours'],
          'properties' => [
            'task_id' => ['type' => 'integer'],
            'hours' => ['type' => 'number'],
            'summary' => ['type' => 'string', 'description' => 'What was worked on'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today'],
            'kind' => ['type' => 'string', 'enum' => ['development', 'design', 'meeting', 'research', 'admin', 'other']],
          ],
        ],
      ],
      [
        'name' => 'list_timesheets',
        'description' => 'List time entries. Filters: task_id, project_id, since/until (YYYY-MM-DD). listed_hours sums only the returned rows — page with offset before reporting a period total.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'task_id' => ['type' => 'integer'],
            'project_id' => ['type' => 'integer'],
            'since' => ['type' => 'string'],
            'until' => ['type' => 'string'],
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
      'log_time' => $this->timesheets->logTime($args),
      'list_timesheets' => $this->timesheets->listTimesheets($args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
