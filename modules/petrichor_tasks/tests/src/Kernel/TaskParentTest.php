<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tasks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Subtask parenting rules.
 *
 * `parent` is an entity reference with no constraint behind it and is writable
 * over MCP, so nothing but the service layer stops a task being parented into
 * another project or under its own descendant. Both failures are silent: the
 * task simply stops appearing where it should.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class TaskParentTest extends KernelTestBase {

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
    'petrichor',
    'petrichor_projects',
    'petrichor_tasks',
  ];

  /**
   * The task service under test.
   *
   * @var \Drupal\petrichor_tasks\TaskService
   */
  protected $tasks;

  /**
   * Project ids, keyed 'a' and 'b'.
   *
   * @var array
   */
  protected array $projects = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installConfig(['system', 'filter']);

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);

    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_project');
    foreach (['a', 'b'] as $key) {
      $project = $storage->create(['label' => 'Project ' . $key]);
      $project->save();
      $this->projects[$key] = (int) $project->id();
    }
    $this->tasks = $this->container->get('petrichor_tasks.service');
  }

  /**
   * Creates a task in one of the two projects.
   */
  protected function makeTask(string $title, string $project = 'a', ?int $parent_id = NULL): int {
    $values = ['title' => $title, 'project_id' => $this->projects[$project]];
    if ($parent_id !== NULL) {
      $values['parent_id'] = $parent_id;
    }
    return (int) $this->tasks->createTask($values)['id'];
  }

  /**
   * A parent in the same project is accepted.
   */
  public function testSameProjectParentIsAllowed(): void {
    $parent = $this->makeTask('Parent');
    $child = $this->makeTask('Child', 'a', $parent);
    $this->assertSame($parent, $this->tasks->getTask($child)['parent_id']);
  }

  /**
   * A parent in another project is refused at create time.
   */
  public function testParentInAnotherProjectIsRefusedOnCreate(): void {
    $foreign = $this->makeTask('Foreign parent', 'b');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/different project/');
    $this->makeTask('Child', 'a', $foreign);
  }

  /**
   * A parent in another project is refused on update too.
   */
  public function testParentInAnotherProjectIsRefusedOnUpdate(): void {
    $task = $this->makeTask('Local');
    $foreign = $this->makeTask('Foreign parent', 'b');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/different project/');
    $this->tasks->updateTask($task, ['parent_id' => $foreign]);
  }

  /**
   * A task cannot be its own parent.
   */
  public function testTaskCannotParentItself(): void {
    $task = $this->makeTask('Solo');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/its own parent/');
    $this->tasks->updateTask($task, ['parent_id' => $task]);
  }

  /**
   * Re-parenting under a descendant is refused.
   *
   * The damage this prevents is total and silent: grandparent -> parent ->
   * child, then pointing the grandparent at the child leaves all three in a
   * loop reachable from no root, so they vanish from every parent-filtered
   * query at once.
   */
  public function testReparentingUnderDescendantIsRefused(): void {
    $grandparent = $this->makeTask('Grandparent');
    $parent = $this->makeTask('Parent', 'a', $grandparent);
    $child = $this->makeTask('Child', 'a', $parent);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/would create a cycle/');
    $this->tasks->updateTask($grandparent, ['parent_id' => $child]);
  }

  /**
   * A missing parent is refused rather than written as a dangling reference.
   */
  public function testMissingParentIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/not found/');
    $this->makeTask('Child', 'a', 999999);
  }

  /**
   * A rejected parent leaves the rest of a partial update unapplied.
   */
  public function testRejectedParentDoesNotPartiallyApply(): void {
    $task = $this->makeTask('Original title');
    $foreign = $this->makeTask('Foreign parent', 'b');
    try {
      $this->tasks->updateTask($task, ['title' => 'Renamed', 'parent_id' => $foreign]);
      $this->fail('Expected the foreign parent to be refused.');
    }
    catch (\InvalidArgumentException) {
      // Expected — the assertion is what did NOT happen.
    }
    $this->assertSame('Original title', $this->tasks->getTask($task)['title']);
  }

  /**
   * Task reads work with petrichor_memory absent, and say nothing about it.
   *
   * Off-topic for a parenting test, but this fixture is the one that matters:
   * it is the only kernel test that builds petrichor_tasks without the memory
   * module, which is exactly the à-la-carte install the '@?' injection in
   * petrichor_tasks.services.yml exists to protect. The positive cases live in
   * TaskMemorySurfacingTest.
   */
  public function testRelatedMemoriesAreAbsentWithoutTheMemoryModule(): void {
    $result = $this->tasks->getTask($this->makeTask('Any task'));
    $this->assertArrayNotHasKey('related_memories', $result);
    $this->assertSame('Any task', $result['title']);
  }

}
