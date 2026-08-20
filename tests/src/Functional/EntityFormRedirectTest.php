<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Saving an entity lands somewhere that exists.
 *
 * The shared entity form redirects to the saved entity's collection, which is
 * right for five of the six entity types in this suite and absent on the
 * sixth. petrichor_memory ships no collection link template on purpose —
 * omitting it is what leaves /pm/memories free for the filterable View to
 * claim — so every memory save, including a bare status change, raised
 * UndefinedLinkTemplateException and showed the site's generic error page.
 *
 * A form save is the least interesting code path in the suite and the one a
 * person hits most, which is exactly why it went unnoticed: nothing else in
 * the suite reaches this line, MCP writes never touch a form at all, and the
 * kernel tests that cover memory behaviour build entities directly. It needs
 * a real form submission through a real browser to fail, so that is what this
 * does.
 *
 * @group petrichor
 */
class EntityFormRedirectTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_tasks',
    'petrichor_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An account that may create and edit Petrichor content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->editor = $this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]);
    $this->drupalLogin($this->editor);
  }

  /**
   * Creating a memory through the form saves and redirects, without erroring.
   */
  public function testCreatingMemoryRedirectsWithoutError(): void {
    $this->drupalGet('/pm/memory/add');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'label[0][value]' => 'Client hates multi-step forms',
      'fact[0][value]' => 'Confirmed on the call of 12 August.',
    ], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');
    $this->assertSession()->pageTextContains('Created memory Client hates multi-step forms.');
  }

  /**
   * Editing an existing memory does too — the reported symptom.
   *
   * Separate from creation because the report was about changing a saved
   * memory's status, and an add form that works proves nothing about an edit
   * form that redirects the same broken way.
   */
  public function testEditingMemoryRedirectsWithoutError(): void {
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create([
        'label' => 'Storage stays in core',
        'fact' => 'Decided during the rewrite.',
        'type' => 'decision',
        'status' => 'active',
      ]);
    $memory->save();

    $this->drupalGet('/pm/memory/' . $memory->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['status' => 'archived'], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');
    $this->assertSession()->pageTextContains('Updated memory Storage stays in core.');
  }

  /**
   * An entity type that does have a collection still lands on it.
   *
   * The fix is a fallback, not a replacement: five entity types should be
   * unaffected, and a change that quietly sent every save to the entity's own
   * page instead of its list would be a regression nobody asked for.
   *
   * Run as an administrator, and that is not incidental. Collection routes
   * come from AdminHtmlRouteProvider and inherit the entity type's
   * admin_permission, so /pm/projects needs `administer petrichor` — meaning
   * a user holding only `manage petrichor content` saves a project
   * successfully and is then redirected straight into a 403. That is a real
   * gap, separate from the one this class was written for, and it is left
   * alone here rather than papered over: this test's job is to prove the
   * fallback did not change where entities with a collection land.
   */
  public function testEntityWithCollectionStillRedirectsThere(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
      'administer petrichor',
    ]));

    $this->drupalGet('/pm/project/add');
    $this->submitForm(['label[0][value]' => 'Acme redesign'], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->addressEquals('/pm/projects');
  }

}
