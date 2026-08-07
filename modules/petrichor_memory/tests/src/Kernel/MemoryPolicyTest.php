<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The memory_policy setting, and that 'ask' is actually enforced.
 *
 * The point of storing 'proposed' rather than just telling the agent to ask is
 * that it holds whether or not the agent cooperates: a proposed memory is real
 * and durable, but recall() and the context bundle only ever see 'active', so
 * nothing an agent recorded can influence a later session until a human has
 * seen it. These tests pin that.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class MemoryPolicyTest extends KernelTestBase {

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
    'petrichor_memory',
  ];

  /**
   * The memory service.
   *
   * @var \Drupal\petrichor_memory\MemoryService
   */
  protected $memory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_memory');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor']);
    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);
    $this->memory = $this->container->get('petrichor_memory.service');
  }

  /**
   * Sets the site memory policy.
   */
  protected function setPolicy(string $policy): void {
    $this->config('petrichor.settings')->set('memory_policy', $policy)->save();
  }

  /**
   * The shipped default is 'ask'.
   */
  public function testDefaultPolicyIsAsk(): void {
    $this->assertSame('ask', $this->config('petrichor.settings')->get('memory_policy'));
  }

  /**
   * Under 'ask', a new memory is proposed and invisible to recall.
   */
  public function testAskStoresProposedAndHidesItFromRecall(): void {
    $this->setPolicy('ask');
    $result = $this->memory->remember(['title' => 'Zebra fact', 'fact' => 'Zebras are striped.']);

    $this->assertSame('proposed', $result['status']);
    $this->assertStringContainsString('not yet active', $result['note']);

    // The whole point: it exists, but nothing surfaces it.
    $this->assertSame([], $this->memory->recall('Zebra')['items']);
    $this->assertSame([], $this->memory->listMemories()['items']);
    $this->assertCount(1, $this->memory->listMemories(['status' => 'proposed'])['items']);
  }

  /**
   * Confirming a proposed memory makes it recallable.
   */
  public function testConfirmMakesItRecallable(): void {
    $this->setPolicy('ask');
    $id = (int) $this->memory->remember(['title' => 'Zebra fact', 'fact' => 'Zebras are striped.'])['id'];

    $this->assertSame('active', $this->memory->confirmMemory($id)['status']);
    $this->assertCount(1, $this->memory->recall('Zebra')['items']);
  }

  /**
   * Confirming something that is not proposed is refused, not silently ignored.
   */
  public function testConfirmingTwiceRefuses(): void {
    $this->setPolicy('ask');
    $id = (int) $this->memory->remember(['title' => 'Zebra', 'fact' => 'Striped.'])['id'];
    $this->memory->confirmMemory($id);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/not proposed/');
    $this->memory->confirmMemory($id);
  }

  /**
   * Under 'automatic', a memory is active immediately.
   */
  public function testAutomaticStoresActive(): void {
    $this->setPolicy('automatic');
    $result = $this->memory->remember(['title' => 'Zebra', 'fact' => 'Striped.']);

    $this->assertSame('active', $result['status']);
    $this->assertCount(1, $this->memory->recall('Zebra')['items']);
  }

  /**
   * Under 'on_demand', an unrequested write is refused with instructions.
   */
  public function testOnDemandRefusesUnlessRequested(): void {
    $this->setPolicy('on_demand');
    try {
      $this->memory->remember(['title' => 'Zebra', 'fact' => 'Striped.']);
      $this->fail('Expected an unrequested memory to be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('requested: true', $e->getMessage());
    }
    $this->assertSame([], $this->memory->listMemories(['status' => 'proposed'])['items']);

    // And that the escape hatch works when the user really did ask.
    $result = $this->memory->remember([
      'title' => 'Zebra',
      'fact' => 'Striped.',
      'requested' => TRUE,
    ]);
    $this->assertSame('active', $result['status']);
  }

}
