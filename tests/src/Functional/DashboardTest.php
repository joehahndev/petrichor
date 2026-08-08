<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The dashboard, and especially the state it is in before anyone has used it.
 *
 * /pm is the first page a site builder sees after installing the suite. The
 * version this replaced listed entity types and counts, which on a fresh site
 * meant a bullet list of zeroes — accurate, and no help at all. So the empty
 * state is tested first and hardest here.
 *
 * @group petrichor
 */
class DashboardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_tasks',
    'petrichor_board',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Creates a project.
   */
  protected function createProject(string $title, array $values = []): int {
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => $title, 'status' => 'open'] + $values);
    $project->save();
    return (int) $project->id();
  }

  /**
   * Creates a task in a project.
   */
  protected function createTask(string $title, int $project_id, string $status = 'todo'): void {
    $this->container->get('entity_type.manager')
      ->getStorage('petrichor_task')
      ->create([
        'label' => $title,
        'project' => $project_id,
        'status' => $status,
      ])
      ->save();
  }

  /**
   * With nothing created yet, the page says what to do first.
   */
  public function testEmptyStateTellsYouWhatToDo(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
    $this->drupalGet('/pm');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Start with a project');
    $this->assertSession()->linkExists('Create your first project');
    // The old page's failure mode: a list of entity types and zeroes.
    $this->assertSession()->pageTextNotContains('Workspaces');
  }

  /**
   * A read-only user is not offered a button that would refuse them.
   *
   * On a first run this button is the only thing on the page, so offering it
   * to somebody who cannot use it makes the whole page a dead end.
   */
  public function testReadOnlyUserIsNotOfferedTheButton(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/pm');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Create your first project');
    $this->assertSession()->pageTextContains('do not have permission');
  }

  /**
   * Once a project exists the empty state gives way to the real thing.
   */
  public function testProjectsAreListedWithTheirDetail(): void {
    $id = $this->createProject('Acme redesign', ['client' => 'Acme']);
    $this->createTask('Open one', $id);
    $this->createTask('Open two', $id);
    $this->createTask('Finished', $id, 'done');

    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
    $this->drupalGet('/pm');

    $this->assertSession()->pageTextNotContains('Start with a project');
    $this->assertSession()->linkExists('Acme redesign');
    $this->assertSession()->pageTextContains('Acme');
    $this->assertSession()->pageTextContains('Open');
    // Two open, one done — the done task must not be counted.
    $this->assertSession()->pageTextContains('2 open');
    // petrichor_board is installed, so each project offers its board.
    $this->assertSession()->linkExists('Board');
  }

  /**
   * The counts are not cached past the change that invalidates them.
   *
   * The page this replaced tagged itself 'petrichor_dashboard' and nothing in
   * the codebase ever invalidated that tag, so the numbers here were right
   * exactly once and then frozen until somebody cleared caches by hand. This
   * is the regression that pins the fix.
   */
  public function testCountsFollowTheData(): void {
    $id = $this->createProject('Acme redesign');
    $this->createTask('Open one', $id);

    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
    $this->drupalGet('/pm');
    $this->assertSession()->pageTextContains('1 open');

    $this->createTask('Open two', $id);
    $this->drupalGet('/pm');
    $this->assertSession()->pageTextContains('2 open');
  }

  /**
   * New projects show up without a manual cache clear either.
   */
  public function testNewProjectsAppear(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
    $this->drupalGet('/pm');
    $this->assertSession()->pageTextContains('Start with a project');

    $this->createProject('Acme redesign');
    $this->drupalGet('/pm');
    $this->assertSession()->linkExists('Acme redesign');
  }

  /**
   * The workspace links point only at what is installed.
   */
  public function testWorkspaceLinksReflectTheInstall(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/pm');

    // petrichor_board is installed.
    $this->assertSession()->linkExists('Today');
    // petrichor_wiki and petrichor_memory are not.
    $this->assertSession()->linkNotExists('Knowledge base');
    $this->assertSession()->linkNotExists('Memories');
  }

}
