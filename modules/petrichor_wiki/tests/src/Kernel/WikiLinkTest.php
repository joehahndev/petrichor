<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\petrichor_wiki\BacklinkStorage;
use Drupal\petrichor_wiki\WikiLinkService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers `[[Page Title]]`: rendering, safety, indexing, and what a rename does.
 *
 * The load-bearing tests here are the ones that defend decisions rather than
 * behaviour:
 *
 * - testCodeSpansAndFencesAreNotLinked() is why extraction goes through the
 *   CommonMark tokenizer instead of preg_match_all. The first page anyone
 *   writes about this feature will contain a fenced `[[example]]`, and a regex
 *   indexer would record a backlink the page does not display.
 * - testCreatingThePageResolvesWantedLinks() is the cache-tag contract. If it
 *   fails, red links stay red until an unrelated cache clear and the wiki looks
 *   broken in the one moment it should feel alive.
 * - testRenameKeepsInboundLinksFindable() is the price of storing titles rather
 *   than ids. The rename is *allowed* to orphan inbound links; it is not
 *   allowed to lose them.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class WikiLinkTest extends KernelTestBase {

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
   * The service under test: parsing, resolution and indexing.
   *
   * @var \Drupal\petrichor_wiki\WikiLinkService
   */
  protected WikiLinkService $links;

  /**
   * The two link tables, read directly to assert what indexing wrote.
   *
   * @var \Drupal\petrichor_wiki\BacklinkStorage
   */
  protected BacklinkStorage $backlinks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_wiki_page');
    $this->installConfig(['filter', 'petrichor_markdown']);

    // Created from BacklinkStorage::schema() rather than installSchema() so
    // this suite does not depend on the install file, which another change is
    // reworking. Swap for installSchema('petrichor_wiki', [...]) once
    // petrichor_wiki_schema() is wired — that would also cover the wiring.
    $schema = $this->container->get('database')->schema();
    foreach (BacklinkStorage::schema() as $name => $table) {
      if (!$schema->tableExists($name)) {
        $schema->createTable($name, $table);
      }
    }
    // urlFor() generates real routes.
    $this->container->get('router.builder')->rebuild();

    $this->links = $this->container->get('petrichor_wiki.link_service');
    $this->backlinks = $this->container->get('petrichor_wiki.backlink_storage');
  }

  /**
   * A link to an existing page becomes an anchor to it.
   */
  public function testResolvedLinkPointsAtThePage(): void {
    $target = $this->page('Deploy Runbook');
    $html = $this->renderMarkdown('See [[Deploy Runbook]] first.');

    $this->assertStringContainsString('class="petrichor-wikilink"', $html);
    $this->assertStringContainsString('/wiki/' . $target->id(), $html);
    $this->assertStringContainsString('>Deploy Runbook</a>', $html);
    $this->assertStringNotContainsString('petrichor-wikilink--wanted', $html);
  }

  /**
   * Case and whitespace do not decide whether a link resolves.
   *
   * `=` on a varchar is case-sensitive on SQLite and case-insensitive on MySQL,
   * which is the entire reason the normalized title table exists.
   */
  public function testResolutionIsCaseAndWhitespaceInsensitive(): void {
    $this->page('Deploy Runbook');
    $html = $this->renderMarkdown('[[  deploy   RUNBOOK  ]]');
    $this->assertStringNotContainsString('petrichor-wikilink--wanted', $html);
    // The anchor text is what the author typed, not the target's label.
    $this->assertStringContainsString('>deploy   RUNBOOK</a>', $html);
  }

  /**
   * A link to a page nobody has written is a to-do, not an error.
   */
  public function testWantedLinkRendersDistinctlyAndStillCounts(): void {
    $source = $this->page('Onboarding', 'Read [[Deploy Runbook]] next.');
    $html = $this->renderMarkdown('Read [[Deploy Runbook]] next.');

    $this->assertStringContainsString('petrichor-wikilink--wanted', $html);
    $this->assertStringContainsString('data-wanted="true"', $html);
    // It points at the add form, so the red link is how the page gets written.
    $this->assertStringContainsString('/wiki/add', $html);

    $key = WikiLinkService::titleKey('Deploy Runbook');
    $this->assertSame(
      [['id' => (int) $source->id(), 'occurrences' => 1]],
      $this->backlinks->backlinksFor($key),
      'An unwritten page you have already linked to is recorded as a backlink.',
    );
    $wanted = $this->links->listWantedPages();
    $this->assertSame('Deploy Runbook', $wanted[0]['title']);
    $this->assertSame(1, $wanted[0]['links']);
  }

  /**
   * Creating the page flips every wanted link to it.
   *
   * Two halves: the tag must be emitted while the link is wanted, and creating
   * the page must invalidate that exact tag.
   */
  public function testCreatingThePageResolvesWantedLinks(): void {
    $this->page('Onboarding', 'Read [[Deploy Runbook]] next.');
    $tag = WikiLinkService::titleCacheTag(WikiLinkService::titleKey('Deploy Runbook'));

    $result = $this->process('Read [[Deploy Runbook]] next.');
    $this->assertContains($tag, $result->getCacheTags(), 'A wanted link still emits its title tag — that is what makes it flip later.');
    $this->assertSame(
      ['library' => ['petrichor_wiki/wikilink']],
      $result->getAttachments(),
      'The CSS rides along with the filter result, since the Markdown format is shared with non-wiki bodies.',
    );

    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum([$tag]);
    $this->page('Deploy Runbook');
    $this->assertNotSame($before, $checksum->getCurrentChecksum([$tag]), 'Creating the page must invalidate the title tag.');

    $this->assertStringNotContainsString('petrichor-wikilink--wanted', $this->renderMarkdown('Read [[Deploy Runbook]] next.'));
  }

  /**
   * Code spans and fenced blocks are neither linked nor indexed.
   */
  public function testCodeSpansAndFencesAreNotLinked(): void {
    $body = "Type `[[Page Title]]` to link.\n\n```\n[[Also Not A Link]]\n```\n\nBut [[This One]] is.";
    $source = $this->page('Syntax', $body);
    $html = $this->renderMarkdown($body);

    $this->assertSame(1, substr_count($html, '<a '), 'Exactly one of the three occurrences is a link.');
    $this->assertStringContainsString('[[Page Title]]', strip_tags($html), 'The code span renders its literal text.');
    $this->assertStringContainsString('[[Also Not A Link]]', strip_tags($html), 'So does the fenced block.');
    $this->assertStringContainsString('>This One</a>', $html);

    $indexed = array_column($this->backlinks->outboundLinks((int) $source->id()), 'text');
    $this->assertSame(['This One'], $indexed, 'The index must agree with what the page displays.');
  }

  /**
   * Titles are user content and never reach the output unescaped.
   */
  public function testTitleIsEscapedInBothTextAndHref(): void {
    $evil = 'Evil "><script>alert(1)</script>';
    $html = $this->renderMarkdown('[[' . $evil . ']]');

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
    $this->assertStringContainsString('&quot;', $html);
  }

  /**
   * Renaming re-points inbound links, but never loses them.
   *
   * Storing the title rather than the id is what makes a rename free of any
   * cross-page sweep; the cost is that inbound links follow the *name*. That is
   * correct wiki semantics only as long as it stays visible, so the rows
   * survive and the old title still lists them.
   */
  public function testRenameKeepsInboundLinksFindable(): void {
    $target = $this->page('Deploy Runbook');
    $source = $this->page('Onboarding', 'Read [[Deploy Runbook]] next.');

    $target->set('label', 'Release Runbook');
    $target->save();

    $this->assertStringContainsString(
      'petrichor-wikilink--wanted',
      $this->renderMarkdown('Read [[Deploy Runbook]] next.'),
      'The old title is now unwritten, so links to it go wanted.',
    );
    $this->assertSame(
      [['id' => (int) $source->id(), 'occurrences' => 1]],
      $this->links->getBacklinksByTitle('Deploy Runbook'),
      'The orphaned links are still findable under the old title — that is what stops the rename being silent.',
    );
    $this->assertStringNotContainsString(
      'petrichor-wikilink--wanted',
      $this->renderMarkdown('Read [[Release Runbook]] next.'),
      'A page that already used the new title lights up.',
    );
  }

  /**
   * Deleting a page turns inbound links back into to-dos.
   */
  public function testDeleteMakesInboundLinksWanted(): void {
    $target = $this->page('Deploy Runbook');
    $source = $this->page('Onboarding', 'Read [[Deploy Runbook]] next.');
    $target->delete();

    $this->assertStringContainsString('petrichor-wikilink--wanted', $this->renderMarkdown('Read [[Deploy Runbook]] next.'));
    $this->assertNotSame([], $this->links->getBacklinksByTitle('Deploy Runbook'));
    $this->assertSame([], $this->backlinks->outboundLinks((int) $target->id()), 'The deleted page stops being a source.');
    $this->assertNotSame([], $this->backlinks->outboundLinks((int) $source->id()));
  }

  /**
   * Two pages with one title: the oldest keeps the name.
   *
   * Resolving to the most recently changed page would let a link's destination
   * move under the reader because somebody edited a same-titled decoy.
   */
  public function testAmbiguousTitleResolvesToTheOldestPage(): void {
    $first = $this->page('Runbook');
    $second = $this->page('Runbook');
    $second->set('body', ['value' => 'touched', 'format' => 'markdown'])->save();

    $html = $this->renderMarkdown('[[Runbook]]');
    $this->assertStringContainsString('/wiki/' . $first->id(), $html);
    $this->assertStringNotContainsString('/wiki/' . $second->id(), $html);
    $this->assertStringContainsString('petrichor-wikilink--ambiguous', $html);

    $duplicates = $this->links->findDuplicateTitles();
    $this->assertSame([[(int) $first->id(), (int) $second->id()]], array_column($duplicates, 'ids'));
  }

  /**
   * An edit that changes no links leaves the link rows alone.
   *
   * A rename or a re-parent rewriting link rows would invalidate the backlink
   * cache of every target the page mentions, for nothing.
   */
  public function testNonBodyEditDoesNotRewriteLinkRows(): void {
    $source = $this->page('Onboarding', 'Read [[Deploy Runbook]] next.');
    $before = $this->backlinks->outboundLinks((int) $source->id());

    $source->set('weight', 5);
    $source->save();

    $this->assertSame($before, $this->backlinks->outboundLinks((int) $source->id()));
  }

  /**
   * The index is derived, so it must be reproducible from bodies alone.
   */
  public function testRebuildAllReproducesTheIndex(): void {
    $this->page('Deploy Runbook');
    $source = $this->page('Onboarding', 'Read [[Deploy Runbook]] and [[Nothing Yet]].');
    $expected = $this->backlinks->outboundLinks((int) $source->id());

    $this->backlinks->truncate();
    $this->assertSame([], $this->backlinks->outboundLinks((int) $source->id()));

    $this->assertSame(2, $this->links->rebuildAll());
    $this->assertSame($expected, $this->backlinks->outboundLinks((int) $source->id()));
    $this->assertStringNotContainsString('petrichor-wikilink--wanted', $this->renderMarkdown('[[Deploy Runbook]]'));
  }

  /**
   * The alias form links the target and shows only the display text.
   */
  public function testAliasFormLinksTheTargetNotTheDisplayText(): void {
    $target = $this->page('Deploy Runbook');
    $html = $this->renderMarkdown('[[Deploy Runbook|the runbook]]');

    $this->assertStringContainsString('/wiki/' . $target->id(), $html);
    $this->assertStringContainsString('>the runbook</a>', $html);
  }

  /**
   * Creates a saved wiki page.
   */
  protected function page(string $title, string $body = ''): object {
    $page = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_wiki_page')
      ->create([
        'label' => $title,
        'body' => ['value' => $body, 'format' => 'markdown'],
      ]);
    $page->save();
    return $page;
  }

  /**
   * Runs the Markdown filter and returns the full result.
   */
  protected function process(string $markdown): object {
    return $this->container->get('plugin.manager.filter')
      ->createInstance('petrichor_markdown')
      ->process($markdown, 'en');
  }

  /**
   * Runs the Markdown filter and returns the HTML.
   *
   * Not named render(): KernelTestBase::render(array &$elements) already owns
   * that name, and overriding it with a different signature is a fatal.
   */
  protected function renderMarkdown(string $markdown): string {
    return (string) $this->process($markdown)->getProcessedText();
  }

}
