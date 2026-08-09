<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tasks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Finding a task by name.
 *
 * The same gap list_projects had: with no text filter, a caller that knows
 * what a task is called can only page the whole project and read titles.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class TaskQueryTest extends KernelTestBase {

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
   * The task service under test.
   *
   * @var \Drupal\petrichor_tasks\TaskService
   */
  protected $tasks;

  /**
   * The project fixtures hang off.
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
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installConfig(['system', 'filter', 'petrichor_tags']);

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);
    $this->tasks = $this->container->get('petrichor_tasks.service');

    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Project', 'status' => 'open']);
    $project->save();
    $this->projectId = (int) $project->id();
  }

  /**
   * Creates a task and returns its id.
   */
  protected function task(string $title, array $values = []): int {
    return (int) $this->tasks->createTask([
      'title' => $title,
      'project_id' => $this->projectId,
    ] + $values)['id'];
  }

  /**
   * A substring of the title finds the task.
   */
  public function testQueryMatchesOnTitle(): void {
    $id = $this->task('Fix the contact form');
    $this->task('Something else');

    $result = $this->tasks->listTasks(['query' => 'contact']);
    $this->assertSame([$id], array_column($result['items'], 'id'));
    $this->assertSame(1, $result['total']);
  }

  /**
   * Descriptions are searched: this is a search somebody asked for.
   */
  public function testQueryMatchesOnDescription(): void {
    $id = $this->task('Opaque title', ['description' => 'Actually about the contact form.']);
    $this->task('Something else');

    $this->assertSame([$id], array_column($this->tasks->listTasks(['query' => 'contact'])['items'], 'id'));
  }

  /**
   * Tags are searched too.
   */
  public function testQueryMatchesOnTags(): void {
    $id = $this->task('Opaque title', ['tags' => ['accessibility', 'forms']]);
    $this->task('Something else');

    $this->assertSame([$id], array_column($this->tasks->listTasks(['query' => 'access'])['items'], 'id'));
  }

  /**
   * No match returns nothing, not everything.
   */
  public function testNonMatchingQueryReturnsNothing(): void {
    $this->task('Fix the contact form');
    $result = $this->tasks->listTasks(['query' => 'nothing like this']);

    $this->assertSame([], $result['items']);
    $this->assertSame(0, $result['total']);
  }

  /**
   * A literal '%' is escaped rather than matching the whole table.
   */
  public function testWildcardCharactersAreEscaped(): void {
    $this->task('Fix the contact form');
    $this->task('Something else');

    $this->assertSame([], $this->tasks->listTasks(['query' => '%'])['items']);
  }

  /**
   * The query composes with the other filters rather than replacing them.
   */
  public function testQueryCombinesWithOtherFilters(): void {
    $todo = $this->task('Contact form validation', ['status' => 'todo']);
    $this->task('Contact form styling', ['status' => 'done']);

    $result = $this->tasks->listTasks(['query' => 'contact', 'status' => 'todo']);
    $this->assertSame([$todo], array_column($result['items'], 'id'));
  }

  /**
   * An empty query still lists everything.
   */
  public function testEmptyQueryListsEverything(): void {
    $this->task('One');
    $this->task('Two');

    $this->assertSame(2, $this->tasks->listTasks()['total']);
    $this->assertSame(2, $this->tasks->listTasks(['query' => ''])['total']);
  }

}
