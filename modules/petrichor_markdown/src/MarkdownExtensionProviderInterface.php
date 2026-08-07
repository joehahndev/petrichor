<?php

declare(strict_types=1);

namespace Drupal\petrichor_markdown;

use Drupal\Core\Render\BubbleableMetadata;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Contributes a CommonMark extension to the shared Markdown text format.
 *
 * The seam exists so petrichor_markdown never learns what any other module
 * adds to markdown: a wiki-only or markdown-only install stays valid, and
 * disabling the contributing module leaves its syntax rendering as literal
 * text rather than erroring. Same shape as petrichor_mcp.tool_provider.
 *
 * Register an implementation with the `petrichor_markdown.extension_provider`
 * service tag.
 */
interface MarkdownExtensionProviderInterface {

  /**
   * The extension to register on the shared Environment.
   *
   * Called once per request at most: League's Environment is sealed after its
   * first conversion, so the returned object must be safe to reuse for every
   * subsequent conversion in the request.
   */
  public function getExtension(): ExtensionInterface;

  /**
   * Drains the render metadata accumulated by the last conversion.
   *
   * Draining rather than reading: a filter plugin instance survives many
   * process() calls, and metadata from one body must never leak into the
   * cacheability of the next. Implementations must reset their buffer here
   * even when it is empty.
   */
  public function takeMetadata(): BubbleableMetadata;

}
