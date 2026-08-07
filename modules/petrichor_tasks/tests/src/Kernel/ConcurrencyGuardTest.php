<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tasks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Optimistic concurrency, across UTC offsets.
 *
 * The guard used to compare RFC 3339 strings with `>`. That is only correct
 * while both sides render the same offset, and it failed *open* when they did
 * not — the write went through and silently overwrote whoever edited first.
 * These tests pin the instant-based comparison that replaced it.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class ConcurrencyGuardTest extends KernelTestBase {

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
   * The task under test.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $task;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installConfig(['system', 'filter']);

    // A fixed, non-UTC site timezone. The bug only shows when the server's
    // rendered offset differs from the caller's, so leaving this to the
    // machine's locale would make the test pass or fail by accident.
    $this->config('system.date')->set('timezone.default', 'America/New_York')->save();

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);

    $projects = $this->container->get('entity_type.manager')->getStorage('petrichor_project');
    $project = $projects->create(['label' => 'Concurrency']);
    $project->save();

    $tasks = $this->container->get('entity_type.manager')->getStorage('petrichor_task');
    $this->task = $tasks->create(['label' => 'Original', 'project' => $project->id()]);
    $this->task->save();
  }

  /**
   * The task's changed time, as a UNIX instant.
   */
  protected function changedAt(): int {
    return (int) $this->task->get('changed')->value;
  }

  /**
   * Renders an instant in an arbitrary timezone, RFC 3339.
   */
  protected function at(int $instant, string $timezone): string {
    return (new \DateTimeImmutable('@' . $instant))
      ->setTimezone(new \DateTimeZone($timezone))
      ->format(\DateTimeInterface::RFC3339);
  }

  /**
   * The same instant in another timezone is not a conflict.
   */
  public function testSameInstantInAnotherZoneIsNotConflict(): void {
    $token = $this->at($this->changedAt(), 'UTC');
    $result = $this->container->get('petrichor_tasks.service')
      ->updateTask((int) $this->task->id(), ['title' => 'Renamed', 'if_unchanged_since' => $token]);
    $this->assertSame('Renamed', $result['title']);
  }

  /**
   * A stale token in a far-eastern offset still conflicts.
   *
   * This is the regression. The task changed one hour AFTER the token, so the
   * write must be refused — but rendering the token at +14:00 pushes its date
   * a day forward, and the old string comparison therefore read it as newer
   * and allowed the overwrite.
   */
  public function testStaleTokenWithMisleadingOffsetStillConflicts(): void {
    $token = $this->at($this->changedAt() - 3600, 'Pacific/Kiritimati');

    // Prove the test is actually exercising the trap rather than passing for
    // an unrelated reason: as raw strings, the stale token sorts as the later
    // of the two, which is exactly what fooled the previous implementation.
    $server_rendered = $this->at($this->changedAt(), 'America/New_York');
    $this->assertGreaterThan(
      $server_rendered,
      $token,
      'The stale token should sort later as a string; if not, this test no longer covers the bug.',
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/^Conflict:/');
    $this->container->get('petrichor_tasks.service')
      ->updateTask((int) $this->task->id(), ['title' => 'Clobbered', 'if_unchanged_since' => $token]);
  }

  /**
   * An unreadable token refuses the write rather than waving it through.
   */
  public function testUnreadableTokenFailsClosed(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/^Conflict:/');
    $this->container->get('petrichor_tasks.service')
      ->updateTask((int) $this->task->id(), ['title' => 'Clobbered', 'if_unchanged_since' => 'not a timestamp']);
  }

  /**
   * No token means no guard: the caller has opted into last-write-wins.
   */
  public function testAbsentTokenSkipsTheGuard(): void {
    $result = $this->container->get('petrichor_tasks.service')
      ->updateTask((int) $this->task->id(), ['title' => 'Unguarded']);
    $this->assertSame('Unguarded', $result['title']);
  }

}
