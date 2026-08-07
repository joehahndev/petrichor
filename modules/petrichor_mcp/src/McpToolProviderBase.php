<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

/**
 * Convenience base for tool providers.
 */
abstract class McpToolProviderBase implements McpToolProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function hasTool(string $name): bool {
    foreach ($this->getDefinitions() as $definition) {
      if ($definition['name'] === $name) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
