<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The memory overview view at /pm/memories, replacing the bare entity list.
 *
 * The bare list showed id/title/updated only — no way to tell a proposed
 * memory from an active one without opening it, which was the actual
 * complaint this exists to fix. Covers what a hand-authored Views config
 * can't be caught by phpcs/phpstan: whether the shipped filter and column
 * definitions actually match the schema Views reports for this entity.
 *
 * petrichor_tags is in $modules deliberately, not incidentally: the view
 * is optional config depending on both petrichor_memory and
 * petrichor_tags, so it does not exist at all without the latter — see
 * MemoryOverviewWithoutTagsTest for that other case.
 *
 * @group petrichor
 */
class MemoryOverviewTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_memory',
    'petrichor_tags',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Creates a saved memory.
   */
  protected function memory(string $title, string $status, string $type = 'learning', ?int $project_id = NULL): void {
    $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create([
        'label' => $title,
        'fact' => $title,
        'status' => $status,
        'type' => $type,
        'project' => $project_id,
      ])
      ->save();
  }

  /**
   * A proposed memory is visible on the unfiltered page.
   *
   * The actual bug report this view exists to fix: the old bare list
   * carried no status column, and `list_memories`/`recall` both default to
   * active-only, so a proposed memory was invisible everywhere unless
   * someone opened it directly.
   */
  public function testProposedMemoriesAreVisibleByDefault(): void {
    $this->memory('A proposed fact', 'proposed');
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/pm/memories');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('A proposed fact');
    $this->assertSession()->pageTextContains('Proposed');
  }

  /**
   * The status filter actually narrows the list.
   *
   * Exercised through the exposed filter's real query parameter (the
   * grouped filter's numeric key, not the raw field value) — the same path
   * a human clicking the dropdown goes through.
   */
  public function testStatusFilterNarrowsResults(): void {
    $this->memory('Proposed one', 'proposed');
    $this->memory('Active one', 'active');
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));

    $this->drupalGet('/pm/memories', ['query' => ['status' => 1]]);
    $this->assertSession()->pageTextContains('Proposed one');
    $this->assertSession()->pageTextNotContains('Active one');
  }

  /**
   * A memory's project scope shows as the project's title, not its id.
   */
  public function testProjectShowsAsTitle(): void {
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Acme redesign', 'status' => 'open']);
    $project->save();
    $this->memory('Scoped fact', 'active', 'learning', (int) $project->id());

    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/pm/memories');

    $this->assertSession()->pageTextContains('Acme redesign');
    $this->assertSession()->linkExists('Acme redesign');
  }

  /**
   * Reading the memory overview requires the ordinary view permission.
   */
  public function testAnonymousIsRefused(): void {
    $this->drupalGet('/pm/memories');
    $this->assertSession()->statusCodeEquals(403);
  }

}
