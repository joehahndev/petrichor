<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Utility\Error;
use Drupal\petrichor_mcp\McpPrompts;
use Drupal\petrichor_mcp\McpResources;
use Drupal\petrichor_mcp\McpToolRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP Streamable HTTP endpoint (JSON-RPC 2.0, single responses only).
 *
 * Supports initialize, ping, tools/list, and tools/call. No SSE streaming
 * and no server-initiated messages yet; batching is not supported. Clients
 * should authenticate with basic auth (enable the core basic_auth module).
 */
final class McpController extends ControllerBase {

  protected const PROTOCOL_VERSION = '2025-06-18';

  public function __construct(
    protected McpToolRegistry $tools,
    protected McpResources $resources,
    protected McpPrompts $prompts,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('petrichor_mcp.tool_registry'),
      $container->get('petrichor_mcp.resources'),
      $container->get('petrichor_mcp.prompts'),
    );
  }

  /**
   * Handles a single JSON-RPC message.
   */
  public function handle(Request $request): Response {
    $message = json_decode($request->getContent(), TRUE);
    if (!is_array($message) || ($message['jsonrpc'] ?? NULL) !== '2.0') {
      return $this->error(NULL, -32700, 'Parse error: expected a JSON-RPC 2.0 object.');
    }

    $id = $message['id'] ?? NULL;
    $method = $message['method'] ?? '';
    $params = $message['params'] ?? [];

    // Notifications get acknowledged with no body.
    if ($id === NULL) {
      return new Response('', 202);
    }

    try {
      $result = match ($method) {
        'initialize' => [
          'protocolVersion' => self::PROTOCOL_VERSION,
          'capabilities' => [
            'tools' => new \stdClass(),
            'resources' => new \stdClass(),
            'prompts' => new \stdClass(),
          ],
          'serverInfo' => [
            'name' => 'petrichor-mcp',
            'version' => '0.2.0',
          ],
        ],
        'ping' => new \stdClass(),
        'tools/list' => ['tools' => $this->tools->getDefinitions()],
        'tools/call' => $this->callTool($params),
        'resources/list' => ['resources' => $this->resources->listResources()],
        'resources/read' => $this->resources->readResource((string) ($params['uri'] ?? '')),
        'prompts/list' => ['prompts' => $this->prompts->listPrompts()],
        'prompts/get' => $this->prompts->getPrompt((string) ($params['name'] ?? ''), $params['arguments'] ?? []),
        default => NULL,
      };
    }
    catch (\InvalidArgumentException $e) {
      return $this->error($id, -32602, $e->getMessage());
    }
    catch (\Throwable $e) {
      Error::logException($this->getLogger('petrichor_mcp'), $e);
      return $this->error($id, -32603, 'Internal error.');
    }

    if ($result === NULL) {
      return $this->error($id, -32601, "Method not found: $method");
    }

    return new JsonResponse([
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => $result,
    ]);
  }

  /**
   * Executes a tools/call request and wraps the result in MCP content.
   *
   * Deliberately NOT pretty-printed. The only consumer of this text is a
   * language model, which pays for the indentation by the token and gains
   * nothing from it: on a real context bundle, JSON_PRETTY_PRINT cost 5,035
   * characters — about 1,258 tokens, 36% of the whole response — purely in
   * whitespace. Anything reading this by eye can pipe it through `jq`.
   *
   * JSON_UNESCAPED_SLASHES stays: every path in a response would otherwise
   * carry a backslash per slash, which is both larger and harder to read.
   *
   * Every call is logged. Nothing else records what an agent did here — the
   * entity `changed` timestamp says something happened, never what asked.
   */
  protected function callTool(array $params): array {
    $name = (string) ($params['name'] ?? '');
    $args = $params['arguments'] ?? [];
    $started = microtime(TRUE);
    $data = $this->tools->call($name, is_array($args) ? $args : []);
    $text = json_encode($data, JSON_UNESCAPED_SLASHES);

    // Metadata only. Arguments and responses carry real content, and a log
    // that quietly accumulates it is a second copy of the data with none of
    // the access control.
    $this->getLogger('petrichor_mcp')->info(
      'tool @tool by @user in @ms ms, @bytes bytes', [
        '@tool' => $name,
        '@user' => $this->currentUser()->getAccountName() ?: 'anonymous',
        '@ms' => number_format((microtime(TRUE) - $started) * 1000, 1),
        '@bytes' => strlen($text),
      ]);

    return [
      'content' => [
        [
          'type' => 'text',
          'text' => $text,
        ],
      ],
      'isError' => FALSE,
    ];
  }

  /**
   * Builds a JSON-RPC error response.
   */
  protected function error(mixed $id, int $code, string $message): JsonResponse {
    return new JsonResponse([
      'jsonrpc' => '2.0',
      'id' => $id,
      'error' => ['code' => $code, 'message' => $message],
    ]);
  }

}
