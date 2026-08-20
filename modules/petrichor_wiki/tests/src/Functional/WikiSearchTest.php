<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The human half of search_wiki: a box on /wiki, and a results page.
 *
 * The search_wiki MCP tool has existed since the wiki module shipped, with
 * no human-facing equivalent — a reader at /wiki could browse the tree or check
 * "wanted pages", never search by content. This is that gap closed.
 *
 * @group petrichor
 */
class WikiSearchTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_markdown',
    'petrichor_wiki',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
   * The index carries a search box, not just the tree.
   */
  public function testIndexHasSearchBox(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/wiki');

    $this->assertSession()->elementExists('css', 'input[name="q"]');
    $this->assertSession()->elementExists('css', 'form[action*="/wiki/search"]');
  }

  /**
   * Landing on /wiki/search with nothing typed is the empty box, not an error.
   *
   * WikiService::searchWiki() throws on an empty keyword — that is correct
   * for an MCP tool call that should never happen, and wrong for a page a
   * human can simply click into.
   */
  public function testEmptyQueryShowsTheFormNotAnError(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/wiki/search');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'input[name="q"]');
  }

  /**
   * A real query finds the page and shows a snippet from its body.
   */
  public function testMatchingQueryReturnsPageWithSnippet(): void {
    $this->page('Deploy Runbook', 'Run the litestream restore script before anything else.');
    $this->page('Unrelated Page', 'Nothing to do with deployment.');

    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/wiki/search', ['query' => ['q' => 'litestream']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Deploy Runbook');
    $this->assertSession()->pageTextContains('litestream restore script');
    $this->assertSession()->pageTextNotContains('Unrelated Page');
  }

  /**
   * A query matching nothing says so, rather than showing a blank list.
   */
  public function testNoMatchesSaysSoByName(): void {
    $this->page('Deploy Runbook', 'Run the litestream restore script.');

    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/wiki/search', ['query' => ['q' => 'nonexistentxyz']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Nothing matched "nonexistentxyz"');
  }

  /**
   * The box on the results page keeps the query, so refining means editing.
   */
  public function testResultsPagePrefillsTheQuery(): void {
    $this->page('Deploy Runbook', 'Run the litestream restore script.');

    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));
    $this->drupalGet('/wiki/search', ['query' => ['q' => 'litestream']]);

    $this->assertSession()->fieldValueEquals('q', 'litestream');
  }

  /**
   * Reading the wiki, including search, requires being logged in.
   */
  public function testAnonymousIsRefused(): void {
    $this->drupalGet('/wiki/search');
    $this->assertSession()->statusCodeEquals(403);
  }

}
