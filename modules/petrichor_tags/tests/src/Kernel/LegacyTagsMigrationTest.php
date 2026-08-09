<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tags\Kernel;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Installing petrichor_tags on a site with real pre-existing tag data.
 *
 * This is the scenario the clean-install test suite structurally cannot
 * cover: every other Kernel test enables petrichor_tags alongside
 * petrichor_memory/petrichor_tasks from a bare database, so neither entity
 * type ever has an installed 'tags' field before petrichor_tags provides
 * one. A real site that predates this module does — Memory/Task used to
 * define their own native string 'tags' field, and that field, with real
 * data, is still sitting in the installed schema the day petrichor_tags
 * is first enabled.
 *
 * That distinction matters because Drupal's own ModuleInstaller::install()
 * auto-installs a newly-enabled module's base fields onto every existing
 * entity type as an unconditional first step, before hook_install() runs.
 * Finding a field named 'tags' already installed (the old string one), it
 * overwrites the "last installed" bookkeeping to say the new type
 * (entity_reference) is now installed — without touching the actual SQL
 * table, since a table by that name already exists. petrichor_tags_install()
 * then reads that already-overwritten bookkeeping, concludes the field was
 * already migrated, and skips — leaving a table with old string columns
 * that every subsequent entity query addresses as if it had new reference
 * columns. This is reproduced here with a raw-SQL-seeded 'tags' table
 * exactly as a real site's would look, not simulated by installing
 * petrichor_tags from a bare database.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class LegacyTagsMigrationTest extends KernelTestBase {

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
    'petrichor_memory',
    'petrichor_tasks',
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
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor']);
    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);
  }

  /**
   * Installs an old-style string 'tags' field with real seeded data.
   *
   * Seeded with raw SQL, not the entity API: current code no longer defines
   * this field on either entity type (it was removed from Memory.php and
   * Task.php in the same change that introduced petrichor_tags), so there
   * is nothing for the entity API to write through — exactly the state a
   * real pre-existing site is in the moment its code is upgraded, before
   * petrichor_tags is ever enabled.
   */
  protected function installLegacyStringTagsField(string $entity_type_id, array $tags_by_entity_id): void {
    $manager = \Drupal::entityDefinitionUpdateManager();
    $definition = BaseFieldDefinition::create('string')
      ->setLabel('Tags')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);
    $manager->installFieldStorageDefinition('tags', $entity_type_id, $entity_type_id, $definition);

    $table = $entity_type_id . '__tags';
    foreach ($tags_by_entity_id as $entity_id => $tags) {
      foreach (array_values($tags) as $delta => $value) {
        \Drupal::database()->insert($table)->fields([
          'bundle' => $entity_type_id,
          'deleted' => 0,
          'entity_id' => $entity_id,
          'revision_id' => $entity_id,
          'langcode' => 'en',
          'delta' => $delta,
          'tags_value' => $value,
        ])->execute();
      }
    }
  }

  /**
   * The bug: real legacy data left the site unreadable after install.
   *
   * Pinned as a regression test in its own right — this must keep failing
   * on the old single-phase hook_install() approach and keep passing once
   * the fix (removing the old field in petrichor_memory/petrichor_tasks's
   * own update hooks, before petrichor_tags is ever enabled) is in place.
   */
  public function testInstallingOverLegacyDataDoesNotBreakEntityLoading(): void {
    $memory = $this->container->get('petrichor_memory.service')->remember([
      'title' => 'A fact',
      'fact' => 'A fact',
      'requested' => TRUE,
    ]);
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Acme redesign', 'status' => 'open']);
    $project->save();
    $task = $this->container->get('petrichor_tasks.service')->createTask([
      'title' => 'A task',
      'project_id' => (int) $project->id(),
    ]);

    $this->installLegacyStringTagsField('petrichor_memory', [
      (int) $memory['id'] => ['Forms', 'Accessibility'],
    ]);
    $this->installLegacyStringTagsField('petrichor_task', [
      (int) $task['id'] => ['Forms'],
    ]);

    // The real deploy order: updatedb (which removes the old fields and
    // stashes their data) runs before petrichor_tags is ever enabled — not
    // the other way around.
    \Drupal::moduleHandler()->loadInclude('petrichor_memory', 'install');
    petrichor_memory_update_11004();
    \Drupal::moduleHandler()->loadInclude('petrichor_tasks', 'install');
    petrichor_tasks_update_11001();

    \Drupal::service('module_installer')->install(['petrichor_tags']);

    $memory_storage = $this->container->get('entity_type.manager')->getStorage('petrichor_memory');
    $loaded_memory = $memory_storage->load($memory['id']);
    $this->assertNotNull($loaded_memory, 'A memory that predates petrichor_tags must still load after it is installed.');
    $this->assertSame(
      ['Forms', 'Accessibility'],
      $this->container->get('petrichor_tags.resolver')->namesFor($loaded_memory)
    );

    $task_storage = $this->container->get('entity_type.manager')->getStorage('petrichor_task');
    $loaded_task = $task_storage->load($task['id']);
    $this->assertNotNull($loaded_task, 'A task that predates petrichor_tags must still load after it is installed.');
    $this->assertSame(
      ['Forms'],
      $this->container->get('petrichor_tags.resolver')->namesFor($loaded_task)
    );

    // The same name migrated from both entity types is one term, not two —
    // the migration path shares the resolver's dedup, not a parallel one.
    $term_storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $forms_term_ids = $term_storage->getQuery()->accessCheck(FALSE)
      ->condition('vid', 'petrichor_tags')
      ->condition('name', 'Forms')
      ->execute();
    $this->assertCount(1, $forms_term_ids, '"Forms" migrated from two entity types should be one term.');
  }

}
