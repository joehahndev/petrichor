<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends the old /kb paths to their /wiki equivalents.
 *
 * The wiki lived at /kb until the whole feature was renamed to say "wiki"
 * everywhere. Every machine name was already "wiki" — the module, the entity
 * type, the tables, the tool names — so the URL was the last thing still
 * calling it a knowledge base, and the last thing telling a reader the suite
 * had two names for one feature.
 *
 * A subscriber rather than a set of legacy routes, because it covers every
 * sub-path uniformly with one rule: the canonical page, the revision routes,
 * the search form's own action, and — the case routes would miss — a /kb link
 * somebody typed by hand into a wiki page body years before the rename.
 *
 * Runs above the router so nothing has to resolve /kb to a route first; there
 * are no /kb routes left to resolve.
 */
final class LegacyKbPathSubscriber implements EventSubscriberInterface {

  /**
   * The path prefix this redirects away from.
   */
  private const LEGACY_PREFIX = '/kb';

  /**
   * Where it goes instead.
   */
  private const CURRENT_PREFIX = '/wiki';

  /**
   * Redirects a legacy /kb request to /wiki.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $path = $event->getRequest()->getPathInfo();
    if ($path !== self::LEGACY_PREFIX && !str_starts_with($path, self::LEGACY_PREFIX . '/')) {
      return;
    }

    $target = self::CURRENT_PREFIX . substr($path, strlen(self::LEGACY_PREFIX));
    // The query string carries ?q= on the search route, so dropping it would
    // turn a bookmarked search into an empty form.
    $query = $event->getRequest()->getQueryString();
    if ($query !== NULL && $query !== '') {
      $target .= '?' . $query;
    }

    // 301: the move is permanent, and a browser that caches it stops asking.
    $event->setResponse(new RedirectResponse($target, 301));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Above Drupal's own router (32) so this answers before anything tries to
    // match a route that no longer exists.
    return [KernelEvents::REQUEST => [['onRequest', 100]]];
  }

}
