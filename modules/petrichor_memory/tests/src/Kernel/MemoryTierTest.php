<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The maintainer gate on forget, as a regression test.
 *
 * DeleteDenialTest covers the entity access handler; this covers the second
 * enforcement point — the service-layer permission check that keeps
 * contributor-tier agents from removing facts from the active set.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class MemoryTierTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_memory');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    // Burn uid 1 so test accounts don't get the superuser bypass.
    $this->createUser([], 'uid1-superuser');
  }

  /**
   * Contributor-shaped accounts cannot forget; maintainer-shaped ones can.
   */
  public function testForgetRequiresMaintainerTier(): void {
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create(['label' => 'A fact', 'status' => 'active']);
    $memory->save();
    $service = $this->container->get('petrichor_memory.service');

    $contributor = $this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]);
    $this->setCurrentUser($contributor);
    try {
      $service->forget((int) $memory->id());
      $this->fail('Contributor forget should throw.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('archive petrichor memories', $e->getMessage());
    }
    $this->assertSame('active', $this->reload($memory)->get('status')->value);

    $maintainer = $this->createUser([
      'view petrichor content',
      'manage petrichor content',
      'archive petrichor memories',
    ]);
    $this->setCurrentUser($maintainer);
    $result = $service->forget((int) $memory->id());
    $this->assertSame('archived', $result['status']);
    $this->assertSame('archived', $this->reload($memory)->get('status')->value);
  }

  /**
   * Reloads an entity fresh from storage.
   */
  protected function reload($entity) {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);
    return $storage->load($entity->id());
  }

}
