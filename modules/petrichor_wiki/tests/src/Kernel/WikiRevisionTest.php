<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Kernel;

use Drupal\Core\Utility\UpdateException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\TestTools\Extension\SchemaInspector;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Wiki page history, as a regression test.
 *
 * PRODUCT.md measures the wiki against a git-backed markdown wiki, so
 * history is not a nice-to-have here — it is the thing being compared. The
 * failure this guards against is quiet: an entity type that is revisionable
 * in every visible way while the history stays one row forever, because
 * nothing in Drupal forks a revision unless a form checkbox is ticked and
 * MCP never sees a form.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class WikiRevisionTest extends KernelTestBase {

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
    'petrichor_wiki',
  ];

  /**
   * The account every revision in this test is attributed to.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_wiki_page');

    // Whatever side tables petrichor_wiki declares — the link/title index lives
    // in this module too, and its save hooks would hit a missing table.
    $spec = SchemaInspector::getTablesSpecification(
      $this->container->get('module_handler'),
      'petrichor_wiki'
    );
    if ($spec) {
      $this->installSchema('petrichor_wiki', array_keys($spec));
    }

    // Burn uid 1 so test accounts don't get the superuser bypass.
    $this->createUser([], 'uid1-superuser');
    $this->editor = $this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]);
    $this->setCurrentUser($this->editor);
  }

  /**
   * Saving a new page produces exactly one revision, attributed and dated.
   */
  public function testCreateMakesOneRevision(): void {
    $page = $this->createPage(['label' => 'Herd on Windows', 'body' => 'first draft']);

    $this->assertNotNull($page->getRevisionId());
    $this->assertTrue($page->isDefaultRevision());
    $this->assertSame([(int) $page->getRevisionId()], $this->revisionIds($page));

    // A revision nobody wrote at no particular time is not history. Only the
    // seed revisions created by the conversion update may lack these.
    $this->assertSame((int) $this->editor->id(), (int) $page->getRevisionUserId());
    $this->assertNotEmpty($page->getRevisionCreationTime());
  }

  /**
   * An edit forks a revision and the previous one stays byte-for-byte intact.
   */
  public function testEditKeepsPreviousRevisionRetrievable(): void {
    $parent = $this->createPage(['label' => 'Parent']);
    $page = $this->createPage([
      'label' => 'Original title',
      'body' => 'Original body.',
      'parent' => $parent->id(),
      'weight' => 3,
    ]);
    $first_revision_id = (int) $page->getRevisionId();

    $page->set('label', 'Revised title');
    $page->set('body', ['value' => 'Revised body.', 'format' => 'markdown']);
    $page->set('weight', 9);
    $page->save();
    $second_revision_id = (int) $page->getRevisionId();

    $this->assertNotSame($first_revision_id, $second_revision_id);
    $this->assertGreaterThan($first_revision_id, $second_revision_id);

    $old = $this->storage()->loadRevision($first_revision_id);
    $this->assertSame('Original title', $old->label());
    $this->assertSame('Original body.', $old->get('body')->value);
    $this->assertSame(3, (int) $old->get('weight')->value);
    // Parent is revisionable on purpose: an old revision must show where the
    // page was, not where it is now.
    $this->assertSame((int) $parent->id(), (int) $old->get('parent')->target_id);
    $this->assertFalse($old->isDefaultRevision());

    $current = $this->reload($page);
    $this->assertSame('Revised title', $current->label());
    $this->assertSame('Revised body.', $current->get('body')->value);
    $this->assertSame(9, (int) $current->get('weight')->value);
    $this->assertTrue($current->isDefaultRevision());
  }

  /**
   * The "why" round-trips, and does not leak onto the next edit.
   *
   * A log message is the one part of a revision that cannot be reconstructed
   * from a diff, which is exactly why it must not be inherited: a fresh
   * revision wearing the previous edit's reasoning is worse than a blank one.
   */
  public function testRevisionLogMessageRoundTrips(): void {
    $page = $this->createPage(['label' => 'Deploy notes', 'body' => 'v1']);

    $page->set('body', ['value' => 'v2', 'format' => 'markdown']);
    $page->setRevisionLogMessage('Corrected the ddev port after the 1.24 upgrade.');
    $page->save();
    $explained = (int) $page->getRevisionId();

    $this->assertSame(
      'Corrected the ddev port after the 1.24 upgrade.',
      $this->storage()->loadRevision($explained)->getRevisionLogMessage()
    );

    $page = $this->reload($page);
    $page->set('body', ['value' => 'v3', 'format' => 'markdown']);
    $page->save();

    $this->assertSame('', (string) $this->storage()
      ->loadRevision((int) $page->getRevisionId())
      ->getRevisionLogMessage());
    // The earlier revision keeps its own reason.
    $this->assertSame(
      'Corrected the ddev port after the 1.24 upgrade.',
      $this->storage()->loadRevision($explained)->getRevisionLogMessage()
    );
  }

  /**
   * N edits leave N+1 revisions — no silent merging, no runaway duplication.
   */
  public function testRevisionCountTracksEdits(): void {
    $page = $this->createPage(['label' => 'Counted', 'body' => 'edit 0']);
    $this->assertCount(1, $this->revisionIds($page));

    for ($i = 1; $i <= 5; $i++) {
      $page = $this->reload($page);
      $page->set('body', ['value' => 'edit ' . $i, 'format' => 'markdown']);
      $page->save();
      $this->assertCount($i + 1, $this->revisionIds($page), "after $i edits");
    }

    $revisions = $this->storage()->loadMultipleRevisions($this->revisionIds($page));
    // Keyed by revision id; sort rather than trust the load order.
    ksort($revisions);
    $bodies = array_map(static fn ($revision): string => $revision->get('body')->value, $revisions);
    $this->assertSame(
      ['edit 0', 'edit 1', 'edit 2', 'edit 3', 'edit 4', 'edit 5'],
      array_values($bodies)
    );
  }

  /**
   * Syncing writes correct in place — the documented opt-out from forking.
   *
   * Derived-data repair and imports are not editorial acts and must not show up
   * in a human's history; if this stops working, a rebuild pass would double
   * the revision table.
   */
  public function testSyncingSavesInPlace(): void {
    $page = $this->createPage(['label' => 'In place', 'body' => 'v1']);
    $revision_id = (int) $page->getRevisionId();

    $page = $this->reload($page);
    $page->setSyncing(TRUE);
    $page->set('body', ['value' => 'v1 repaired', 'format' => 'markdown']);
    $page->save();

    $this->assertSame([$revision_id], $this->revisionIds($page));
    $this->assertSame('v1 repaired', $this->storage()->loadRevision($revision_id)->get('body')->value);
  }

  /**
   * The revision routes' four access operations resolve, and delete stays shut.
   *
   * Core asks for these by name; an access handler that does not answer them
   * returns neutral, which is a 403 on every revision route with nothing
   * logged.
   */
  public function testRevisionAccessOperations(): void {
    $page = $this->createPage(['label' => 'Access probe']);
    $viewer = $this->createUser(['view petrichor content']);

    $this->assertTrue($page->access('view all revisions', $this->editor));
    $this->assertTrue($page->access('view revision', $this->editor));
    $this->assertTrue($page->access('view all revisions', $viewer));

    // Reverting is an edit; discarding a revision is a delete. Neither the
    // contributor shape nor the viewer may discard, on any transport.
    $page->set('body', ['value' => 'v2', 'format' => 'markdown']);
    $page->save();
    $old = $this->storage()->loadRevision($this->revisionIds($page)[0]);

    $this->assertTrue($old->access('revert', $this->editor));
    $this->assertFalse($old->access('revert', $viewer));
    $this->assertFalse($old->access('delete revision', $this->editor));
    $this->assertFalse($old->access('delete revision', $viewer));
  }

  /**
   * The conversion update refuses to run a second time.
   *
   * This is the data-destroying path: a re-run against an already-converted
   * table would copy revisions into revisions. The full conversion itself is
   * only faithfully testable from a pre-conversion database — a kernel test
   * installs the schema from current code, so the entity type is revisionable
   * before the first line of the update runs, and the guard is what can be
   * proved here.
   */
  public function testConversionUpdateRefusesToRerun(): void {
    $this->container->get('module_handler')->loadInclude('petrichor_wiki', 'install');
    $sandbox = [];

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('petrichor_wiki_page_revision already exists');
    petrichor_wiki_update_11001($sandbox);
  }

  /**
   * Creates and saves a wiki page with sensible defaults.
   */
  protected function createPage(array $values) {
    $values += ['label' => 'Untitled', 'body' => ''];
    if (is_string($values['body'])) {
      $values['body'] = ['value' => $values['body'], 'format' => 'markdown'];
    }
    $page = $this->storage()->create($values);
    $page->save();
    return $page;
  }

  /**
   * All revision ids for a page, oldest first.
   */
  protected function revisionIds($page): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $page->id())
      ->sort('revision_id')
      ->execute();
    return array_map(intval(...), array_keys($ids));
  }

  /**
   * Reloads an entity fresh from storage.
   */
  protected function reload($entity) {
    $storage = $this->storage();
    $storage->resetCache([$entity->id()]);
    return $storage->load($entity->id());
  }

  /**
   * The wiki page storage handler.
   */
  protected function storage() {
    return $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
  }

}
