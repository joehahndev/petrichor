<?php

declare(strict_types=1);

namespace Drupal\petrichor_mcp;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Tracks whether the composed MCP tool surface has changed.
 *
 * The tool list is not static: McpToolRegistry composes it from whichever
 * submodules are enabled, so it moves on every `drush en`/`drush pmu`, and a
 * changed registry only actually takes effect once caches are rebuilt. This
 * is one integer, bumped at each of those points, so the streaming endpoint
 * (McpController::stream()) can tell "something changed" from "nothing
 * changed" with one cheap read instead of diffing the tool list.
 *
 * The raw key/value store, not State, and that choice is load-bearing rather
 * than stylistic. `\Drupal\Core\State\State` extends CacheCollector: the
 * first read of a key within a request caches it in memory for the rest of
 * that request, on the ordinary and normally-correct assumption that state
 * does not change out from under a single request. McpController::stream()
 * violates that assumption on purpose — it is one request that reads this
 * value repeatedly over up to a minute of wall-clock time, specifically
 * hoping to observe a write made by a *different* request in between reads.
 * Through State, every read after the first would silently return the
 * value from connection-open, forever, no matter how many bumps landed
 * while the stream was open. Caught by testStreamSendsListChanged() failing
 * against a real HTTP connection, not by reasoning about it — the same
 * failure mode does not show up in a kernel test, where "different request"
 * is not a thing that happens.
 */
class ToolsGenerationTracker {

  const COLLECTION = 'petrichor_mcp';
  const KEY = 'tools_generation';

  public function __construct(
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * The current generation.
   *
   * Two callers agreeing on this value have not missed a change between
   * their reads; that is the only property this needs, so the value itself
   * carries no other meaning.
   */
  public function current(): int {
    return (int) $this->store()->get(self::KEY, 0);
  }

  /**
   * Marks the tool surface as changed.
   */
  public function bump(): void {
    $this->store()->set(self::KEY, $this->current() + 1);
  }

  /**
   * The key/value collection this tracker owns.
   *
   * Not cached on the object: KeyValueFactoryInterface::get() is cheap
   * (a service lookup, not a query), and holding onto the collection would
   * reintroduce the exact per-instance staleness the class exists to avoid
   * if anything ever memoized it further up.
   */
  protected function store(): KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
