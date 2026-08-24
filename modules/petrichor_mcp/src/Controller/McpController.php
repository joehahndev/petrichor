<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Drupal\Core\Utility\Error;
use Drupal\petrichor_mcp\McpPrompts;
use Drupal\petrichor_mcp\McpResources;
use Drupal\petrichor_mcp\McpToolRegistry;
use Drupal\petrichor_mcp\ToolsGenerationTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MCP Streamable HTTP endpoint (JSON-RPC 2.0).
 *
 * POST supports initialize, ping, tools/list, and tools/call, each as a
 * single JSON response; batching is not supported. GET opens an SSE stream
 * that sends notifications/tools/list_changed when the composed tool set
 * moves (see ToolsGenerationTracker) and otherwise says nothing — this
 * server has no other server-initiated message to send yet. That stream is
 * opt-in and off by default; see DEFAULT_STREAM_ENABLED for why. Clients
 * should authenticate with basic auth (enable the core basic_auth module).
 */
final class McpController extends ControllerBase {

  protected const PROTOCOL_VERSION = '2025-06-18';

  /**
   * How long one GET connection stays open before closing on its own.
   *
   * Each open stream holds a PHP-FPM worker for its whole life, so this is
   * bounded rather than infinite. Claude Code (and any spec-compliant
   * client) reconnects with backoff on its own when a stream ends, so a
   * closed-and-reopened stream is invisible to anything but this file.
   * Overridable in settings.php for tests, which cannot wait 55 real seconds.
   */
  protected const DEFAULT_STREAM_SECONDS = 55;

  /**
   * How often the open stream re-checks the generation counter.
   */
  protected const DEFAULT_POLL_SECONDS = 2;

  /**
   * Whether GET /mcp offers an SSE stream at all.
   *
   * Off by default because the cost is structural, not incidental: one open
   * stream occupies one PHP-FPM worker for its entire life, so a site with N
   * concurrent MCP clients needs N workers doing nothing but sleeping, on top
   * of the workers serving real requests. A pool of 8 — ddev's default, and
   * not unusual in production — is exhausted by 8 connected clients, at which
   * point every other request on the site, the UI included, queues until
   * something gives up. Bounding each connection (DEFAULT_STREAM_SECONDS)
   * caps how long one worker is held, but not how many are held at once, and
   * a spec-compliant client reconnects immediately on close.
   *
   * What that buys is a single notification, tools/list_changed, which fires
   * only when the composed tool set moves — a module install or uninstall.
   * On a site whose module list is stable, which is most of them, it is a
   * permanent worker per client for an event that may never fire.
   *
   * Enable it where the worker pool is sized for the expected client count:
   * @code
   * $settings['petrichor_mcp_stream_enabled'] = TRUE;
   * @endcode
   */
  protected const DEFAULT_STREAM_ENABLED = FALSE;

  public function __construct(
    protected McpToolRegistry $tools,
    protected McpResources $resources,
    protected McpPrompts $prompts,
    protected ToolsGenerationTracker $toolsGeneration,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('petrichor_mcp.tool_registry'),
      $container->get('petrichor_mcp.resources'),
      $container->get('petrichor_mcp.prompts'),
      $container->get('petrichor_mcp.tools_generation'),
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
            // listChanged is a promise, not decoration, so it tracks whether
            // the stream is actually being served: a client that holds a
            // connection open on the strength of a flag with nothing behind
            // it is worse off than one that never saw the flag. See stream().
            'tools' => ['listChanged' => $this->streamEnabled()],
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
   * Whether this site serves the GET /mcp SSE stream.
   *
   * Read in two places that have to agree: the capability advertised at
   * initialize, and the endpoint that would honour it.
   */
  protected function streamEnabled(): bool {
    return (bool) Settings::get('petrichor_mcp_stream_enabled', self::DEFAULT_STREAM_ENABLED);
  }

  /**
   * Opens an SSE stream and pushes tools/list_changed when the tool set moves.
   *
   * This is a poll loop over one State value, not a push from the code that
   * actually changes the tool set — Drupal has no pub/sub between a request
   * that runs hook_modules_installed() and a PHP-FPM worker blocked in a
   * different request's while loop, and this site has no message queue or
   * Redis to build one on. Polling State every couple of seconds is the
   * mechanism that fits the infrastructure actually here.
   *
   * The stream closes itself after DEFAULT_STREAM_SECONDS regardless of
   * whether anything changed, so no single connection pins a worker
   * indefinitely. That bounds one connection, not the pool: N connected
   * clients still occupy N workers continuously, because a spec-compliant
   * client reconnects as soon as the stream closes. Sizing the worker pool
   * above the expected client count is therefore a requirement of turning
   * this on, not a tuning detail — see DEFAULT_STREAM_ENABLED.
   */
  public function stream(): Response {
    if (!$this->streamEnabled()) {
      // What the Streamable HTTP transport specifies for a server that does
      // not offer SSE on GET. A spec-compliant client reads it as "POST
      // only" and stops asking, rather than treating it as a failure and
      // retrying — which is the difference between a disabled feature and
      // a client hammering a dead endpoint.
      return new Response('', 405, ['Allow' => 'POST']);
    }
    $seen = $this->toolsGeneration->current();
    $stream_seconds = (int) Settings::get('petrichor_mcp_stream_seconds', self::DEFAULT_STREAM_SECONDS);
    $poll_seconds = (int) Settings::get('petrichor_mcp_poll_seconds', self::DEFAULT_POLL_SECONDS);

    $response = new StreamedResponse(function () use ($seen, $stream_seconds, $poll_seconds) {
      // Padding, not a message: some intermediaries — confirmed on Apache's
      // mod_proxy_fcgi, which this site does not run but a host site might
      // — hold the response in a fixed-size buffer regardless of how often
      // flush() is called, and only forward it once that buffer fills or
      // the response ends. flush() alone measured a 4s delay here; 8 KiB of
      // padding (comfortably past the common 4-8 KiB buffer sizes this kind
      // of intermediary tends to use) forces the buffer to fill on the
      // first write, so the client gets bytes immediately either way.
      $this->writeSseLine(':' . str_repeat('0', 8192));
      // A comment line, not a message: some intermediaries hold back the
      // first byte of a response until enough of it arrives to be worth
      // sending on. This gives the client something immediately so it knows
      // the stream is actually open rather than still connecting.
      $this->writeSseLine(':stream open');
      $elapsed = 0;
      while (!connection_aborted() && $elapsed < $stream_seconds) {
        $current = $this->toolsGeneration->current();
        if ($current !== $seen) {
          $seen = $current;
          $this->writeSseMessage(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);
        }
        sleep(max(1, $poll_seconds));
        $elapsed += max(1, $poll_seconds);
      }
    });
    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('Connection', 'keep-alive');
    // Told to nginx explicitly: the default is to buffer a proxied response
    // until it is complete or a buffer fills, which would turn this stream
    // into "nothing until close" — the opposite of what it exists to do.
    $response->headers->set('X-Accel-Buffering', 'no');
    return $response;
  }

  /**
   * Writes one JSON-RPC message as an SSE `data:` event and flushes it.
   */
  private function writeSseMessage(array $message): void {
    $this->writeSseLine('data: ' . json_encode($message, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Writes one raw SSE line, already formatted, and flushes immediately.
   *
   * Buffered output is indistinguishable from a dead stream to a client
   * waiting on it.
   */
  private function writeSseLine(string $line): void {
    echo $line . "\n\n";
    if (ob_get_level() > 0) {
      @ob_flush();
    }
    flush();
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
      // No 'structuredContent'. Measured 2026-08-07 rather than assumed: the
      // spec (2025-06-18) says a tool returning it SHOULD also return the
      // serialized JSON in a text block for compatibility, so both go on the
      // wire — list_projects went 677 -> 1,026 chars, 52% larger. The client
      // then read `content[0].text` and ignored structuredContent, so the
      // extra field bought nothing and cost half again per call.
      //
      // Worth re-testing when a client is known to prefer structuredContent
      // AND to drop the duplicate text; until both are true this is a pure
      // loss. See task 185.
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
