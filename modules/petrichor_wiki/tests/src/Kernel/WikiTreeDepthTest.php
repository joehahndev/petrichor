<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\TestTools\Extension\SchemaInspector;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What the tree draws when there is too much of it to draw.
 *
 * The index rendered every page at every depth, which was correct and
 * unremarkable at fourteen pages. Importing the running project log takes the
 * wiki to several hundred, where an unpruned index stops being navigation and
 * becomes a wall, and where the skeleton cap stops being theoretical.
 *
 * The subtle one is orphan promotion. A page caught in a parent cycle is
 * reachable from no root, so the tree walk never sees it; the index used to
 * be the one surface guaranteed to show it anyway. A depth limit must not
 * quietly take that guarantee away, because "not shown at this depth" and
 * "shown nowhere in the wiki" look identical from the front page.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class WikiTreeDepthTest extends KernelTestBase {

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
    'petrichor_markdown',
    'petrichor_wiki',
  ];

  /**
   * The service under test.
   *
   * @var \Drupal\petrichor_wiki\WikiService
   */
  protected $wiki;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_wiki_page');

    $spec = SchemaInspector::getTablesSpecification(
      $this->container->get('module_handler'),
      'petrichor_wiki'
    );
    if ($spec) {
      $this->installSchema('petrichor_wiki', array_keys($spec));
    }
    $this->installConfig(['system', 'filter', 'petrichor', 'petrichor_markdown']);

    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
    $this->wiki = $this->container->get('petrichor_wiki.service');
  }

  /**
   * A depth limit stops the walk without hiding the roots.
   */
  public function testDepthLimitStopsAtTheGivenLevel(): void {
    $root = $this->page('Project logs');
    $project = $this->page('petrichor-drupal-pm', $root);
    $this->page('2026-08-10 — Related sections', $project);

    $shallow = $this->wiki->getTree(NULL, NULL, NULL, 2);
    $full = $this->wiki->getTree();

    $this->assertSame(['Project logs'], array_column($shallow['items'], 'title'));
    $this->assertSame(
      ['petrichor-drupal-pm'],
      array_column($shallow['items'][0]['children'], 'title'),
    );
    // A node at the depth boundary carries no children rather than an empty
    // list, so the component's `children is not empty` guard treats it the
    // same as a leaf.
    $this->assertEmpty(
      $shallow['items'][0]['children'][0]['children'] ?? [],
      'The third level is pruned.',
    );

    // Unlimited still reaches the entry, so the page is not lost — only
    // absent from the shallow view.
    $this->assertSame(
      ['2026-08-10 — Related sections'],
      array_column($full['items'][0]['children'][0]['children'], 'title'),
    );
    // The total counts the skeleton, not what was drawn.
    $this->assertSame(3, $shallow['total']);
  }

  /**
   * A cycle-trapped page is still promoted to a root under a depth limit.
   *
   * The regression this exists for: making the index shallow must not make a
   * page invisible everywhere at once.
   */
  public function testOrphansStillSurfaceUnderDepthLimit(): void {
    $this->page('Project logs');
    $a = $this->page('Cycle A');
    $b = $this->page('Cycle B', $a);
    // Close the loop: A's parent becomes B, so neither is reachable from a
    // real root any more.
    $a->set('parent', $b->id())->save();

    $titles = array_column($this->wiki->getTree(NULL, NULL, NULL, 2)['items'], 'title');

    $this->assertContains('Project logs', $titles);
    $this->assertContains('Cycle A', $titles, 'A cycle-trapped page is promoted to a root.');
  }

  /**
   * Asking for one branch still narrows to it — depth did not break pruning.
   */
  public function testRootIdStillPrunesToOneBranch(): void {
    $root = $this->page('Project logs');
    $this->page('petrichor-drupal-pm', $root);
    $this->page('Unrelated top-level page');

    $titles = array_column($this->wiki->getTree(NULL, NULL, (int) $root->id())['items'], 'title');

    $this->assertSame(['petrichor-drupal-pm'], $titles);
  }

  /**
   * More pages than fit in one IN clause still all come back.
   *
   * The skeleton reads its columns with one bound parameter per id, and
   * SQLite has historically stopped at 999 of them. The read is chunked so
   * the tree cap can be a judgement about readability rather than a database
   * limit in disguise; this is the test that fails if the chunking goes.
   */
  public function testSkeletonReadsMorePagesThanOneChunk(): void {
    $root = $this->page('Project logs');
    for ($i = 0; $i < 620; $i++) {
      $this->page(sprintf('Entry %03d', $i), $root);
    }

    $tree = $this->wiki->getTree();

    $this->assertSame(621, $tree['total']);
    $this->assertFalse($tree['truncated']);
    $this->assertCount(620, $tree['items'][0]['children']);
  }

  /**
   * A saved page, optionally under a parent.
   */
  protected function page(string $title, $parent = NULL) {
    $values = ['label' => $title, 'body' => $title];
    if ($parent !== NULL) {
      $values['parent'] = $parent->id();
    }
    $page = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_wiki_page')
      ->create($values);
    $page->save();
    return $page;
  }

}
