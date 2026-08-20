<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_wiki\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Old /kb links still arrive somewhere.
 *
 * The wiki moved from /kb to /wiki when the feature stopped having two names.
 * Every machine name was already "wiki"; the URL was the last thing calling it
 * a knowledge base. Renaming a path is the one part of that which breaks
 * something a person can notice — a bookmark, a link in a chat log, or a /kb
 * link typed by hand into a wiki page body long before the rename.
 *
 * @group petrichor
 */
class LegacyKbPathTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
  }

  /**
   * The index redirects, and lands on the real page rather than a 404.
   */
  public function testIndexRedirects(): void {
    $this->drupalGet('/kb');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/wiki');
  }

  /**
   * A page URL redirects, carrying its id.
   */
  public function testPageRedirects(): void {
    $page = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_wiki_page')
      ->create(['label' => 'Herd on Windows', 'body' => 'first draft']);
    $page->save();

    $this->drupalGet('/kb/' . $page->id());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/wiki/' . $page->id());
    $this->assertSession()->pageTextContains('Herd on Windows');
  }

  /**
   * The query string survives, which is what a bookmarked search is made of.
   */
  public function testSearchRedirectKeepsQuery(): void {
    $this->drupalGet('/kb/search', ['query' => ['q' => 'litestream']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/wiki/search?q=litestream');
  }

  /**
   * A path merely starting with those letters is not touched.
   *
   * The rule matches /kb and /kb/... only. Without the boundary it would also
   * swallow something like /kbase, which has nothing to do with this module.
   */
  public function testUnrelatedPathIsNotRedirected(): void {
    $this->drupalGet('/kbase-not-ours');

    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->addressEquals('/kbase-not-ours');
  }

}
