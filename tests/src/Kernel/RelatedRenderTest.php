<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\petrichor\Hook\PetrichorRelatedHooks;
use Drupal\petrichor_projects\Entity\Project;
use Drupal\petrichor_tasks\Entity\Task;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Related section as it actually reaches the page.
 *
 * The other Related tests all stop at the registry and assert on arrays. This
 * one runs the entity view builder, so it covers the two joints those tests
 * cannot see: the extra field that puts the section on the page at all, and
 * the SDC that turns groups into markup. Both are the kind of wiring that
 * fails silently — a component whose props do not validate renders nothing,
 * and an extra field nobody declared is simply absent — so a passing service
 * layer proves less here than it looks like it does.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class RelatedRenderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'datetime',
    'taxonomy',
    'petrichor',
    'petrichor_projects',
    'petrichor_tasks',
    'petrichor_tags',
  ];

  /**
   * A project to hang everything off.
   *
   * @var \Drupal\petrichor_projects\Entity\Project
   */
  protected $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installConfig(['system', 'filter', 'petrichor', 'petrichor_tags']);

    // Rendering needs a theme, and the view builder needs real routes to link
    // each related row to.
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();

    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->project = Project::create(['label' => 'Acme redesign']);
    $this->project->save();
  }

  /**
   * A tagged task renders the section, with the sibling's title in it.
   */
  public function testRelatedSectionRendersOnTaskPage(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $this->task('Audit every form', ['forms']);

    $html = $this->renderFull($task);

    $this->assertStringContainsString('petrichor-related', $html, 'The component rendered.');
    $this->assertStringContainsString('Audit every form', $html, 'The sibling is listed.');
    $this->assertStringContainsString('1 shared tag', $html, 'And it says why it is there.');
    $this->assertStringNotContainsString('Rebuild the intake form</a>', $html, 'The task does not link to itself.');
  }

  /**
   * An untagged task renders no section at all — not an empty one.
   */
  public function testNoSectionWithoutRelatedContent(): void {
    $task = $this->task('Something else entirely', []);

    $html = $this->renderFull($task);

    $this->assertStringNotContainsString('petrichor-related', $html);
  }

  /**
   * The extra field is declared, so Manage display can move or hide it.
   */
  public function testExtraFieldIsDeclaredForPetrichorEntities(): void {
    $extra = $this->container->get('entity_field.manager')
      ->getExtraFields('petrichor_task', 'petrichor_task');

    $this->assertArrayHasKey(PetrichorRelatedHooks::FIELD_NAME, $extra['display']);
  }

  /**
   * Hiding it in Manage display actually hides it.
   *
   * The point of shipping this as an extra field rather than as markup a
   * template appends: a site owner who does not want it can turn it off.
   */
  public function testHiddenInDisplayMeansNotRendered(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $this->task('Audit every form', ['forms']);

    $display = $this->container->get('entity_display.repository')
      ->getViewDisplay('petrichor_task', 'petrichor_task', 'default');
    $display->removeComponent(PetrichorRelatedHooks::FIELD_NAME)->save();

    $this->assertStringNotContainsString('petrichor-related', $this->renderFull($task));
  }

  /**
   * Full markup for an entity's canonical view mode.
   */
  protected function renderFull($entity): string {
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder($entity->getEntityTypeId())
      ->view($entity, 'full');
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * A saved task in the shared project.
   */
  protected function task(string $label, array $tags): Task {
    $task = Task::create([
      'label' => $label,
      'project' => $this->project->id(),
      'status' => 'todo',
      'tags' => $this->container->get('petrichor_tags.resolver')->resolveNames($tags),
    ]);
    $task->save();
    return $task;
  }

}
