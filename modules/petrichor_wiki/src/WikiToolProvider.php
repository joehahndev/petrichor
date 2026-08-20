<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki;

use Drupal\petrichor_mcp\McpToolProviderBase;

/**
 * MCP tools for the wiki. Thin: every tool delegates to WikiService.
 *
 * Six tools, and each one answers a question none of the others can. Search
 * finds pages you can already name; the tree answers "what is in here" for an
 * agent that cannot; revisions answer "who changed this and why", which the
 * single `changed` timestamp on a page cannot. Backlinks deliberately have no
 * tool of their own — get_wiki_page already returns them, and a second way to
 * ask the same question costs context in every session that never uses it.
 */
class WikiToolProvider extends McpToolProviderBase {

  public function __construct(
    protected WikiService $wiki,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    $tags = [
      'type' => 'array',
      'items' => ['type' => 'string'],
      'description' => 'Shared across wiki pages, tasks and memories — reuse an existing name rather than a near-duplicate.',
    ];
    return [
      [
        'name' => 'search_wiki',
        'description' => 'Search wiki pages. Results are ranked (title matches beat body matches, pages other pages link to rank higher) and each carries a plain-text snippet around the match, so relevance can be judged without fetching the page. Optional project_id filter.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['query'],
          'properties' => [
            'query' => ['type' => 'string'],
            'project_id' => ['type' => 'integer'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many results (see next_offset)'],
          ],
        ],
      ],
      [
        'name' => 'get_wiki_page',
        'description' => 'Get a wiki page: raw markdown body, breadcrumb of ancestors, child pages, and the pages that link here.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => ['id' => ['type' => 'integer']],
        ],
      ],
      [
        'name' => 'list_wiki_tree',
        'description' => 'Nested wiki page hierarchy. Use before searching, to see what exists rather than guess it may not. max_depth limits depth; page_id expands one branch; project_id scopes to a project.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'project_id' => ['type' => 'integer', 'description' => 'Omit for the whole wiki'],
            'page_id' => [
              'type' => 'integer',
              'description' => 'Expand only the branch leading to this page, plus its children',
            ],
            'root_id' => ['type' => 'integer', 'description' => 'Start from this page instead of the top level'],
            'max_depth' => ['type' => 'integer', 'description' => 'Levels to return; 1 is the top level only'],
          ],
        ],
      ],
      [
        'name' => 'get_wiki_revisions',
        'description' => 'A page\'s edit history, newest first: who saved each revision, when, and the log message they left. Returns an empty list with a note if this site does not keep wiki revisions.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => [
            'id' => ['type' => 'integer'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many revisions (see next_offset)'],
          ],
        ],
      ],
      [
        'name' => 'create_wiki_page',
        'description' => 'Create a wiki page (markdown body). Optional parent_id for hierarchy, project_id to scope. Write [[Page Title]] to link another page — the target does not have to exist yet.',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['title'],
          'properties' => [
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string', 'description' => 'Markdown'],
            'parent_id' => ['type' => 'integer'],
            'project_id' => ['type' => 'integer'],
            'tags' => $tags,
          ],
        ],
      ],
      [
        'name' => 'update_wiki_page',
        'description' => 'Update a wiki page. Use append to add to the end without resending the whole body (append wins over body if both are given).',
        'inputSchema' => [
          'type' => 'object',
          'required' => ['id'],
          'properties' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string', 'description' => 'Replaces the entire body'],
            'append' => ['type' => 'string', 'description' => 'Markdown appended after a blank line'],
            'parent_id' => [
              'type' => 'integer',
              'description' => 'Rejected if it would put the page under its own descendant',
            ],
            'revision_log' => ['type' => 'string', 'description' => 'Why this edit was made; shown in the page history'],
            'tags' => $tags,
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
      'search_wiki' => $this->wiki->searchWiki(
        (string) ($args['query'] ?? ''),
        isset($args['project_id']) ? (int) $args['project_id'] : NULL,
        25,
        (int) ($args['offset'] ?? 0),
      ),
      'get_wiki_page' => $this->wiki->getWikiPage((int) ($args['id'] ?? 0)),
      'list_wiki_tree' => $this->wiki->getTree(
        isset($args['project_id']) ? (int) $args['project_id'] : NULL,
        isset($args['page_id']) ? (int) $args['page_id'] : NULL,
        isset($args['root_id']) ? (int) $args['root_id'] : NULL,
        isset($args['max_depth']) ? max(1, (int) $args['max_depth']) : NULL,
      ),
      'get_wiki_revisions' => $this->wiki->getWikiPageRevisions(
        (int) ($args['id'] ?? 0),
        25,
        (int) ($args['offset'] ?? 0),
      ),
      'create_wiki_page' => $this->wiki->createWikiPage($args),
      'update_wiki_page' => $this->wiki->updateWikiPage((int) ($args['id'] ?? 0), $args),
      default => throw new \InvalidArgumentException("Unknown tool: $name"),
    };
  }

}
