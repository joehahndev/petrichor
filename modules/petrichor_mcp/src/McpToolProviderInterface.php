<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

/**
 * A submodule's contribution to the MCP tool surface.
 *
 * Providers are tagged services (petrichor_mcp.tool_provider) collected by
 * the registry, so each Petrichor submodule ships its own tools and the
 * à-la-carte install exposes exactly what's enabled. Providers translate
 * tool arguments to service-layer calls — entity logic belongs in the
 * domain services, never here.
 */
interface McpToolProviderInterface {

  /**
   * Tool definitions in MCP tools/list format.
   */
  public function getDefinitions(): array;

  /**
   * Whether this provider owns the given tool name.
   */
  public function hasTool(string $name): bool;

  /**
   * Executes a tool and returns JSON-serializable data.
   *
   * @throws \InvalidArgumentException
   *   For bad arguments or unknown tools.
   */
  public function callTool(string $name, array $args): mixed;

}
