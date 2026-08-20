<?php

declare(strict_types=1);

namespace Drupal\petrichor\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\petrichor\Entity\PetrichorEntityBase;
use Drupal\petrichor\Related\RelatedRegistry;

/**
 * Puts the Related section on Petrichor entity pages.
 *
 * An extra field rather than markup appended in a template, because that makes
 * it an ordinary thing a site owner can move or switch off from Manage display
 * at /admin/structure/petrichor/{entity} — the Field UI anchors this suite
 * already ships. A module that decides unilaterally where its output goes on
 * someone else's site is the behaviour the optional theme is careful to avoid,
 * and this follows the same rule.
 *
 * Note the one surface this does not reach: /wiki/{id} is served by
 * WikiController, not by the entity view builder, so hook_entity_view never
 * fires there. petrichor_wiki calls the registry itself. That asymmetry is
 * documented at the top of petrichor_wiki.routing.yml, where the override that
 * causes it lives.
 */
class PetrichorRelatedHooks {

  use StringTranslationTrait;

  /**
   * The extra field's machine name, and the render-array key it produces.
   */
  public const FIELD_NAME = 'petrichor_related';

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RelatedRegistry $registry,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   *
   * Declared for every entity type in the suite rather than a hard-coded list.
   * The base module owns PetrichorEntityBase, so "which entity types are ours"
   * is answerable here without depending on the submodules that define them —
   * the same reason the shared access handler and list builder can be generic.
   *
   * Nothing is declared when no provider is installed, so a site running, say,
   * projects and tasks alone never sees a Related row in Manage display that
   * could not possibly render anything.
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];
    if (!$this->registry->hasProviders()) {
      return $extra;
    }

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      $class = $definition->getClass();
      if (!is_subclass_of($class, PetrichorEntityBase::class)) {
        continue;
      }
      // None of these entity types has bundles, so the bundle key is the
      // entity type id.
      $extra[$entity_type_id][$entity_type_id]['display'][self::FIELD_NAME] = [
        'label' => $this->t('Related'),
        'description' => $this->t('Other records connected to this one, such as those sharing a tag.'),
        // Below the entity's own fields. This is context for what is on the
        // page, so it reads after it.
        'weight' => 20,
        'visible' => TRUE,
      ];
    }
    return $extra;
  }

  /**
   * Implements hook_entity_view().
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    // Full view only. A teaser or a reference label showing three groups of
    // related records would be longer than the thing it is standing in for.
    if ($view_mode !== 'full' || !$entity instanceof ContentEntityInterface) {
      return;
    }
    if (!$entity instanceof PetrichorEntityBase || !$display->getComponent(self::FIELD_NAME)) {
      return;
    }

    $set = $this->registry->getRelated($entity);

    $element = $set->isEmpty() ? [] : [
      '#type' => 'component',
      '#component' => 'petrichor:related',
      '#props' => ['groups' => $set->toArray()],
    ];

    // Applied even when the set is empty, and that is the whole point: an
    // entity with nothing related today gets a page whose cache entry still
    // depends on the entity types that could relate to it tomorrow. Skip this
    // and tagging some other task leaves this page cached as empty forever,
    // and the bug looks like it belongs to the tagging code.
    CacheableMetadata::createFromRenderArray($element)
      ->merge($set->getCacheability())
      ->applyTo($element);

    $build[self::FIELD_NAME] = $element;
  }

}
