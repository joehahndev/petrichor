<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory\Controller;

use Drupal\Core\Access\AccessException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\petrichor_memory\MemoryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * One-click status changes for a memory.
 *
 * Holds no entity logic — MemoryService::setStatus() owns the transition and
 * the permission rule, the same division every other controller in this suite
 * keeps. What lives here is the HTTP part: where to send the reader back to,
 * and what to tell them.
 */
final class MemoryStatusController extends ControllerBase {

  public function __construct(
    protected readonly MemoryService $memory,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('petrichor_memory.service'),
      $container->get('request_stack'),
    );
  }

  /**
   * Moves one memory to the given status and returns where it came from.
   */
  public function setStatus(EntityInterface $petrichor_memory, string $status): RedirectResponse {
    $labels = $this->memory->statusOptions();
    try {
      $this->memory->setStatus((int) $petrichor_memory->id(), $status);
    }
    catch (\InvalidArgumentException $e) {
      // setStatus() raises this both for a genuinely bad request and for the
      // maintainer-only archive permission. Only the second is worth a 403 —
      // the rest is the reader's own stale page, so it gets a message rather
      // than an error page.
      if (str_contains($e->getMessage(), 'archive petrichor memories')) {
        throw new AccessDeniedHttpException($e->getMessage(), $e);
      }
      $this->messenger()->addWarning($e->getMessage());
      return $this->back($petrichor_memory);
    }
    catch (AccessException $e) {
      throw new AccessDeniedHttpException($e->getMessage(), $e);
    }

    $this->messenger()->addStatus($this->t('%label is now @status.', [
      '%label' => $petrichor_memory->label(),
      '@status' => $labels[$status] ?? $status,
    ]));
    return $this->back($petrichor_memory);
  }

  /**
   * Back where the click came from, or the memory itself.
   *
   * These buttons appear in two places — the memory's own page and the
   * /pm/memories list — and the right destination differs. Working through a
   * review queue means staying in the queue; acting on one memory you opened
   * means staying on it. The referrer is what tells the two apart, and it
   * falls back to the entity when there is not one.
   */
  protected function back(EntityInterface $memory): RedirectResponse {
    $referer = $this->requestStack->getCurrentRequest()?->headers->get('referer');
    $host = $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost();
    // Only follow a referrer pointing back at this site: an off-site one is
    // either noise or an open-redirect attempt.
    if ($referer && $host && str_starts_with($referer, $host)) {
      return new RedirectResponse($referer);
    }
    return new RedirectResponse($memory->toUrl('canonical')->toString());
  }

}
