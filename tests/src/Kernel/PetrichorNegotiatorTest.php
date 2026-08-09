<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\petrichor\Theme\PetrichorNegotiator;
use Drupal\Core\Routing\RouteMatch;
use Symfony\Component\Routing\Route;

/**
 * Which routes render in petrichor_theme.
 *
 * PetrichorNegotiator::isWorkspaceRoute() is a pure function of the route
 * name, shared by three
 * unrelated call sites (the negotiator itself, contextual-link suppression,
 * and a cache context) — a route family it misses is invisible in all three
 * at once, silently, since nothing errors when a route just renders in the
 * host theme instead. That is exactly what happened to the memory overview
 * View: it replaced Memory's entity collection route (which matched the
 * entity.petrichor_ prefix) with a Views route (view.petrichor_memory_
 * overview.page_1, which matched nothing), and the page quietly lost the
 * workspace theme with no error to notice it by. This test is that regression,
 * pinned.
 *
 * @group petrichor
 */
class PetrichorNegotiatorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['petrichor'];

  /**
   * Builds a route match for a given route name and path.
   */
  protected function routeMatch(string $name, string $path): RouteMatch {
    return new RouteMatch($name, new Route($path));
  }

  /**
   * The three hand-named non-entity routes are recognised.
   */
  public function testNamedRoutesAreWorkspaceRoutes(): void {
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute($this->routeMatch('petrichor.dashboard', '/pm')));
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute($this->routeMatch('petrichor_board.today', '/pm/today')));
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute($this->routeMatch('petrichor_board.board', '/pm/board/{petrichor_project}')));
  }

  /**
   * Any entity.petrichor_* route under /pm or /kb is a workspace route.
   */
  public function testEntityRoutesAreWorkspaceRoutes(): void {
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('entity.petrichor_task.canonical', '/pm/task/{petrichor_task}'),
    ));
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('entity.petrichor_wiki_page.canonical', '/kb/{petrichor_wiki_page}'),
    ));
  }

  /**
   * A View route for a petrichor_* view is a workspace route.
   *
   * The regression this task fixed: view.petrichor_memory_overview.page_1
   * matches neither the hand-named list nor the entity prefix, and used to
   * fall through to FALSE.
   */
  public function testViewRoutesAreWorkspaceRoutes(): void {
    $this->assertTrue(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('view.petrichor_memory_overview.page_1', '/pm/memories'),
    ));
  }

  /**
   * A petrichor_* route outside /pm or /kb is not a workspace route.
   *
   * The per-entity settings forms (/admin/structure/petrichor/*) are exactly
   * this shape — they must stay in the admin theme.
   */
  public function testPetrichorRouteOutsideWorkspacePathIsNotMatched(): void {
    $this->assertFalse(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('view.petrichor_memory_overview.page_1', '/admin/structure/petrichor/memory'),
    ));
  }

  /**
   * A route belonging to some other module entirely is never a match.
   *
   * Path sniffing alone would also catch a host site's own routes hung
   * under /pm by coincidence; matching by route name family is what avoids
   * theming content this suite does not own.
   */
  public function testUnrelatedRouteIsNotMatched(): void {
    $this->assertFalse(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('entity.node.canonical', '/pm/some-coincidental-path'),
    ));
    $this->assertFalse(PetrichorNegotiator::isWorkspaceRoute(
      $this->routeMatch('view.frontpage.page_1', '/pm'),
    ));
  }

}
