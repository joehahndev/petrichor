<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

/**
 * Collects tool providers from all enabled Petrichor submodules.
 */
class McpToolRegistry {

  /**
   * Tool providers, one per enabled submodule that exposes tools.
   *
   * @var \Drupal\petrichor_mcp\McpToolProviderInterface[]
   */
  protected array $providers;

  public function __construct(iterable $providers) {
    $this->providers = is_array($providers) ? $providers : iterator_to_array($providers);
  }

  /**
   * Merged tool definitions across all providers.
   */
  public function getDefinitions(): array {
    $definitions = [];
    foreach ($this->providers as $provider) {
      $definitions = array_merge($definitions, $provider->getDefinitions());
    }
    return $definitions;
  }

  /**
   * Dispatches a tool call to its owning provider.
   *
   * @throws \InvalidArgumentException
   *   If no provider owns the tool.
   */
  public function call(string $name, array $args): mixed {
    foreach ($this->providers as $provider) {
      if ($provider->hasTool($name)) {
        return $provider->callTool($name, $args);
      }
    }
    throw new \InvalidArgumentException("Unknown tool: $name");
  }

}
