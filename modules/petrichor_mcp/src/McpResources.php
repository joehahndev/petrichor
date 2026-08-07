<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Exposes wiki/KB pages as MCP resources (petrichor://wiki/{id}).
 *
 * Degrades to an empty list when the wiki module is absent, per the
 * à-la-carte packaging rule.
 */
class McpResources {

  protected const CAP = 200;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Most recently changed wiki pages, capped at CAP; empty without the module.
   */
  public function listResources(): array {
    if (!$this->entityTypeManager->hasDefinition('petrichor_wiki_page')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('petrichor_wiki_page');
    $ids = $storage->getQuery()->accessCheck(TRUE)
      ->sort('changed', 'DESC')->range(0, self::CAP)->execute();
    $resources = [];
    foreach ($storage->loadMultiple($ids) as $page) {
      $resources[] = [
        'uri' => 'petrichor://wiki/' . $page->id(),
        'name' => $page->label(),
        'mimeType' => 'text/markdown',
      ];
    }
    return $resources;
  }

  /**
   * Page body behind a petrichor://wiki/{id} URI, as MCP resource contents.
   *
   * @throws \InvalidArgumentException
   *   If the URI is malformed, the wiki module is absent, or the page is not
   *   viewable by the current user — an unreadable page is reported as missing
   *   so the URI space cannot be probed.
   */
  public function readResource(string $uri): array {
    if (!preg_match('#^petrichor://wiki/(\d+)$#', $uri, $matches)) {
      throw new \InvalidArgumentException("Unknown resource URI: $uri");
    }
    if (!$this->entityTypeManager->hasDefinition('petrichor_wiki_page')) {
      throw new \InvalidArgumentException('Wiki module is not enabled.');
    }
    $page = $this->entityTypeManager->getStorage('petrichor_wiki_page')->load((int) $matches[1]);
    if (!$page || !$page->access('view')) {
      throw new \InvalidArgumentException("Resource not found: $uri");
    }
    return [
      'contents' => [
        [
          'uri' => $uri,
          'mimeType' => 'text/markdown',
          'text' => (string) $page->get('body')->value,
        ],
      ],
    ];
  }

}
