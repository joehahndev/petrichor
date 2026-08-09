<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tags\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\TestTools\Extension\SchemaInspector;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * One tags field, shared by memory, tasks and the wiki at once.
 *
 * Attaching the same field to three entity types via
 * hook_entity_base_field_info() is only a real "shared vocabulary" if a name
 * typed on one of them
 * resolves to the exact term a different entity type already used — that is
 * the property this pins, with all three modules installed together, not
 * three separate single-entity tests that could each pass on a resolver
 * that secretly kept a separate table per entity type.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class TagsAttachToEntitiesTest extends KernelTestBase {

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
    'petrichor_markdown',
    'petrichor_memory',
    'petrichor_tasks',
    'petrichor_wiki',
    'petrichor_tags',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_memory');
    $this->installEntitySchema('petrichor_task');
    $this->installEntitySchema('petrichor_wiki_page');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor', 'petrichor_tags']);

    $spec = SchemaInspector::getTablesSpecification(
      $this->container->get('module_handler'),
      'petrichor_wiki'
    );
    if ($spec) {
      $this->installSchema('petrichor_wiki', array_keys($spec));
    }

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);
    $this->config('petrichor.settings')->set('memory_policy', 'automatic')->save();
  }

  /**
   * All three entity types report the field, not just the one under test.
   */
  public function testAllThreeEntityTypesHaveTheField(): void {
    $manager = $this->container->get('entity_field.manager');
    foreach (['petrichor_memory', 'petrichor_task', 'petrichor_wiki_page'] as $entity_type_id) {
      $this->assertArrayHasKey(
        'tags',
        $manager->getFieldStorageDefinitions($entity_type_id),
        "$entity_type_id should have a tags field."
      );
    }
  }

  /**
   * A tag typed on a memory and the same name typed on a task are one term.
   *
   * This is the point of a shared vocabulary rather than three word lists —
   * checked here by id equality, not just by both entities showing the same
   * string, which a bug that created a duplicate term with the same name
   * would still pass.
   */
  public function testTheSameNameAcrossEntityTypesResolvesToOneTerm(): void {
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Acme redesign', 'status' => 'open']);
    $project->save();

    $memory = $this->container->get('petrichor_memory.service')->remember([
      'title' => 'A fact',
      'fact' => 'A fact',
      'tags' => ['Accessibility'],
    ]);
    $task = $this->container->get('petrichor_tasks.service')->createTask([
      'title' => 'A task',
      'project_id' => (int) $project->id(),
      'tags' => ['accessibility'],
    ]);
    $page = $this->container->get('petrichor_wiki.service')->createWikiPage([
      'title' => 'A page',
      'tags' => ['ACCESSIBILITY'],
    ]);

    $this->assertSame($memory['tags'], $task['tags']);
    $this->assertSame($memory['tags'], $page['tags']);

    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'petrichor_tags')
      ->execute();
    $this->assertCount(1, $ids, 'One term should back all three entities, not one per entity type.');
  }

}
