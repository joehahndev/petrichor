<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\CommonMark;

use Drupal\Component\Utility\Html;
use Drupal\petrichor_wiki\WikiLinkService;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Renders a resolved WikiLinkNode as an anchor.
 *
 * Injection surface, stated once because it is the whole reason this class is
 * small: HtmlElement escapes attribute values (Util\Xml::escape) but inserts
 * contents verbatim, so the link text — user content, straight out of a page
 * body — is escaped here explicitly. The href never contains user content
 * either: an existing page becomes a route-generated path, and a wanted page
 * becomes the add form with the title in a query string that Url builds and
 * encodes.
 */
final class WikiLinkRenderer implements NodeRendererInterface {

  public function __construct(
    private readonly WikiLinkService $links,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement {
    if (!$node instanceof WikiLinkNode) {
      throw new \InvalidArgumentException('Expected a WikiLinkNode.');
    }
    $id = $node->getTargetId();

    $classes = ['petrichor-wikilink'];
    $attributes = [];
    if ($id === NULL) {
      // A wanted link is a to-do, not a broken link, so it stays clickable and
      // says so in the markup as well as the class — screen readers get the
      // distinction the colour makes visually.
      $classes[] = 'petrichor-wikilink--wanted';
      $attributes['data-wanted'] = 'true';
      $attributes['title'] = 'Page not written yet — create it';
    }
    elseif ($node->isAmbiguous()) {
      $classes[] = 'petrichor-wikilink--ambiguous';
      $attributes['title'] = sprintf(
        '%d pages share this title; linking to the oldest.',
        count($node->getCandidateIds()),
      );
    }

    return new HtmlElement(
      'a',
      ['href' => $this->links->urlFor($id, $node->getTarget())] + $attributes + ['class' => implode(' ', $classes)],
      // The anchor text is always what the author typed, never the target's
      // current label. That keeps one page's title out of another page's
      // rendering, and it is what lets the cache tag be title-scoped instead
      // of entity-scoped: nothing about the target is displayed here.
      Html::escape($node->getText()),
    );
  }

}
