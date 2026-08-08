<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tasks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Task reads carry the decisions that apply to them.
 *
 * Capture and review were the easy halves. A memory that only surfaces when
 * somebody remembers to search for it is storage, not memory — so the read
 * that happens before the work carries the constraints along uninvited.
 *
 * The counterpart, that a task read does not disturb recall recency, lives in
 * MemoryRetrievalTest; the absence path when petrichor_memory is not installed
 * lives in TaskParentTest, which has no memory module in its fixture.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class TaskMemorySurfacingTest extends KernelTestBase {

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
    'petrichor_memory',
  ];

  /**
   * The task service under test.
   *
   * @var \Drupal\petrichor_tasks\TaskService
   */
  protected $tasks;

  /**
   * The memory service, used to build fixtures.
   *
   * @var \Drupal\petrichor_memory\MemoryService
   */
  protected $memory;

  /**
   * The project every fixture task belongs to.
   *
   * @var int
   */
  protected int $projectId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installEntitySchema('petrichor_memory');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor']);

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);

    $this->tasks = $this->container->get('petrichor_tasks.service');
    $this->memory = $this->container->get('petrichor_memory.service');
    $this->config('petrichor.settings')->set('memory_policy', 'automatic')->save();

    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Petrichor', 'status' => 'open']);
    $project->save();
    $this->projectId = (int) $project->id();
  }

  /**
   * Creates a task in the fixture project and returns its id.
   */
  protected function task(string $title): int {
    return (int) $this->tasks->createTask([
      'title' => $title,
      'project_id' => $this->projectId,
    ])['id'];
  }

  /**
   * Stores an active memory and returns its id.
   */
  protected function remember(string $title, string $type = 'decision', ?int $project_id = NULL): int {
    return (int) $this->memory->remember([
      'title' => $title,
      'fact' => 'The body, which get_task must not inline.',
      'type' => $type,
      'project_id' => $project_id,
    ])['id'];
  }

  /**
   * A decision matching the task title arrives with the task.
   */
  public function testMatchingDecisionSurfacesOnTheTask(): void {
    $memory = $this->remember('Comment storage decision', 'decision', $this->projectId);
    $id = $this->task('Comment storage rewrite');

    $result = $this->tasks->getTask($id);
    $this->assertSame([['id' => $memory, 'title' => 'Comment storage decision']], $result['related_memories']);
    $this->assertArrayHasKey('related_memories_note', $result);
  }

  /**
   * No match, no key — an empty array on every task read is pure weight.
   */
  public function testTheKeyIsAbsentWhenNothingMatches(): void {
    $this->remember('Something else entirely', 'decision', $this->projectId);
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));

    $this->assertArrayNotHasKey('related_memories', $result);
    $this->assertArrayNotHasKey('related_memories_note', $result);
  }

  /**
   * Reference material stays out of the way of the work.
   */
  public function testLearningsAndReferencesDoNotSurface(): void {
    $this->remember('Comment storage learning', 'learning', $this->projectId);
    $this->remember('Comment storage reference', 'reference', $this->projectId);
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));

    $this->assertArrayNotHasKey('related_memories', $result);
  }

  /**
   * Proposed memories stay invisible here too.
   */
  public function testProposedMemoriesDoNotSurface(): void {
    $this->config('petrichor.settings')->set('memory_policy', 'ask')->save();
    $this->remember('Comment storage decision', 'decision', $this->projectId);
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));

    $this->assertArrayNotHasKey('related_memories', $result);
  }

  /**
   * A global decision applies here, because it applies everywhere.
   */
  public function testGlobalMemoriesSurface(): void {
    $memory = $this->remember('Comment storage decision', 'decision');
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));

    $this->assertSame([$memory], array_column($result['related_memories'], 'id'));
  }

  /**
   * Another project's decision does not follow the task home.
   */
  public function testAnotherProjectsMemoryDoesNotSurface(): void {
    $other = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Elsewhere', 'status' => 'open']);
    $other->save();
    $this->remember('Comment storage decision', 'decision', (int) $other->id());

    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));
    $this->assertArrayNotHasKey('related_memories', $result);
  }

  /**
   * At most three, so a common word in a title cannot flood the response.
   */
  public function testAtMostThreeMemoriesRideAlong(): void {
    foreach (range(1, 5) as $n) {
      $this->remember("Comment storage decision $n", 'decision', $this->projectId);
    }
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));
    $this->assertCount(3, $result['related_memories']);
  }

  /**
   * Ids and titles only: the body costs tokens on a call agents make often.
   */
  public function testNoFactBodiesAreInlined(): void {
    $this->remember('Comment storage decision', 'decision', $this->projectId);
    $result = $this->tasks->getTask($this->task('Comment storage rewrite'));

    $this->assertSame(['id', 'title'], array_keys($result['related_memories'][0]));
  }

  /**
   * Reading a task does not count as recalling the memories it surfaces.
   *
   * The task-level counterpart of MemoryRetrievalTest: verified here through
   * the real wiring rather than the service in isolation, because it is
   * getTask() that turns one passive read into thousands.
   */
  public function testReadingTasksDoesNotTouchTheRecallTable(): void {
    $this->remember('Comment storage decision', 'decision', $this->projectId);
    $id = $this->task('Comment storage rewrite');

    $this->tasks->getTask($id);
    $this->tasks->getTask($id);

    $rows = $this->container->get('database')
      ->select('petrichor_memory_recall', 'r')
      ->fields('r', ['memory_id', 'last_recalled'])
      ->execute()
      ->fetchAllKeyed();
    $this->assertSame([], $rows);
  }

}
