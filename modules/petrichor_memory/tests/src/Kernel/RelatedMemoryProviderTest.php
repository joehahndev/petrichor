<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\petrichor_memory\Entity\Memory;
use Drupal\petrichor_projects\Entity\Project;
use Drupal\petrichor_tasks\Entity\Task;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The decisions a task page shows, and the ones get_task already showed.
 *
 * This provider exists to close a parity gap rather than to add a feature:
 * `get_task` has returned `related_memories` to agents since the memory module
 * shipped, and the same task opened in a browser showed nothing. The tests
 * that matter are therefore the ones that pin *sameness* — same matching rule,
 * same rows, and the same refusal to record a recall that nobody asked for.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class RelatedMemoryProviderTest extends KernelTestBase {

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
    'petrichor_memory',
  ];

  /**
   * The registry under test.
   *
   * @var \Drupal\petrichor\Related\RelatedRegistry
   */
  protected $related;

  /**
   * The memory service, for comparing against the agent-facing path.
   *
   * @var \Drupal\petrichor_memory\MemoryService
   */
  protected $memory;

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
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_task');
    $this->installEntitySchema('petrichor_memory');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor']);

    $this->container->get('router.builder')->rebuild();

    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->related = $this->container->get('petrichor.related');
    $this->memory = $this->container->get('petrichor_memory.service');
    $this->project = Project::create(['label' => 'Acme redesign']);
    $this->project->save();
  }

  /**
   * A task page lists the decisions get_task would hand an agent.
   *
   * Asserted against relatedTo() rather than against a hard-coded list, so the
   * two surfaces cannot drift even if the matching rule is retuned later —
   * which is the only guarantee worth having here.
   */
  public function testTaskPageShowsWhatGetTaskShows(): void {
    $task = $this->task('Comment storage rewrite');
    $decision = $this->memory('Comment storage stays in core comment module', 'decision');

    $groups = $this->related->getRelated($task)->toArray();
    $group = $this->group($groups, 'related_memories');

    $expected = array_column(
      $this->memory->relatedTo($task->label(), (int) $this->project->id()),
      'id',
    );
    $this->assertNotEmpty($expected, 'The agent-facing path finds something to compare against.');
    $this->assertSame($expected, array_column($group['items'], 'id'));
    $this->assertContains((int) $decision->id(), array_column($group['items'], 'id'));
  }

  /**
   * Reading a task page must not count as recalling a memory.
   *
   * The last_recalled timestamp means "somebody went looking and this
   * answered", and it feeds the recency half of recall's ranking. Rendering a
   * page nobody asked to search would flatten that signal with hits nobody
   * acted on. The MCP path is careful about this already; the browser path
   * must not undo it.
   */
  public function testRenderingDoesNotRecordRecall(): void {
    $task = $this->task('Comment storage rewrite');
    $this->memory('Comment storage stays in core comment module', 'decision');

    $before = $this->recallRowCount();
    $this->related->getRelated($task);

    $this->assertSame($before, $this->recallRowCount(), 'No last_recalled row was written.');
  }

  /**
   * Learnings and references stay out; only decisions and preferences show.
   */
  public function testOnlyDecisionsAndPreferencesSurface(): void {
    $task = $this->task('Comment storage rewrite');
    $decision = $this->memory('Comment storage stays in core', 'decision');
    $this->memory('Comment storage was slow before', 'learning');

    $groups = $this->related->getRelated($task)->toArray();

    $this->assertSame(
      [(int) $decision->id()],
      array_column($this->group($groups, 'related_memories')['items'], 'id'),
    );
  }

  /**
   * A task nothing was decided about gets no group at all.
   */
  public function testTaskWithNoMatchingMemoriesHasNoGroup(): void {
    $task = $this->task('Comment storage rewrite');
    $this->memory('Client prefers sans-serif headings', 'preference');

    $groups = $this->related->getRelated($task)->toArray();

    $this->assertSame([], array_column($groups, 'id'));
  }

  /**
   * The set depends on the memories it listed and on the memory list.
   */
  public function testCacheabilityCoversMemories(): void {
    $task = $this->task('Comment storage rewrite');
    $decision = $this->memory('Comment storage stays in core comment module', 'decision');

    $tags = $this->related->getRelated($task)->getCacheTags();

    $this->assertContains('petrichor_memory:' . $decision->id(), $tags);
    $this->assertContains('petrichor_memory_list', $tags);
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
   * Rows in the recall side table.
   */
  protected function recallRowCount(): int {
    return (int) $this->container->get('database')
      ->select('petrichor_memory_recall')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * A saved task in the shared project.
   */
  protected function task(string $label): Task {
    $task = Task::create([
      'label' => $label,
      'project' => $this->project->id(),
      'status' => 'todo',
    ]);
    $task->save();
    return $task;
  }

  /**
   * A saved, active memory scoped to the shared project.
   */
  protected function memory(string $label, string $type): Memory {
    $memory = Memory::create([
      'label' => $label,
      'fact' => $label,
      'type' => $type,
      'status' => 'active',
      'project' => $this->project->id(),
    ]);
    $memory->save();
    return $memory;
  }

}
