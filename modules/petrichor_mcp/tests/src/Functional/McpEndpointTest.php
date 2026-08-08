<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_mcp\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The /mcp endpoint over HTTP: who may call it, and what it does when abused.
 *
 * The tool layer is covered by kernel tests, but the endpoint itself — auth,
 * CSRF, malformed JSON-RPC, and the read-only boundary — is the surface an
 * agent and an attacker both actually touch, and nothing exercised it.
 *
 * @group petrichor
 */
class McpEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'petrichor',
    'petrichor_projects',
    'petrichor_tasks',
    'petrichor_mcp',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The endpoint URL.
   *
   * @var string
   */
  protected string $url;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->url = $this->buildUrl('/mcp');
  }

  /**
   * POSTs a JSON-RPC message, returning [status, decoded body].
   */
  protected function post(array $options, array $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']): array {
    $response = $this->getHttpClient()->post($this->url, $options + [
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/json'],
      'body' => json_encode($message),
    ]);
    return [
      $response->getStatusCode(),
      json_decode((string) $response->getBody(), TRUE),
    ];
  }

  /**
   * Basic-auth credentials for a user with the given permissions.
   */
  protected function agent(array $permissions): array {
    $user = $this->drupalCreateUser($permissions);
    return ['auth' => [$user->getAccountName(), $user->passRaw]];
  }

  /**
   * A non-cookie client works with no CSRF token, as it must.
   *
   * This is the assertion that makes the CSRF protection safe to add: every
   * real MCP client authenticates with a key or basic auth and has no session,
   * so core's check must not apply to it.
   */
  public function testTokenlessApiClientIsAllowed(): void {
    [$status, $body] = $this->post($this->agent([
      'access petrichor mcp',
      'view petrichor content',
    ]));

    $this->assertSame(200, $status);
    $this->assertNotEmpty($body['result']['tools']);
  }

  /**
   * A cookie-authenticated POST without the token is refused.
   *
   * The vulnerability this closes: the endpoint decodes its body whatever
   * Content-Type claims, so a page on another origin could form-POST here with
   * enctype="text/plain", the browser would attach this cookie, and the write
   * tools would run as this user.
   */
  public function testCookieSessionWithoutTokenIsForbidden(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access petrichor mcp',
      'view petrichor content',
    ]));

    [$status] = $this->post(['cookies' => $this->getSessionCookies()]);
    $this->assertSame(403, $status);
  }

  /**
   * The same request with a valid token is allowed.
   *
   * Cookie auth stays usable for a first-party UI; it just has to prove it is
   * first-party, which is the whole distinction CSRF turns on.
   */
  public function testCookieSessionWithTokenIsAllowed(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access petrichor mcp',
      'view petrichor content',
    ]));
    $cookies = $this->getSessionCookies();
    $token = (string) $this->getHttpClient()
      ->get($this->buildUrl('/session/token'), ['cookies' => $cookies])
      ->getBody();

    $response = $this->getHttpClient()->post($this->url, [
      'http_errors' => FALSE,
      'cookies' => $cookies,
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
      'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
    ]);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Anonymous callers get nothing.
   */
  public function testAnonymousIsRefused(): void {
    [$status] = $this->post([]);
    $this->assertContains($status, [401, 403]);
  }

  /**
   * Authenticating is not the same as being allowed in.
   */
  public function testUserWithoutTheMcpPermissionIsRefused(): void {
    [$status] = $this->post($this->agent(['view petrichor content']));
    $this->assertSame(403, $status);
  }

  /**
   * A read-only agent may read and may not write.
   *
   * The recommended shape for anything unattended, so the boundary is worth
   * pinning at the transport rather than trusting the service layer alone.
   */
  public function testReadOnlyAgentCannotWrite(): void {
    $agent = $this->agent(['access petrichor mcp', 'view petrichor content']);

    [$status, $body] = $this->post($agent, [
      'jsonrpc' => '2.0',
      'id' => 1,
      'method' => 'tools/call',
      'params' => ['name' => 'list_projects', 'arguments' => []],
    ]);
    $this->assertSame(200, $status);
    $this->assertArrayNotHasKey('error', $body);

    [, $body] = $this->post($agent, [
      'jsonrpc' => '2.0',
      'id' => 2,
      'method' => 'tools/call',
      'params' => [
        'name' => 'create_project',
        'arguments' => ['title' => 'Should not exist'],
      ],
    ]);
    // Refused as a JSON-RPC error rather than a crash, and nothing was made.
    $this->assertTrue(
      isset($body['error']) || !empty($body['result']['isError']),
      'A read-only agent was allowed to create a project.',
    );
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * A malformed body is a JSON-RPC error, not a 500.
   */
  public function testMalformedJsonIsHandled(): void {
    $agent = $this->agent(['access petrichor mcp', 'view petrichor content']);
    $response = $this->getHttpClient()->post($this->url, $agent + [
      'http_errors' => FALSE,
      'headers' => ['Content-Type' => 'application/json'],
      'body' => '{not json at all',
    ]);

    $this->assertNotSame(500, $response->getStatusCode());
    $body = json_decode((string) $response->getBody(), TRUE);
    $this->assertArrayHasKey('error', $body);
  }

  /**
   * An unknown method is reported, not silently accepted.
   */
  public function testUnknownMethodIsReported(): void {
    [, $body] = $this->post($this->agent([
      'access petrichor mcp',
      'view petrichor content',
    ]), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope/notreal']);

    $this->assertArrayHasKey('error', $body);
  }

  /**
   * An unknown tool is reported too.
   */
  public function testUnknownToolIsReported(): void {
    [, $body] = $this->post($this->agent([
      'access petrichor mcp',
      'view petrichor content',
    ]), [
      'jsonrpc' => '2.0',
      'id' => 1,
      'method' => 'tools/call',
      'params' => ['name' => 'no_such_tool', 'arguments' => []],
    ]);

    $this->assertTrue(
      isset($body['error']) || !empty($body['result']['isError']),
      'An unknown tool was not reported as an error.',
    );
  }

}
