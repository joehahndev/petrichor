<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\CommonMark;

use Drupal\petrichor_wiki\WikiLinkService;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Tokenizes `[[Page Title]]` and `[[Page Title|display text]]`.
 *
 * Registered at PRIORITY, above core's OpenBracketParser (20):
 * InlineParserEngine offers a position to parsers in priority order and
 * stops at the first that returns TRUE, so anything at or below 20 would
 * let `[[Foo]]` decay into core's bracket/delimiter machinery instead.
 * Everything above us — BacktickParser at 150 especially — still wins,
 * which is exactly why code spans and fenced blocks never reach this parser
 * and a page documenting the syntax renders it literally.
 *
 * Stateless: the same instance is reused for the life of the Environment, and a
 * second instance backs backlink extraction, which parses without rendering.
 */
final class WikiLinkParser implements InlineParserInterface {

  /**
   * Inline parser priority.
   *
   * 25 sits above OpenBracketParser (20) and below CloseBracketParser (30),
   * which only ever matches `]`, so nothing else competes for a `[[` position.
   */
  public const PRIORITY = 25;

  /**
   * {@inheritdoc}
   */
  public function getMatchDefinition(): InlineParserMatch {
    // A target may not contain brackets, a newline, or the pipe that separates
    // it from the display text. The alias arm is matched rather than left out
    // so `[[A|B]]` never half-parses into a link named "A" followed by "|B]]".
    return InlineParserMatch::regex('\[\[([^\[\]\n|]{1,255})(?:\|([^\[\]\n|]{1,255}))?\]\]');
  }

  /**
   * {@inheritdoc}
   */
  public function parse(InlineParserContext $inlineContext): bool {
    $matches = $inlineContext->getSubMatches();
    $target = trim($matches[0] ?? '');
    if ($target === '') {
      // `[[   ]]` is not a link. Returning FALSE hands the position back to
      // the engine, which emits the literal text.
      return FALSE;
    }
    $text = trim($matches[1] ?? '');
    $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
    // The raw target is stored, never a resolved id: the page it names may be
    // created, renamed or deleted between this parse and the next one, and a
    // baked id would silently point at the wrong page after a rename.
    $inlineContext->getContainer()->appendChild(new WikiLinkNode(
      $target,
      $text === '' ? $target : $text,
      WikiLinkService::titleKey($target),
    ));
    return TRUE;
  }

}
