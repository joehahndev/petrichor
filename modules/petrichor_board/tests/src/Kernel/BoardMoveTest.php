<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_board\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * BoardService::moveCard() — persistence, conflict, and ordering.
 *
 * The load-bearing test here is testPureReorderLeavesChangedAlone(): the whole
 * reason card order lives in a side table rather than a base field (D4) is that
 * a base field would make every drag an entity save, moving `changed` — which
 * list_tasks sorts by, get_project_context reports as activity, and
 * if_unchanged_since compares against. If that assertion ever fails, the side
 * table has stopped earning its keep and the decision needs revisiting.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class BoardMoveTest extends KernelTestBase {

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
    'petrichor_board',
  ];

  /**
   * The project every test task belongs to.
   */
  protected int $projectId;

  /**
   * Request time, frozen for the whole test run.
   */
  protected int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installSchema('petrichor_board', ['petrichor_board_order']);
    // Burn uid 1 so test accounts don't get the superuser bypass.
    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->now = (int) $this->container->get('datetime.time')->getRequestTime();
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Board project']);
    $project->save();
    $this->projectId = (int) $project->id();
  }

  /**
   * A cross-column move writes the new status through to storage.
   */
  public function testStatusChangePersists(): void {
    $id = $this->makeTask('Wire the board', 'todo', $this->now - 300);

    $result = $this->board()->moveCard($id, 'in_progress');

    $this->assertSame('in_progress', $result['status']);
    $this->assertSame('in_progress', $result['task']['status']);
    $this->assertContains($id, $result['order']);
    $this->assertSame('in_progress', $this->tasks()->getTask($id)['status']);
    $this->assertSame('in_progress', $this->reload($id)->get('status')->value);
    $this->assertSame([], $this->board()->columnOrder($this->projectId, 'todo'));
  }

  /**
   * A same-column reorder must not touch the task's changed timestamp.
   *
   * The seeded timestamps are in the past, so a stray entity save anywhere in
   * moveCard() would snap `changed` forward to the request time and this
   * assertion would catch it. This is the D4 contract, stated as a test.
   */
  public function testPureReorderLeavesChangedAlone(): void {
    $alpha = $this->makeTask('Alpha', 'todo', $this->now - 300);
    $bravo = $this->makeTask('Bravo', 'todo', $this->now - 200);
    $charlie = $this->makeTask('Charlie', 'todo', $this->now - 100);
    $before = $this->tasks()->getTask($alpha)['changed'];
    $this->assertSame(date('c', $this->now - 300), $before);

    $result = $this->board()->moveCard($alpha, 'todo', NULL, $charlie);

    // The reorder really happened — otherwise the timestamp assertion below
    // would pass on a no-op.
    $this->assertSame($alpha, $result['order'][0]);

    $this->assertSame($before, $this->tasks()->getTask($alpha)['changed'], 'A pure reorder must not bump changed (D4).');
    $this->assertSame(date('c', $this->now - 200), $this->tasks()->getTask($bravo)['changed']);
    $this->assertSame(date('c', $this->now - 100), $this->tasks()->getTask($charlie)['changed']);
    $this->assertSame((string) ($this->now - 300), (string) $this->reload($alpha)->get('changed')->value);
  }

  /**
   * A tripped if_unchanged_since guard throws and writes nothing.
   */
  public function testConflictLeavesStateUnchanged(): void {
    $id = $this->makeTask('Alpha', 'todo', $this->now - 300);
    $stale = date('c', $this->now - 7200);
    $before = $this->tasks()->getTask($id);

    try {
      $this->board()->moveCard($id, 'done', NULL, NULL, $stale);
      $this->fail('A stale if_unchanged_since should throw.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('changed since', $e->getMessage());
    }
    $this->assertSame($before, $this->tasks()->getTask($id));
    $this->assertSame('todo', $this->reload($id)->get('status')->value);
    $this->assertSame([], $this->orderRow($id));

    // A pure reorder must honour the guard too — it is the case where nothing
    // reaches updateTask(), so BoardService has to do the comparison itself.
    try {
      $this->board()->moveCard($id, 'todo', NULL, NULL, $stale);
      $this->fail('A stale if_unchanged_since should throw on a pure reorder.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('changed since', $e->getMessage());
    }
    $this->assertSame($before, $this->tasks()->getTask($id));
    $this->assertSame([], $this->orderRow($id));
  }

  /**
   * An unknown status is rejected before anything is written.
   *
   * UpdateTask() sets fields with no whitelist and never calls validate(), and
   * SQLite has no CHECK constraint behind a list_string — so this guard is the
   * only thing between a hand-crafted request and a permanently unrenderable
   * column.
   */
  public function testInvalidStatusWritesNothing(): void {
    $id = $this->makeTask('Alpha', 'todo', $this->now - 300);
    $before = $this->tasks()->getTask($id);

    try {
      $this->board()->moveCard($id, 'bogus', NULL, NULL);
      $this->fail('An unknown status should throw.');
    }
    catch (\DomainException $e) {
      // The type is the contract: the controller maps DomainException to 422
      // invalid_status and InvalidArgumentException to 404/409.
      $this->assertNotSame('', $e->getMessage());
    }
    $this->assertSame($before, $this->tasks()->getTask($id));
    $this->assertSame('todo', $this->reload($id)->get('status')->value);
    $this->assertSame([], $this->orderRow($id));
  }

  /**
   * Neighbour ids place a card exactly between them.
   *
   * The three tasks start unplaced, so their order is the changed DESC
   * tie-break: Charlie, Bravo, Alpha. Moving Alpha between Charlie and Bravo
   * forces the neighbours to be given weights first, then a midpoint.
   */
  public function testOrderingPlacesCardBetweenNeighbours(): void {
    $alpha = $this->makeTask('Alpha', 'todo', $this->now - 300);
    $bravo = $this->makeTask('Bravo', 'todo', $this->now - 200);
    $charlie = $this->makeTask('Charlie', 'todo', $this->now - 100);
    $this->assertSame([$charlie, $bravo, $alpha], $this->board()->columnOrder($this->projectId, 'todo'));

    $result = $this->board()->moveCard($alpha, 'todo', $charlie, $bravo);

    $this->assertSame([$charlie, $alpha, $bravo], $result['order']);
    $this->assertSame([$charlie, $alpha, $bravo], $this->board()->columnOrder($this->projectId, 'todo'));
  }

  /**
   * The board service under test.
   */
  protected function board() {
    return $this->container->get('petrichor_board.service');
  }

  /**
   * The task service the board delegates every entity read and write to.
   */
  protected function tasks() {
    return $this->container->get('petrichor_tasks.service');
  }

  /**
   * Creates a task with an explicit, in-the-past changed timestamp.
   *
   * Every entity saved in one kernel test request shares REQUEST_TIME, which
   * would make the unplaced changed DESC tie-break arbitrary and would hide a
   * spurious entity save behind an identical timestamp. Setting `changed`
   * explicitly is honoured by ChangedItem::preSave() — it only forces the
   * request time when the submitted value matches the stored one.
   */
  protected function makeTask(string $title, string $status, int $changed): int {
    $task = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_task')
      ->create([
        'label' => $title,
        'project' => $this->projectId,
        'status' => $status,
      ]);
    $task->save();
    $task->set('changed', $changed);
    $task->save();
    return (int) $task->id();
  }

  /**
   * The side-table row for a task, as a list of weights.
   */
  protected function orderRow(int $task_id): array {
    return $this->container->get('database')
      ->select('petrichor_board_order', 'o')
      ->fields('o', ['weight'])
      ->condition('task_id', $task_id)
      ->execute()
      ->fetchCol();
  }

  /**
   * Reloads a task fresh from storage.
   */
  protected function reload(int $task_id) {
    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_task');
    $storage->resetCache([$task_id]);
    return $storage->load($task_id);
  }

}
