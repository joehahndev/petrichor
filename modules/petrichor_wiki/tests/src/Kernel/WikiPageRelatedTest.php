<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\TestTools\Extension\SchemaInspector;
use Drupal\petrichor_projects\Entity\Project;
use Drupal\petrichor_tasks\Entity\Task;
use Drupal\petrichor_wiki\Controller\WikiController;
use Drupal\petrichor_wiki\Entity\WikiPage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Related section on /wiki/{id}, which no hook can reach.
 *
 * This route overrides the wiki page's canonical entity link, and that
 * override is documented in petrichor_wiki.routing.yml as costing the module
 * hook_ENTITY_TYPE_view(). The consequence is that the extra field placing a
 * Related section on every other entity page in the suite does nothing here,
 * and the controller has to ask for one itself.
 *
 * Which makes this the one Related surface with no shared safety net: if the
 * controller stops asking, nothing fails anywhere else, and the section simply
 * disappears from the wiki while continuing to work on tasks and
 * memories. That is exactly the sort of quiet regression worth a test.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class WikiPageRelatedTest extends KernelTestBase {

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
    'petrichor_markdown',
    'petrichor_wiki',
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
    $this->installEntitySchema('petrichor_wiki_page');

    $spec = SchemaInspector::getTablesSpecification(
      $this->container->get('module_handler'),
      'petrichor_wiki'
    );
    if ($spec) {
      $this->installSchema('petrichor_wiki', array_keys($spec));
    }

    $this->installConfig([
      'system',
      'filter',
      'petrichor',
      'petrichor_markdown',
      'petrichor_tags',
    ]);
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
   * A tagged page gets the Related component as a slot on wiki_page.
   */
  public function testTaggedPageGetsRelatedSlot(): void {
    $page = $this->page('Form conventions', ['forms']);
    $task = $this->task('Rebuild the intake form', ['forms']);

    $slot = $this->buildPage($page)['page']['#slots']['related'] ?? NULL;

    $this->assertNotNull($slot, 'The controller asked the registry and filled the slot.');
    $this->assertSame('petrichor:related', $slot['#component']);

    $groups = $slot['#props']['groups'];
    $task_group = array_values(array_filter(
      $groups,
      static fn(array $group): bool => $group['id'] === 'tag_siblings:petrichor_task',
    ));
    $this->assertCount(1, $task_group);
    $this->assertSame(
      [(int) $task->id()],
      array_column($task_group[0]['items'], 'id'),
    );
  }

  /**
   * An untagged page gets no slot at all.
   *
   * Not an empty one: ComponentElement runs every slot through
   * Element::isRenderArray() and throws on an empty array, which is why the
   * controller omits rather than blanks it.
   */
  public function testUntaggedPageGetsNoSlot(): void {
    $page = $this->page('Form conventions', []);

    $build = $this->buildPage($page);

    $this->assertArrayNotHasKey('related', $build['page']['#slots']);
  }

  /**
   * The page's cache entry depends on what the Related section read.
   *
   * The controller writes its own #cache array literally, so the merge is
   * hand-rolled and worth pinning: miss it and a tagged page caches without
   * knowing it depends on the task it lists.
   */
  public function testRelatedCacheabilityReachesThePageBuild(): void {
    $page = $this->page('Form conventions', ['forms']);
    $task = $this->task('Rebuild the intake form', ['forms']);

    $tags = $this->buildPage($page)['#cache']['tags'];

    $this->assertContains('petrichor_task:' . $task->id(), $tags);
    $this->assertContains('petrichor_task_list', $tags);
    // The route's own tags survive the merge rather than being replaced.
    $this->assertContains('petrichor_wiki_page_list', $tags);
  }

  /**
   * The controller's render array for one page.
   */
  protected function buildPage(WikiPage $page): array {
    return WikiController::create($this->container)->page($page);
  }

  /**
   * A saved wiki page in the shared project.
   */
  protected function page(string $label, array $tags): WikiPage {
    $page = WikiPage::create([
      'label' => $label,
      'body' => $label,
      'project' => $this->project->id(),
      'tags' => $this->terms($tags),
    ]);
    $page->save();
    return $page;
  }

  /**
   * A saved task in the shared project.
   */
  protected function task(string $label, array $tags): Task {
    $task = Task::create([
      'label' => $label,
      'project' => $this->project->id(),
      'status' => 'todo',
      'tags' => $this->terms($tags),
    ]);
    $task->save();
    return $task;
  }

  /**
   * Tag names as term ids.
   */
  protected function terms(array $names): array {
    return $this->container->get('petrichor_tags.resolver')->resolveNames($names);
  }

}
