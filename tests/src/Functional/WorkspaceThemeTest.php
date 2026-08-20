<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * The companion theme, actually installed and actually rendering a page.
 *
 * This exists because its absence cost twenty minutes of downtime. Moving the
 * theme inside the module package broke every authenticated request — the
 * theme was discovered but its PHP class could not be autoloaded, so the
 * container failed to build — and nothing caught it. The whole suite was
 * green, because nothing in it installed the theme and asked for a page.
 *
 * Everything else that touches the theme checks something adjacent and
 * cheaper: the negotiator has a kernel test that asserts which routes it
 * applies to, and discovery can be confirmed from the CLI. Both passed
 * throughout the outage. The only check that would have failed is this one —
 * install it, log in, request a page, look at the markup.
 *
 * So the assertions here are deliberately end-of-chain. They do not test the
 * negotiator, or discovery, or the settings form. They test that a person with
 * the theme switched on gets a workspace page rendered by it.
 *
 * @group petrichor
 */
class WorkspaceThemeTest extends BrowserTestBase {

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
   *
   * Stark is the *site* theme. petrichor_theme is installed below but never
   * made the default, because that is exactly how a site runs it: the setting
   * puts it in front of the workspace routes and leaves every other page
   * alone.
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['petrichor_theme']);
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));
  }

  /**
   * Switched on, the workspace routes render in the theme.
   *
   * The body classes are the observable proof that the theme's own preprocess
   * hooks ran. A 500 here is the outage this test was written for; missing
   * classes with a 200 would mean the theme rendered but its PHP did not load.
   */
  public function testWorkspaceRoutesRenderInTheTheme(): void {
    $this->config('petrichor.settings')->set('use_workspace_theme', TRUE)->save();

    $this->drupalGet('/pm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'body.petrichor-workspace.petrichor-section-pm');

    $this->drupalGet('/wiki');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'body.petrichor-workspace.petrichor-section-wiki');
  }

  /**
   * Ordinary pages are left alone.
   *
   * The promise the settings form makes in as many words: "nothing else on
   * your site changes". A theme that quietly repainted the admin would be a
   * worse bug than the one above, because nobody would file it.
   */
  public function testOrdinaryPagesAreUntouched(): void {
    $this->config('petrichor.settings')->set('use_workspace_theme', TRUE)->save();

    $this->drupalGet('/user');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'body.petrichor-workspace');
  }

  /**
   * Switched off — the default — the theme stays out of the way.
   *
   * Installed but not applied is the state most sites will be in, and it is
   * the one where a mistake is least visible: the theme is present, so nothing
   * errors, it simply takes over pages it was never asked to.
   */
  public function testSettingOffLeavesTheSiteThemeInPlace(): void {
    $this->config('petrichor.settings')->set('use_workspace_theme', FALSE)->save();

    $this->drupalGet('/pm');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'body.petrichor-workspace');
  }

  /**
   * The theme suppresses contextual links rather than hiding them.
   *
   * Half the reason the theme exists: the contextual pencil's handlers compete
   * with SortableJS for pointer events on the board. CSS cannot remove an
   * event handler, so the markup and the library both have to go — and a
   * regression here would look like "the board feels sticky", which nobody
   * would trace back to a theme hook.
   */
  public function testContextualLinksAreNotRendered(): void {
    $this->config('petrichor.settings')->set('use_workspace_theme', TRUE)->save();

    $this->drupalGet('/pm');

    $this->assertSession()->responseNotContains('contextual-links');
    $this->assertSession()->responseNotContains('contextual/drupal.contextual-links');
  }

}
