<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_timesheets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What total_hours is for: the period total, not just the page total.
 *
 * No test existed for TimesheetService::listTimesheets() at all before this
 * — the only coverage on the module was the install/uninstall GenericTest.
 * That gap is exactly how the page-only sum went unnoticed: it read as
 * correct in isolation ("sums the rows returned"), and nothing exercised the
 * case a real user hits immediately — more entries in a period than fit on
 * one page.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class TimesheetListingTest extends KernelTestBase {

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
    'petrichor_timesheets',
  ];

  /**
   * The timesheet service under test.
   *
   * @var \Drupal\petrichor_timesheets\TimesheetService
   */
  protected $timesheets;

  /**
   * A task everything logs against.
   *
   * @var int
   */
  protected int $taskId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installEntitySchema('petrichor_timesheet');
    $this->installConfig(['system', 'filter']);

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);

    $this->timesheets = $this->container->get('petrichor_timesheets.service');

    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Acme redesign', 'status' => 'open']);
    $project->save();
    $task = $this->container->get('petrichor_tasks.service')->createTask([
      'title' => 'Build the thing',
      'project_id' => (int) $project->id(),
    ]);
    $this->taskId = (int) $task['id'];
  }

  /**
   * Covers the whole filtered set even when the page doesn't.
   *
   * The actual bug: five entries logged, a page size of two returns
   * listed_hours for only the first two — total_hours must still be the sum
   * of all five.
   */
  public function testTotalHoursCoversMoreThanOnePage(): void {
    foreach ([1, 2, 3, 4, 5] as $hours) {
      $this->timesheets->logTime([
        'task_id' => $this->taskId,
        'hours' => $hours,
        'date' => '2026-08-0' . $hours,
      ]);
    }

    $result = $this->timesheets->listTimesheets([], 2);

    $this->assertCount(2, $result['items']);
    $this->assertSame(5, $result['total']);
    $this->assertSame(15.0, $result['total_hours'], 'total_hours must cover all five entries, not just the page.');
    $this->assertLessThan($result['total_hours'], $result['listed_hours'], 'listed_hours should be the partial figure here, not the period total.');
    $this->assertStringContainsString('total_hours covers the whole filtered set', $result['note']);
  }

  /**
   * With no truncation, listed_hours and total_hours agree.
   */
  public function testTotalHoursMatchesListedHoursWhenNothingIsTruncated(): void {
    $this->timesheets->logTime(['task_id' => $this->taskId, 'hours' => 3, 'date' => '2026-08-01']);
    $this->timesheets->logTime(['task_id' => $this->taskId, 'hours' => 2, 'date' => '2026-08-02']);

    $result = $this->timesheets->listTimesheets([], 100);

    $this->assertSame(5.0, $result['listed_hours']);
    $this->assertSame(5.0, $result['total_hours']);
    $this->assertArrayNotHasKey('note', $result);
  }

  /**
   * Respects the same filters as the listing, not all entries.
   */
  public function testTotalHoursRespectsDateFilters(): void {
    $this->timesheets->logTime(['task_id' => $this->taskId, 'hours' => 10, 'date' => '2026-07-01']);
    $this->timesheets->logTime(['task_id' => $this->taskId, 'hours' => 4, 'date' => '2026-08-05']);
    $this->timesheets->logTime(['task_id' => $this->taskId, 'hours' => 6, 'date' => '2026-08-06']);

    $result = $this->timesheets->listTimesheets(['since' => '2026-08-01']);

    $this->assertSame(10.0, $result['total_hours'], 'The July entry must not count toward an August-onward total.');
  }

  /**
   * No matching entries sums to zero, not an error.
   */
  public function testTotalHoursWithNoEntriesIsZero(): void {
    $result = $this->timesheets->listTimesheets(['task_id' => 999999]);

    $this->assertSame(0.0, $result['total_hours']);
    $this->assertSame(0.0, $result['listed_hours']);
  }

}
