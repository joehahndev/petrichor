<?php

declare(strict_types=1);

namespace Drupal\petrichor\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Renders the Petrichor workspace routes in petrichor_theme.
 *
 * Two gates, both of which must pass, because the modules are guests on
 * whatever site they land on: the theme has to actually be installed, and the
 * site has to have opted in via petrichor.settings:use_workspace_theme, which
 * ships FALSE. A host site that enables these modules keeps its own theme
 * until somebody ticks the box.
 *
 * Route matching is by route *name* family, not by request path. Path
 * sniffing would also catch anything a host site happens to hang under /pm,
 * and would miss nothing in return: every workspace route is either one of
 * the three named below, an entity route of a petrichor_* entity type, or a
 * View route whose view id starts with petrichor_ (view.petrichor_*.*) — the
 * naming convention every Views-based page here follows, e.g. the memory
 * overview replacing what used to be Memory's entity collection route. The
 * route's declared path is then checked as a second gate purely to exclude
 * our own admin pages — the per-entity settings forms live at
 * /admin/structure/petrichor/* and must stay in the admin theme.
 *
 * Note the toggle is read on every request but is not a cache tag on the
 * rendered page, so an existing page cache can outlive a flip of the setting.
 * PetrichorSettingsForm invalidates 'rendered' on save to close that window;
 * core's own admin-theme negotiator has the same shape and does not.
 */
final class PetrichorNegotiator implements ThemeNegotiatorInterface {

  /**
   * The optional companion theme.
   */
  public const THEME = 'petrichor_theme';

  /**
   * Workspace routes that are not entity routes.
   *
   * Petrichor_board.move is deliberately absent: it is a POST that returns
   * JSON, so it has no theme to negotiate.
   */
  private const ROUTE_NAMES = [
    'petrichor.dashboard',
    'petrichor_board.today',
    'petrichor_board.board',
  ];

  /**
   * Route name prefix shared by every petrichor_* entity route.
   */
  private const ENTITY_ROUTE_PREFIX = 'entity.petrichor_';

  /**
   * Route name prefix shared by every petrichor_* View page.
   *
   * A view's route is always named view.<view_id>.<display_id>; every View
   * this suite ships gives its view id a petrichor_ prefix so this one
   * string test is enough, the same shape as the entity prefix above.
   */
  private const VIEW_ROUTE_PREFIX = 'view.petrichor_';

  /**
   * Path prefixes the workspace occupies.
   */
  private const PATH_PREFIXES = ['/pm', '/wiki'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    if (!self::isWorkspaceRoute($route_match)) {
      return FALSE;
    }
    // NULL when the module is installed but the config has never been saved,
    // which is the same answer as FALSE: stay a guest.
    if (!$this->configFactory->get('petrichor.settings')->get('use_workspace_theme')) {
      return FALSE;
    }
    // Returning a theme that is not installed would fatal the request, so the
    // guard belongs here rather than in determineActiveTheme().
    return $this->themeHandler->themeExists(self::THEME);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return self::THEME;
  }

  /**
   * Whether a route belongs to the Petrichor workspace.
   *
   * Static and public because three unrelated consumers need exactly this
   * answer and must not drift from each other: this negotiator, the
   * contextual-link suppression in petrichor.module, and the
   * route.name.is_petrichor_workspace cache context that makes the
   * suppression safe to render-cache. The route family is defined once, here.
   *
   * It is a pure function of the route name — the path it also inspects is
   * the route's declared pattern, not the request URI — which is what lets
   * the cache context specialise 'route.name'.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match to test.
   *
   * @return bool
   *   TRUE for workspace routes under /pm or /wiki.
   */
  public static function isWorkspaceRoute(RouteMatchInterface $route_match): bool {
    $name = (string) $route_match->getRouteName();
    if ($name === '') {
      return FALSE;
    }
    if (!in_array($name, self::ROUTE_NAMES, TRUE)
      && !str_starts_with($name, self::ENTITY_ROUTE_PREFIX)
      && !str_starts_with($name, self::VIEW_ROUTE_PREFIX)) {
      return FALSE;
    }

    $route = $route_match->getRouteObject();
    if ($route === NULL) {
      return FALSE;
    }
    $path = $route->getPath();
    foreach (self::PATH_PREFIXES as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
