<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\CommonMark;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * One `[[Page Title]]` occurrence in a parsed document.
 *
 * Carries the title exactly as typed, never a page id: resolution happens once
 * per document, after parsing, so the same node type serves both rendering and
 * backlink extraction (which resolves nothing at all).
 */
final class WikiLinkNode extends AbstractInline {

  /**
   * Target page id, or NULL for a "wanted" link.
   *
   * NULL is not an error state — linking a page you have not written yet is
   * half the point of wiki-linking, and it is what the wanted-pages report
   * reads as a to-do.
   */
  private ?int $targetId = NULL;

  /**
   * TRUE when more than one page carries this title.
   */
  private bool $ambiguous = FALSE;

  /**
   * Every page id sharing the title, oldest first.
   *
   * @var int[]
   */
  private array $candidateIds = [];

  /**
   * Constructs a WikiLinkNode.
   *
   * @param string $target
   *   The link target as typed, before normalization.
   * @param string $text
   *   The text to display. Differs from $target only for the
   *   `[[Target|Display]]` form.
   * @param string $targetKey
   *   The normalized target, from WikiLinkService::titleKey().
   */
  public function __construct(
    private readonly string $target,
    private readonly string $text,
    private readonly string $targetKey,
  ) {
    parent::__construct();
  }

  /**
   * The target as typed, before normalization.
   */
  public function getTarget(): string {
    return $this->target;
  }

  /**
   * The text to display, equal to the target unless an alias was given.
   */
  public function getText(): string {
    return $this->text;
  }

  /**
   * The normalized target, which is what the link table is keyed by.
   */
  public function getTargetKey(): string {
    return $this->targetKey;
  }

  /**
   * The resolved page id, NULL for a wanted link or before resolution runs.
   */
  public function getTargetId(): ?int {
    return $this->targetId;
  }

  /**
   * Whether more than one page carries this title.
   */
  public function isAmbiguous(): bool {
    return $this->ambiguous;
  }

  /**
   * Every page id sharing this title, oldest first.
   *
   * @return int[]
   *   Empty until the document-level resolution pass has run, and empty for
   *   a wanted link even after it.
   */
  public function getCandidateIds(): array {
    return $this->candidateIds;
  }

  /**
   * Stamps the batch resolution onto the node.
   *
   * @param array|null $resolution
   *   ['id' => int, 'ids' => int[], 'ambiguous' => bool], or NULL for wanted.
   */
  public function applyResolution(?array $resolution): void {
    if ($resolution === NULL) {
      return;
    }
    $this->targetId = isset($resolution['id']) ? (int) $resolution['id'] : NULL;
    $this->candidateIds = $resolution['ids'] ?? [];
    $this->ambiguous = !empty($resolution['ambiguous']);
  }

}
