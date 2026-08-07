<?php

declare(strict_types=1);

namespace Drupal\petrichor\Cache;

use Drupal\Core\Cache\Context\RouteNameCacheContext;
use Drupal\petrichor\Theme\PetrichorNegotiator;

/**
 * Whether the current route is a Petrichor workspace route.
 *
 * Cache context ID: 'route.name.is_petrichor_workspace'.
 *
 * Exists so that petrichor_block_view_alter() can strip contextual links
 * inside the workspace without poisoning the block render cache for the rest
 * of the site. Two values, so a block that appears both inside and outside
 * the workspace costs one extra cache entry — the same trade Layout Builder
 * makes with route.name.is_layout_builder_ui, and for the same reason.
 *
 * A specialisation of 'route.name' rather than 'route': the answer depends
 * only on the matched route (its name, and the path pattern that name
 * implies), never on the request URI.
 *
 * @internal
 *   Tagged services are internal.
 */
final class WorkspaceRouteCacheContext extends RouteNameCacheContext {

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Petrichor workspace route');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    return PetrichorNegotiator::isWorkspaceRoute($this->routeMatch)
      ? 'is_petrichor_workspace.1'
      : 'is_petrichor_workspace.0';
  }

}
