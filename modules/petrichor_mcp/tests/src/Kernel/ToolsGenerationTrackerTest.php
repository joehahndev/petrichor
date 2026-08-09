<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The generation counter GET /mcp polls to decide whether to notify.
 *
 * Covers the tracker itself and the three hooks that bump it — the part of
 * task 190 with no HTTP involved. The stream loop that reads this value is
 * exercised over real HTTP in McpEndpointTest instead, with the wait times
 * shrunk via settings.php; a kernel test cannot open a second connection to
 * itself to prove a value written here is visible there.
 *
 * @group petrichor
 */
class ToolsGenerationTrackerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'petrichor_mcp'];

  /**
   * Bumping changes the value; repeated reads with no bump see the same one.
   *
   * Two callers agreeing on the value have not missed a change; that is all
   * this needs to guarantee, not that the value means anything on its own.
   */
  public function testBumpChangesTheValueMonotonically(): void {
    $tracker = $this->container->get('petrichor_mcp.tools_generation');
    $first = $tracker->current();
    $tracker->bump();
    $second = $tracker->current();
    $tracker->bump();
    $third = $tracker->current();

    $this->assertNotSame($first, $second);
    $this->assertNotSame($second, $third);
    // Repeated reads with no bump in between see the same value — the whole
    // point is that a poller can tell "nothing happened" from "something did".
    $this->assertSame($third, $tracker->current());
  }

  /**
   * The install hook bumps the counter.
   *
   * Calling the hook function directly rather than actually installing a
   * module: the behaviour under test is "this hook bumps the tracker", not
   * "the module system calls hook_modules_installed()", which core already
   * guarantees.
   */
  public function testModuleInstallBumpsTheCounter(): void {
    $tracker = $this->container->get('petrichor_mcp.tools_generation');
    $before = $tracker->current();
    petrichor_mcp_modules_installed(['petrichor_wiki']);
    $this->assertGreaterThan($before, $tracker->current());
  }

  /**
   * The uninstall hook bumps the counter too.
   *
   * Losing tools is as much a stale-list problem as gaining them.
   */
  public function testModuleUninstallBumpsTheCounter(): void {
    $tracker = $this->container->get('petrichor_mcp.tools_generation');
    $before = $tracker->current();
    petrichor_mcp_modules_uninstalled(['petrichor_wiki']);
    $this->assertGreaterThan($before, $tracker->current());
  }

  /**
   * The cache-flush hook bumps the counter too.
   *
   * That is the point a changed registry actually takes effect, per the
   * task this exists for.
   */
  public function testCacheFlushBumpsTheCounter(): void {
    $tracker = $this->container->get('petrichor_mcp.tools_generation');
    $before = $tracker->current();
    petrichor_mcp_cache_flush();
    $this->assertGreaterThan($before, $tracker->current());
  }

}
