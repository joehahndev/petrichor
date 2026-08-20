<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tags\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\TestTools\Extension\SchemaInspector;
use Drupal\petrichor_memory\Entity\Memory;
use Drupal\petrichor_projects\Entity\Project;
use Drupal\petrichor_tasks\Entity\Task;
use Drupal\petrichor_wiki\Entity\WikiPage;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Relatedness by shared tag, across every entity type that carries tags.
 *
 * The claim under test is the one the tags module was built for and never
 * cashed in: a tag typed on a task and the same word typed on a memory are one
 * term, so the two records are connected. Until the Related section shipped,
 * nothing anywhere asked that question.
 *
 * Precision is the property worth guarding, not recall. A related list that is
 * usually wrong teaches people to skip it, which is worse than not having one
 * — so the tests that matter most here are the negative ones: an untagged
 * record relates to nothing, and a memory nobody confirmed stays invisible.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class RelatedTagSiblingsTest extends KernelTestBase {

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
    'petrichor_memory',
    'petrichor_markdown',
    'petrichor_wiki',
    'petrichor_tags',
  ];

  /**
   * The registry under test.
   *
   * @var \Drupal\petrichor\Related\RelatedRegistry
   */
  protected $related;

  /**
   * A project to hang everything off.
   *
   * @var \Drupal\petrichor_projects\Entity\Project
   */
  protected $project;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installEntitySchema('petrichor_memory');
    $this->installEntitySchema('petrichor_wiki_page');

    // petrichor_memory is enabled here, so its related provider runs alongside
    // the tag one and reaches for the recall table on every lookup.
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);

    $spec = SchemaInspector::getTablesSpecification(
      $this->container->get('module_handler'),
      'petrichor_wiki'
    );
    if ($spec) {
      $this->installSchema('petrichor_wiki', array_keys($spec));
    }

    $this->installConfig([
      'system',
      'filter',
      'petrichor',
      'petrichor_markdown',
      'petrichor_tags',
    ]);

    // Burn uid 1 so test accounts don't get the superuser bypass — these
    // queries are access-checked, and a superuser would hide a permission bug
    // rather than reveal it.
    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    // RelatedItem::fromEntity() resolves each row's canonical URL, which needs
    // real routes rather than link templates alone.
    $this->container->get('router.builder')->rebuild();

    $this->related = $this->container->get('petrichor.related');
    $this->project = Project::create(['label' => 'Acme redesign']);
    $this->project->save();
  }

  /**
   * One tag on three different entity types connects all three.
   *
   * The cross-type case is the whole point of a single shared vocabulary. If
   * this passes for tasks alone it proves nothing the old per-entity free-text
   * field could not already have done.
   */
  public function testTagSiblingsCrossEntityTypes(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $memory = $this->memory('Client hates multi-step forms', ['forms']);
    $page = $this->page('Form conventions', ['forms']);

    $groups = $this->groupsFor($task);

    // Sorted before comparing: group order follows entity-type discovery
    // order, which is not a documented guarantee and is not what this asserts.
    $ids = array_column($groups, 'id');
    sort($ids);
    $this->assertSame(
      ['tag_siblings:petrichor_memory', 'tag_siblings:petrichor_wiki_page'],
      $ids,
      'A task with one tagged sibling of each other type gets one group per type, and no empty group for its own type.',
    );
    $this->assertSame($this->ids($memory), $this->itemIds($groups, 'tag_siblings:petrichor_memory'));
    $this->assertSame($this->ids($page), $this->itemIds($groups, 'tag_siblings:petrichor_wiki_page'));

    // Relatedness is symmetric: the wiki page finds the task and the memory.
    $from_page = $this->groupsFor($page);
    $this->assertSame($this->ids($task), $this->itemIds($from_page, 'tag_siblings:petrichor_task'));
    $this->assertSame($this->ids($memory), $this->itemIds($from_page, 'tag_siblings:petrichor_memory'));
  }

  /**
   * A record with no tags relates to nothing at all.
   *
   * Not "an empty section" — no groups, so nothing renders. This is the
   * behaviour that keeps the feature from becoming furniture people learn to
   * ignore on the majority of pages that are untagged.
   */
  public function testUntaggedEntityHasNoGroups(): void {
    $this->task('Rebuild the intake form', ['forms']);
    $untagged = $this->task('Something else entirely', []);

    $set = $this->related->getRelated($untagged);

    $this->assertTrue($set->isEmpty());
    $this->assertSame([], $set->toArray());
  }

  /**
   * A tagged record never appears in its own related list.
   */
  public function testSelfIsExcluded(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $other = $this->task('Audit every form', ['forms']);

    $ids = $this->itemIds($this->groupsFor($task), 'tag_siblings:petrichor_task');

    $this->assertSame($this->ids($other), $ids);
    $this->assertNotContains((int) $task->id(), $ids);
  }

  /**
   * Only active memories surface; proposed and archived never do.
   *
   * The default recording policy stores an agent's memory as `proposed` and
   * keeps it out of recall until a human confirms it. A related list that
   * showed proposed memories would be a back door into exactly the influence
   * that policy exists to prevent, and `forget` archiving rather than deleting
   * would stop meaning anything.
   */
  public function testOnlyActiveMemoriesSurface(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $active = $this->memory('Confirmed decision', ['forms']);
    $this->memory('Unreviewed agent claim', ['forms'], 'proposed');
    $this->memory('Retired decision', ['forms'], 'archived');

    $ids = $this->itemIds($this->groupsFor($task), 'tag_siblings:petrichor_memory');

    $this->assertSame($this->ids($active), $ids);
  }

  /**
   * More shared tags ranks higher, and the count is stated on the row.
   */
  public function testRankingPrefersMoreSharedTags(): void {
    $task = $this->task('Rebuild the intake form', ['forms', 'accessibility']);
    $one = $this->task('Unrelated form tweak', ['forms']);
    $two = $this->task('Accessible form audit', ['forms', 'accessibility']);

    $groups = $this->groupsFor($task);
    $items = $this->group($groups, 'tag_siblings:petrichor_task')['items'];

    $this->assertSame(
      $this->ids($two, $one),
      array_column($items, 'id'),
      'Two shared tags outranks one.',
    );
    $this->assertSame('2 shared tags', $items[0]['meta']);
    $this->assertSame('1 shared tag', $items[1]['meta']);
  }

  /**
   * The set depends on every entity it read, and on the types it queried.
   *
   * Without the list tags, tagging a brand-new record would leave every page
   * that should now list it cached as it was — the stale-HTML failure whose
   * cause looks like it belongs to the tagging code rather than to this.
   */
  public function testCacheabilityCoversRelatedEntitiesAndLists(): void {
    $task = $this->task('Rebuild the intake form', ['forms']);
    $memory = $this->memory('Client hates multi-step forms', ['forms']);

    $tags = $this->related->getRelated($task)->getCacheTags();

    $this->assertContains('petrichor_memory:' . $memory->id(), $tags, 'The memory it found is a dependency.');
    $this->assertContains('petrichor_memory_list', $tags, 'So is the list, for memories written later.');
    $this->assertContains('petrichor_task_list', $tags);
    $this->assertContains('petrichor_wiki_page_list', $tags);
    $this->assertContains('petrichor_task:' . $task->id(), $tags, 'And the host entity, whose own tags decide all of it.');
  }

  /**
   * An untagged record still depends on the lists that could relate to it.
   *
   * The subtle half of the previous test. An empty related section must not be
   * cached forever — tagging this record tomorrow has to invalidate the page
   * that shows nothing today.
   */
  public function testEmptySetStillCarriesHostCacheability(): void {
    $untagged = $this->task('Something else entirely', []);

    $tags = $this->related->getRelated($untagged)->getCacheTags();

    $this->assertContains('petrichor_task:' . $untagged->id(), $tags);
  }

  /**
   * Groups as plain arrays, the shape twig and MCP both consume.
   */
  protected function groupsFor($entity): array {
    return $this->related->getRelated($entity)->toArray();
  }

  /**
   * One group out of a set, by id.
   */
  protected function group(array $groups, string $id): array {
    foreach ($groups as $group) {
      if ($group['id'] === $id) {
        return $group;
      }
    }
    $this->fail(sprintf('No group "%s" in: %s', $id, implode(', ', array_column($groups, 'id')) ?: '(none)'));
  }

  /**
   * The entity ids listed in one group.
   */
  protected function itemIds(array $groups, string $id): array {
    return array_column($this->group($groups, $id)['items'], 'id');
  }

  /**
   * Entity ids as integers, which is what a related row carries.
   *
   * EntityInterface::id() hands back a string even for a serial key, and the
   * related shape is typed. Without this the assertions compare '1' to 1 and
   * fail on a difference nobody cares about.
   */
  protected function ids(...$entities): array {
    return array_map(static fn($entity): int => (int) $entity->id(), $entities);
  }

  /**
   * A saved task in the shared project.
   */
  protected function task(string $label, array $tags): Task {
    $task = Task::create([
      'label' => $label,
      'project' => $this->project->id(),
      'status' => 'todo',
      'tags' => $this->terms($tags),
    ]);
    $task->save();
    return $task;
  }

  /**
   * A saved memory scoped to the shared project.
   */
  protected function memory(string $label, array $tags, string $status = 'active'): Memory {
    $memory = Memory::create([
      'label' => $label,
      'fact' => $label,
      'type' => 'decision',
      'status' => $status,
      'project' => $this->project->id(),
      'tags' => $this->terms($tags),
    ]);
    $memory->save();
    return $memory;
  }

  /**
   * A saved wiki page in the shared project.
   */
  protected function page(string $label, array $tags): WikiPage {
    $page = WikiPage::create([
      'label' => $label,
      'body' => $label,
      'project' => $this->project->id(),
      'tags' => $this->terms($tags),
    ]);
    $page->save();
    return $page;
  }

  /**
   * Tag names as term ids, through the resolver every write path uses.
   */
  protected function terms(array $names): array {
    return $this->container->get('petrichor_tags.resolver')->resolveNames($names);
  }

}
