<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The no-hard-delete guarantee, as a regression test.
 *
 * Loops every Petrichor entity type in the registry so a future entity type
 * cannot dodge the shared access handler. This is the one piece of code
 * whose silent regression is a security hole rather than a bug: agent roles
 * must never be able to delete, on any transport.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class DeleteDenialTest extends KernelTestBase {

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
    'petrichor_milestones',
    'petrichor_timesheets',
    'petrichor_wiki',
    'petrichor_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    foreach ($this->petrichorEntityTypeIds() as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    // Burn uid 1 so test accounts don't get the superuser bypass.
    $this->createUser([], 'uid1-superuser');
  }

  /**
   * Petrichor entity type ids straight from the registry.
   */
  protected function petrichorEntityTypeIds(): array {
    $ids = [];
    foreach ($this->container->get('entity_type.manager')->getDefinitions() as $id => $definition) {
      if (str_starts_with($definition->getProvider(), 'petrichor')) {
        $ids[] = $id;
      }
    }
    $this->assertNotEmpty($ids);
    return $ids;
  }

  /**
   * Accounts with manage (the agent-contributor shape) cannot delete anything.
   */
  public function testManagePermissionCannotDelete(): void {
    $contributor = $this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]);
    $etm = $this->container->get('entity_type.manager');
    foreach ($this->petrichorEntityTypeIds() as $entity_type_id) {
      $entity = $etm->getStorage($entity_type_id)->create(['label' => 'probe']);
      $this->assertTrue($entity->access('update', $contributor), "$entity_type_id: update allowed for contributor");
      $this->assertFalse($entity->access('delete', $contributor), "$entity_type_id: delete DENIED for contributor");
    }
  }

  /**
   * The dedicated delete permission is what grants delete — nothing else.
   */
  public function testDeletePermissionGrantsDelete(): void {
    $deleter = $this->createUser([
      'view petrichor content',
      'manage petrichor content',
      'delete petrichor content',
    ]);
    $viewer = $this->createUser(['view petrichor content']);
    $etm = $this->container->get('entity_type.manager');
    foreach ($this->petrichorEntityTypeIds() as $entity_type_id) {
      $entity = $etm->getStorage($entity_type_id)->create(['label' => 'probe']);
      $this->assertTrue($entity->access('delete', $deleter), "$entity_type_id: delete allowed with delete permission");
      $this->assertFalse($entity->access('delete', $viewer), "$entity_type_id: delete denied for viewer");
      $this->assertFalse($entity->access('update', $viewer), "$entity_type_id: update denied for viewer");
    }
  }

}
