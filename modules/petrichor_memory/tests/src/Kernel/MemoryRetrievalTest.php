<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Passive surfacing must not disturb what it reads.
 *
 * A recall records that a memory was served, and that timestamp is half the
 * ranking. relatedTo() runs on reads nobody asked to search — every task read
 * in a session — so if it recorded the same way it would bump every match it
 * ever touched and flatten the signal into "recently read at all".
 *
 * The suite has now met this shape three times (board card order, the recall
 * table, and here), so it is pinned rather than commented.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class MemoryRetrievalTest extends KernelTestBase {

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

    // 'automatic' so the fixtures below land active without a confirm step;
    // the 'ask' path has its own coverage in MemoryPolicyTest.
    $this->config('petrichor.settings')->set('memory_policy', 'automatic')->save();
  }

  /**
   * Stores a memory and returns its id.
   */
  protected function store(string $title, string $fact, string $type, ?int $project_id = NULL): int {
    return (int) $this->memory->remember([
      'title' => $title,
      'fact' => $fact,
      'type' => $type,
      'project_id' => $project_id,
    ])['id'];
  }

  /**
   * Every row of the recall table, keyed by memory id.
   */
  protected function recallRows(): array {
    return $this->container->get('database')
      ->select('petrichor_memory_recall', 'r')
      ->fields('r', ['memory_id', 'last_recalled'])
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * The regression that matters: relatedTo() never writes the recall table.
   */
  public function testRelatedToDoesNotTouchTheRecallTable(): void {
    $id = $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');

    // recall() does record — establishing that the table is wired at all, so
    // an empty result below cannot be a broken fixture.
    $this->memory->recall('Storage');
    $before = $this->recallRows();
    $this->assertArrayHasKey($id, $before);

    for ($i = 0; $i < 3; $i++) {
      $this->assertNotSame([], $this->memory->relatedTo('Storage'));
    }
    $this->assertSame($before, $this->recallRows(), 'relatedTo() moved last_recalled.');
  }

  /**
   * A memory nobody has recalled stays absent from the table entirely.
   */
  public function testRelatedToCreatesNoRecallRow(): void {
    $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');
    $this->memory->relatedTo('Storage');
    $this->assertSame([], $this->recallRows());
  }

  /**
   * Proposed memories never surface — the review gate holds here too.
   */
  public function testProposedMemoriesDoNotSurface(): void {
    $this->config('petrichor.settings')->set('memory_policy', 'ask')->save();
    $this->memory->remember([
      'title' => 'Storage layout',
      'fact' => 'Comment bodies stay in core.',
      'type' => 'decision',
    ]);
    $this->assertSame([], $this->memory->relatedTo('Storage'));
  }

  /**
   * Archived memories do not surface either.
   */
  public function testArchivedMemoriesDoNotSurface(): void {
    $id = $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');
    $this->setUpCurrentUser([], [
      'view petrichor content',
      'manage petrichor content',
      'archive petrichor memories',
    ]);
    $this->memory->forget($id);
    $this->assertSame([], $this->memory->relatedTo('Storage'));
  }

  /**
   * Only constraint-shaped types ride along; reference material does not.
   */
  public function testOnlyDecisionsAndPreferencesSurface(): void {
    $decision = $this->store('Storage decision', 'Bodies stay in core.', 'decision');
    $preference = $this->store('Storage preference', 'Prefer plain tables.', 'preference');
    $this->store('Storage learning', 'SQLite needs WAL.', 'learning');
    $this->store('Storage reference', 'See the schema docs.', 'reference');

    $ids = array_column($this->memory->relatedTo('Storage', NULL, ['decision', 'preference'], 10), 'id');
    sort($ids);
    $this->assertSame([$decision, $preference], $ids);
  }

  /**
   * Titles and ids only — bodies stay behind recall().
   */
  public function testResultsCarryNoFactBody(): void {
    $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');
    $related = $this->memory->relatedTo('Storage');

    $this->assertCount(1, $related);
    $this->assertSame(['id', 'title'], array_keys($related[0]));
    $this->assertSame('Storage layout', $related[0]['title']);
  }

  /**
   * The cap holds, so a broad title cannot flood a task read.
   */
  public function testTheLimitIsRespected(): void {
    foreach (range(1, 6) as $n) {
      $this->store("Storage rule $n", 'Something about storage.', 'decision');
    }
    $this->assertCount(3, $this->memory->relatedTo('Storage', NULL, ['decision', 'preference'], 3));
  }

  /**
   * Project scope ranks above globals, and globals still appear.
   */
  public function testProjectScopedMatchesRankAboveGlobals(): void {
    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Scoped', 'status' => 'open']);
    $project->save();
    $project_id = (int) $project->id();

    $global = $this->store('Storage global', 'Applies everywhere.', 'decision');
    $scoped = $this->store('Storage scoped', 'Applies here.', 'decision', $project_id);

    $ids = array_column($this->memory->relatedTo('Storage', $project_id), 'id');
    $this->assertSame([$scoped, $global], $ids);
  }

  /**
   * A memory belonging to another project does not leak across.
   */
  public function testOtherProjectsMemoriesDoNotSurface(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_project');
    $ids = [];
    foreach (['a', 'b'] as $key) {
      $project = $storage->create(['label' => "Project $key", 'status' => 'open']);
      $project->save();
      $ids[$key] = (int) $project->id();
    }
    $this->store('Storage rule', 'Applies to A only.', 'decision', $ids['a']);

    $this->assertSame([], $this->memory->relatedTo('Storage', $ids['b']));
  }

  /**
   * An empty keyword returns nothing rather than throwing.
   *
   * Deliberately unlike recall(), which throws — a caller that asks for
   * nothing has a bug. relatedTo() is called with whatever title a task
   * happens to have, and an untitled task must not turn a read into an error.
   */
  public function testEmptyKeywordsReturnNothing(): void {
    $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');
    $this->assertSame([], $this->memory->relatedTo('  '));
  }

  /**
   * A title matches on its words, not as one substring.
   *
   * The whole feature turns on this. A task called "Comment storage rewrite"
   * and the decision about it never share a full title, so matching the title
   * as a single string finds nothing — which is exactly what the first
   * implementation did, and what the task-level tests caught.
   */
  public function testTitleWordsMatchIndividually(): void {
    $id = $this->store('Comment storage decision', 'Bodies stay in core.', 'decision');
    $related = $this->memory->relatedTo('Comment storage rewrite');

    $this->assertSame([$id], array_column($related, 'id'));
  }

  /**
   * Matching more of the title ranks higher.
   */
  public function testMatchingMoreTermsRanksFirst(): void {
    $loose = $this->store('Comment storage conventions', 'General notes.', 'decision');
    $close = $this->store('Comment storage rewrite policy', 'Bodies stay in core.', 'decision');

    $ids = array_column($this->memory->relatedTo('Comment storage rewrite'), 'id');
    $this->assertSame([$close, $loose], $ids);
  }

  /**
   * One shared word is a coincidence, and is not surfaced.
   *
   * Measured, not assumed: matching on a single term put a memory on
   * three-quarters of the tasks in the real database, nearly all of them
   * about something else.
   */
  public function testOneSharedWordIsNotEnough(): void {
    $this->store('Storage conventions', 'General notes.', 'decision');
    $this->assertSame([], $this->memory->relatedTo('Comment storage rewrite'));
  }

  /**
   * A one-word title still matches on that one word.
   */
  public function testSingleTermTitlesStillMatch(): void {
    $id = $this->store('Storage conventions', 'General notes.', 'decision');
    $this->assertSame([$id], array_column($this->memory->relatedTo('Storage'), 'id'));
  }

  /**
   * Fact bodies are not searched — only what the memory is called.
   *
   * The other half of the precision fix. Bodies are prose, and prose about
   * one subject mentions half a dozen others in passing.
   */
  public function testFactBodiesAreNotSearched(): void {
    $this->store('An unrelated title', 'This body mentions comment storage rewrite work.', 'decision');
    $this->assertSame([], $this->memory->relatedTo('Comment storage rewrite'));

    // recall() does search bodies: it was asked to.
    $this->assertCount(1, $this->memory->recall('comment storage rewrite')['items']);
  }

  /**
   * Tags count as terms, so a tagged memory surfaces on its subject.
   */
  public function testTagsAreSearched(): void {
    $id = (int) $this->memory->remember([
      'title' => 'An unrelated title',
      'fact' => 'Body.',
      'type' => 'decision',
      'tags' => ['comment', 'storage'],
    ])['id'];
    $this->assertSame([$id], array_column($this->memory->relatedTo('Comment storage rewrite'), 'id'));
  }

  /**
   * Short and common words are not searched on.
   *
   * Without this every task read matches every memory containing "the", and
   * the three slots fill with noise before anything relevant is considered.
   */
  public function testShortAndCommonWordsAreIgnored(): void {
    $this->store('A decision', 'This is the one with all of the common words.', 'decision');
    $this->assertSame([], $this->memory->relatedTo('Do the one with that'));
  }

  /**
   * Recall still records, after the shared matcher was extracted.
   */
  public function testRecallStillTouchesTheRecallTable(): void {
    $id = $this->store('Storage layout', 'Comment bodies stay in core.', 'decision');
    $this->assertSame([], $this->recallRows());

    $this->memory->recall('Storage');
    $this->assertArrayHasKey($id, $this->recallRows());
  }

}
