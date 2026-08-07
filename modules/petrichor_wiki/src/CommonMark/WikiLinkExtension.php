<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\CommonMark;

use Drupal\petrichor_wiki\WikiLinkService;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Wires `[[Page Title]]` into the shared Markdown environment.
 *
 * Resolution is a single pass over the finished document rather than something
 * the renderer does per node: a hub page with forty links would otherwise cost
 * forty queries inside rendering, and rendering is the hot path the KB
 * lives on.
 */
final class WikiLinkExtension implements ExtensionInterface {

  public function __construct(
    private readonly WikiLinkService $links,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function register(EnvironmentBuilderInterface $environment): void {
    $environment
      ->addInlineParser(new WikiLinkParser(), WikiLinkParser::PRIORITY)
      ->addRenderer(WikiLinkNode::class, new WikiLinkRenderer($this->links), 0)
      ->addEventListener(DocumentParsedEvent::class, $this->resolveAll(...));
  }

  /**
   * Resolves every wiki link in one document with one lookup.
   */
  private function resolveAll(DocumentParsedEvent $event): void {
    $nodes = [];
    foreach ($event->getDocument()->iterator() as $node) {
      if ($node instanceof WikiLinkNode) {
        $nodes[] = $node;
      }
    }
    if ($nodes === []) {
      return;
    }
    $resolved = $this->links->resolve(array_map(
      static fn (WikiLinkNode $node): string => $node->getTargetKey(),
      $nodes,
    ));
    foreach ($nodes as $node) {
      // An absent key means wanted. Not an error, and deliberately not logged:
      // most KBs carry a standing set of wanted links by design.
      $node->applyResolution($resolved[$node->getTargetKey()] ?? NULL);
    }
  }

}
