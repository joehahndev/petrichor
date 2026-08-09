<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Without petrichor_tags, /pm/memories is absent, not broken.
 *
 * The memory overview view is optional config depending on both
 * petrichor_memory and petrichor_tags — it needs the tags column and
 * filter to exist at all. A bare petrichor_memory install therefore has
 * no page at that path, the same honest trade-off petrichor_milestones
 * already makes for the 'milestone' field on tasks: an attached
 * enhancement that simply is not there without its module, rather than a
 * degraded version of itself.
 *
 * What this pins is the failure mode, not the feature: a missing route
 * (404) is the correct, safe outcome of a config dependency that is not
 * met. The bug this guards against is the view's own field/filter
 * definitions referencing a nonexistent field table and producing a 500
 * instead — exactly what happened once during development, caught by
 * this same scenario before it shipped.
 *
 * @group petrichor
 */
class MemoryOverviewWithoutTagsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * No route exists at /pm/memories at all — not a crash, not a fallback.
   */
  public function testNoMemoriesRouteWithoutTags(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/pm/memories');

    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * The entity itself works fine — tags were never load-bearing for it.
   */
  public function testMemoryEntityStillWorksWithoutTags(): void {
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create(['label' => 'A fact', 'fact' => 'A fact', 'status' => 'active']);
    $memory->save();

    $this->assertFalse($memory->hasField('tags'));
  }

}
