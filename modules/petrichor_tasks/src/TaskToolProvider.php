<?php

declare(strict_types=1);

namespace Drupal\petrichor_tasks;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for tasks. Thin: every tool delegates to TaskService.
 */
class TaskToolProvider extends McpToolProviderBase {

  public function __construct(
    protected TaskService $tasks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    $task_fields = [
      'title' => ['type' => 'string'],
      'description' => ['type' => 'string', 'description' => 'Markdown'],
      // Sourced from the base field definition, not restated: the board, the
      // entity, and this schema must agree or a valid drop becomes an invalid
      // tool call.
      'status' => ['type' => 'string', 'enum' => array_keys($this->tasks->statusOptions())],
      'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
      'assignee' => ['type' => 'integer', 'description' => 'User id'],
      'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
      'tags' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'Shared across tasks, memories and wiki pages — reuse an existing name rather than a near-duplicate.',
      ],
      'milestone_id' => ['type' => 'integer'],
      'parent_id' => ['type' => 'integer', 'description' => 'Set to make this a subtask'],
      'estimate_hours' => ['type' => 'number'],
      'blocked' => [
        'type' => 'boolean',
        // Spelled out for the agent: blocked is not a status. Without this the
        // obvious guess is that it belongs in the status enum, and blocked
        // in-progress work stops being visible as in-progress.
        'description' => 'Waiting on something external. Orthogonal to status — a task can be in_progress and blocked.',
      ],
      'blocked_note' => ['type' => 'string', 'description' => 'What it is waiting for'],
    ];
    return [
      [
        'name' => 'list_tasks',
        'description' => 'List tasks. Filters: query (find one by name), project_id, status, assignee, due_before, milestone_id, parent_id, blocked. Results are capped — when the response carries next_offset, pass it back as offset to page through the rest.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'query' => ['type' => 'string', 'description' => 'Substring match on title, description or tags'],
            'project_id' => ['type' => 'integer'],
            'status' => $task_fields['status'],
            'assignee' => $task_fields['assignee'],
            'due_before' => $task_fields['due_date'],
            'milestone_id' => ['type' => 'integer'],
            'parent_id' => ['type' => 'integer'],
            'blocked' => ['type' => 'boolean', 'description' => 'true for only blocked work, false for only unblocked'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many results (see next_offset)'],
          ],
        ],
      ],
      [
        'name' => 'get_task',
        'description' => 'Get one task including description, milestone, and parent.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'create_task',
        'description' => 'Create a task in a project.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['title', 'project_id'],
          'properties' => ['project_id' => ['type' => 'integer']] + $task_fields,
        ],
      ],
      [
        'name' => 'update_task',
        'description' => 'Update fields on a task. Pass if_unchanged_since (ISO date from a prior read) to fail on conflict instead of overwriting.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => [
            'id' => ['type' => 'integer'],
            'if_unchanged_since' => ['type' => 'string'],
          ] + $task_fields,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function callTool(string $name, array $args): mixed {
    return match ($name) {
      'list_tasks' => $this->tasks->listTasks($args),
      'get_task' => $this->tasks->getTask((int) ($args['id'] ?? 0)),
      'create_task' => $this->tasks->createTask($args),
      'update_task' => $this->tasks->updateTask((int) ($args['id'] ?? 0), $args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
