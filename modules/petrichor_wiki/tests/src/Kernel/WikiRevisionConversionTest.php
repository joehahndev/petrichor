<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Kernel;

use Drupal\Core\Utility\UpdateException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\TestTools\Extension\SchemaInspector;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The revisionable conversion, against data that predates it.
 *
 * Petrichor_wiki_update_11001() is the only code in this suite that can lose a
 * wiki, and the same code runs on other people's sites where the
 * data is not ours and the snapshot is not ours to take. WikiRevisionTest
 * covers revisions once they exist; this covers becoming revisionable at all.
 *
 * The pre-conversion state is real, not simulated: petrichor_wiki_pre_revision
 * presents the entity type as it was before revisions, the schema is installed
 * from that, pages are written into it, and only then is the flag dropped and
 * the actual update run — the same sequence as deploying code and running
 * update.php.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class WikiRevisionConversionTest extends KernelTestBase {

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
    'petrichor_wiki_pre_revision',
  ];

  /**
   * Pages written before the conversion, keyed by title.
   */
  protected array $before = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Rewind before any schema is installed, so what lands in the database is
    // the pre-conversion shape rather than today's.
    $this->container->get('state')->set('petrichor_wiki_pre_revision.active', TRUE);
    $this->refreshDefinitions();

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

    $this->assertFalse(
      $this->entityType()->isRevisionable(),
      'the entity type starts out non-revisionable, or this test proves nothing'
    );
  }

  /**
   * Every page keeps every field, and gains exactly one revision.
   */
  public function testConversionPreservesPages(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');

    $parent = $storage->create(['label' => 'Parent page', 'weight' => 1]);
    $parent->save();
    $child = $storage->create([
      'label' => 'Child page',
      'body' => ['value' => "# Heading\n\nBody text that must survive.", 'format' => 'markdown'],
      'parent' => $parent->id(),
      'weight' => 7,
    ]);
    $child->save();
    $third = $storage->create(['label' => 'Third page', 'weight' => 3]);
    $third->save();

    foreach ([$parent, $child, $third] as $page) {
      $this->before[$page->id()] = [
        'label' => $page->label(),
        'body' => $page->get('body')->value,
        'parent' => $page->get('parent')->target_id,
        'weight' => (int) $page->get('weight')->value,
        'created' => (int) $page->get('created')->value,
      ];
    }
    $this->assertCount(3, $this->before);

    $this->runConversion();

    // The entity type is revisionable and the revision table exists.
    $this->assertTrue($this->entityType()->isRevisionable());
    $this->assertTrue(
      $this->container->get('database')->schema()->tableExists('petrichor_wiki_page_revision')
    );

    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
    foreach ($this->before as $id => $expected) {
      $page = $storage->load($id);
      $this->assertNotNull($page, "page $id survived the conversion");
      $this->assertSame($expected['label'], $page->label(), "page $id kept its title");
      $this->assertSame($expected['body'], $page->get('body')->value, "page $id kept its body");
      $this->assertSame($expected['parent'], $page->get('parent')->target_id, "page $id kept its parent");
      $this->assertSame($expected['weight'], (int) $page->get('weight')->value, "page $id kept its weight");
      $this->assertSame($expected['created'], (int) $page->get('created')->value, "page $id kept its creation date");

      $revisions = $storage->getQuery()->accessCheck(FALSE)->allRevisions()
        ->condition('id', $id)->execute();
      $this->assertCount(1, $revisions, "page $id has exactly one seed revision");
    }
  }

  /**
   * The seed revision is the page, not a blank row.
   *
   * A conversion that produced revision rows without the field values would
   * pass a row count and still have lost the history it claims to start.
   */
  public function testSeedRevisionCarriesTheContent(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
    $page = $storage->create([
      'label' => 'Runbook',
      'body' => ['value' => 'Step one.', 'format' => 'markdown'],
      'weight' => 4,
    ]);
    $page->save();
    $id = (int) $page->id();

    $this->runConversion();

    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
    $vids = array_keys($storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('id', $id)->execute());
    $this->assertCount(1, $vids);

    $revision = $storage->loadRevision(reset($vids));
    $this->assertSame('Runbook', $revision->label());
    $this->assertSame('Step one.', $revision->get('body')->value);
    $this->assertSame(4, (int) $revision->get('weight')->value);
    $this->assertTrue($revision->isDefaultRevision());
  }

  /**
   * Editing after the conversion forks, and the seed stays intact.
   *
   * The conversion is only worth anything if history accumulates afterwards.
   */
  public function testHistoryAccumulatesAfterConversion(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
    $page = $storage->create([
      'label' => 'Deploy notes',
      'body' => ['value' => 'Original.', 'format' => 'markdown'],
    ]);
    $page->save();
    $id = (int) $page->id();

    $this->runConversion();

    $storage = $this->container->get('entity_type.manager')->getStorage('petrichor_wiki_page');
    $page = $storage->load($id);
    $page->set('body', ['value' => 'Revised.', 'format' => 'markdown']);
    $page->setRevisionLogMessage('Second pass');
    $page->save();

    $vids = array_keys($storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('id', $id)->execute());
    $this->assertCount(2, $vids, 'the edit forked rather than overwrote');

    sort($vids);
    $seed = $storage->loadRevision($vids[0]);
    $this->assertSame('Original.', $seed->get('body')->value, 'the pre-conversion text is still readable');
    $latest = $storage->loadRevision($vids[1]);
    $this->assertSame('Revised.', $latest->get('body')->value);
    $this->assertSame('Second pass', $latest->getRevisionLogMessage());
  }

  /**
   * Running it twice refuses rather than copying revisions into revisions.
   */
  public function testConversionRefusesToRerun(): void {
    $this->runConversion();

    $this->expectException(UpdateException::class);
    $this->expectExceptionMessageMatches('/already exists/');
    $sandbox = [];
    petrichor_wiki_update_11001($sandbox);
  }

  /**
   * Runs the real update hook to completion, as update.php would.
   */
  protected function runConversion(): void {
    // Deploying the code: definitions come from petrichor_wiki again.
    $this->container->get('state')->set('petrichor_wiki_pre_revision.active', FALSE);
    $this->refreshDefinitions();

    // Resolved through the extension list rather than hardcoded. The path
    // depends on where the suite is checked out — 'modules/custom/petrichor'
    // on drupal.org's CI, but 'modules/custom/<sitename>' under the local
    // ddev-drupal-contrib harness, and anywhere at all in a real site. A
    // literal path passes on exactly one of those.
    $path = $this->container->get('extension.list.module')->getPath('petrichor_wiki');
    require_once $this->root . '/' . $path . '/petrichor_wiki.install';

    $sandbox = [];
    $passes = 0;
    do {
      petrichor_wiki_update_11001($sandbox);
      $passes++;
      $this->assertLessThan(50, $passes, 'the conversion batch terminated');
    } while (($sandbox['#finished'] ?? 1) < 1);

    $this->refreshDefinitions();
  }

  /**
   * Drops every cached copy of the entity definitions.
   */
  protected function refreshDefinitions(): void {
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
  }

  /**
   * The current entity type definition.
   */
  protected function entityType() {
    return $this->container->get('entity_type.manager')->getDefinition('petrichor_wiki_page');
  }

}
