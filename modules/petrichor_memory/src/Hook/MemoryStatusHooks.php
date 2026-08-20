<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory\Hook;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\petrichor_memory\MemoryService;

/**
 * One-click status buttons on a memory, wherever a memory is shown.
 *
 * The default recording policy stores an agent's memory as `proposed` and
 * keeps it invisible to recall until a human confirms it. That review step is
 * the whole safety argument for letting an agent write memories at all, and
 * until now performing it meant opening the edit form, changing a select, and
 * saving — three screens per memory, on the one task the design expects to be
 * done in bulk. A review queue nobody works through is the same as no review.
 *
 * The same links are produced once and shown in both places: as entity
 * operations in the /pm/memories list, and as an action row on the memory's
 * own page.
 */
class MemoryStatusHooks {

  use StringTranslationTrait;

  /**
   * The extra field's machine name.
   */
  public const FIELD_NAME = 'petrichor_memory_status_actions';

  public function __construct(
    protected readonly MemoryService $memory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * Implements hook_entity_operation().
   *
   * Puts the actions in the /pm/memories list, which already renders an
   * entity_operations field.
   *
   * The cacheability parameter arrived in Drupal 11.3 and is optional here
   * because this module supports ^11.1, where core still calls the hook with
   * one argument — a required second parameter would be an ArgumentCountError
   * on every entity list on those versions.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    if ($entity->getEntityTypeId() !== 'petrichor_memory') {
      return [];
    }
    // Which operations appear depends on the memory's status and on whether
    // this user may archive.
    $cacheability?->addCacheableDependency($entity);
    $cacheability?->addCacheContexts(['user.permissions']);

    $operations = [];
    $weight = -10;
    foreach ($this->targets($entity) as $status => $label) {
      $operations['petrichor_memory_status_' . $status] = [
        'title' => $label,
        'url' => $this->url($entity, $status),
        'weight' => $weight++,
      ];
    }
    return $operations;
  }

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];
    $extra['petrichor_memory']['petrichor_memory']['display'][self::FIELD_NAME] = [
      'label' => $this->t('Status actions'),
      'description' => $this->t('One-click buttons for confirming, archiving or reopening this memory.'),
      // Above the fields: on a proposed memory this is the reason you opened
      // the page.
      'weight' => -5,
      'visible' => TRUE,
    ];
    return $extra;
  }

  /**
   * Implements hook_entity_view().
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($view_mode !== 'full' || $entity->getEntityTypeId() !== 'petrichor_memory') {
      return;
    }
    if (!$entity instanceof ContentEntityInterface || !$display->getComponent(self::FIELD_NAME)) {
      return;
    }

    $links = [];
    foreach ($this->targets($entity) as $status => $label) {
      $links[] = [
        'title' => $label,
        'url' => $this->url($entity, $status),
      ];
    }
    if ($links === []) {
      return;
    }

    $build[self::FIELD_NAME] = [
      // '#theme', not '#type'. There is no 'links' render element — a
      // '#type' => 'links' array resolves to no theme hook at all and renders
      // to an empty string, silently and with no error anywhere.
      '#theme' => 'links',
      '#links' => $links,
      // Core's own action-links styling, so this needs no CSS of its own and
      // inherits whatever the host theme does with admin actions.
      '#attributes' => ['class' => ['action-links', 'petrichor-memory-actions']],
      '#cache' => [
        // The buttons offered depend on the memory's current status and on
        // whether this user may archive.
        'tags' => $entity->getCacheTags(),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Statuses this memory can usefully move to, as label => verb.
   *
   * Its current status is left out — a button that does nothing is a button
   * people click once and then distrust. Archiving is offered only to the
   * maintainer tier, matching the permission MemoryService::forget() enforces,
   * so the link is absent rather than present-and-rejected.
   */
  protected function targets(EntityInterface $entity): array {
    if (!$entity->access('update')) {
      return [];
    }
    $current = $entity->get('status')->value;
    $labels = $this->memory->statusOptions();
    $targets = [];
    foreach (array_keys($labels) as $status) {
      if ($status === $current) {
        continue;
      }
      if ($status === 'archived' && !$this->currentUser->hasPermission('archive petrichor memories')) {
        continue;
      }
      // Cast: a list field's allowed_values hold TranslatableMarkup, not
      // strings, because the field definition builds them with t().
      $targets[$status] = $this->actionLabel($status, (string) ($labels[$status] ?? $status));
    }
    return $targets;
  }

  /**
   * The verb for moving *to* a status, rather than the status's own name.
   *
   * "Confirm" says what the click does; "Active" only says where it lands,
   * and a row of nouns reads as a status display rather than as controls.
   *
   * Written as a match over literal t() calls rather than a lookup table
   * because the translation extractor only sees literal strings — a table of
   * bare strings passed through t() at the call site produces buttons that
   * are untranslatable on every site that runs this in another language.
   */
  protected function actionLabel(string $status, string $fallback): object {
    return match ($status) {
      'active' => $this->t('Confirm'),
      'archived' => $this->t('Archive'),
      'proposed' => $this->t('Send back for review'),
      // A status some site added to the field. Its own label is the best
      // available verb, and it is already translated as a field setting.
      default => $this->t('Move to @status', ['@status' => $fallback]),
    };
  }

  /**
   * The tokenised URL for one transition.
   *
   * The route demands a CSRF token because a GET link changes state. Core
   * generates it from the internal path, which is what the access check
   * re-derives on the other side.
   */
  protected function url(EntityInterface $entity, string $status): Url {
    $url = Url::fromRoute('petrichor_memory.set_status', [
      'petrichor_memory' => $entity->id(),
      'status' => $status,
    ]);
    return $url->setOption('query', [
      'token' => $this->csrfToken->get($url->getInternalPath()),
    ]);
  }

}
