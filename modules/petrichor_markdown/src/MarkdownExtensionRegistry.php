<?php

declare(strict_types=1);

namespace Drupal\petrichor_markdown;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Collects the CommonMark extensions other modules contribute.
 *
 * Thin by design — it holds no markdown knowledge of its own, so the only
 * thing that changes in petrichor_markdown when a new syntax lands elsewhere
 * is nothing.
 */
final class MarkdownExtensionRegistry {

  /**
   * Constructs a MarkdownExtensionRegistry.
   *
   * @param iterable<\Drupal\petrichor_markdown\MarkdownExtensionProviderInterface> $providers
   *   Services tagged petrichor_markdown.extension_provider.
   */
  public function __construct(
    private readonly iterable $providers,
  ) {}

  /**
   * Collects one extension from each provider.
   *
   * @return \League\CommonMark\Extension\ExtensionInterface[]
   *   Extensions in tag priority order.
   */
  public function getExtensions(): array {
    $extensions = [];
    foreach ($this->providers as $provider) {
      $extensions[] = $provider->getExtension();
    }
    return $extensions;
  }

  /**
   * Drains every provider's metadata into one object.
   *
   * Called after each conversion, unconditionally: a provider that contributed
   * nothing still has to be drained, or a stale buffer from a failed
   * conversion would attach itself to the next body rendered.
   */
  public function takeMetadata(): BubbleableMetadata {
    $metadata = new BubbleableMetadata();
    foreach ($this->providers as $provider) {
      $metadata = $metadata->merge($provider->takeMetadata());
    }
    return $metadata;
  }

}
