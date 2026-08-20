<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * One-click status buttons on a memory page.
 *
 * The review loop these serve is load-bearing: the default recording policy
 * stores an agent's memory as `proposed` and keeps it out of recall until a
 * human confirms it, which only works if confirming is cheap enough that
 * somebody actually does it.
 *
 * The tests that matter most are the refusals — a state change reachable by
 * GET is exactly the shape that gets abused, so the token requirement and the
 * maintainer-only archive permission are pinned harder than the happy path.
 *
 * @group petrichor
 */
class MemoryStatusActionsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'petrichor',
    'petrichor_projects',
    'petrichor_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A memory to act on.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $memory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create([
        'label' => 'Storage stays in core',
        'fact' => 'Decided during the rewrite.',
        'type' => 'decision',
        'status' => 'proposed',
      ]);
    $this->memory->save();
  }

  /**
   * A contributor sees Confirm on a proposed memory, and it works.
   */
  public function testConfirmMovesProposedToActive(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->drupalGet($this->memory->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Confirm');

    $this->clickLink('Confirm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Storage stays in core is now Active.');
    $this->assertSame('active', $this->reload()->get('status')->value);
  }

  /**
   * The button for the status it is already in is not offered.
   */
  public function testCurrentStatusHasNoButton(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->drupalGet($this->memory->toUrl('canonical'));

    // 'proposed' is the current status, so its own verb must be absent.
    $this->assertSession()->linkNotExists('Send back for review');
  }

  /**
   * Archive is maintainer-only, and absent for everyone else.
   *
   * MemoryService::forget() enforces this, and the button must agree with it
   * — offering a control that always fails is worse than offering none.
   */
  public function testArchiveHiddenWithoutMaintainerPermission(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->drupalGet($this->memory->toUrl('canonical'));

    $this->assertSession()->linkNotExists('Archive');
  }

  /**
   * A maintainer gets Archive, and it archives rather than deleting.
   */
  public function testMaintainerCanArchive(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
      'archive petrichor memories',
    ]));

    $this->drupalGet($this->memory->toUrl('canonical'));
    $this->clickLink('Archive');

    $this->assertSession()->pageTextContains('Storage stays in core is now Archived.');
    $reloaded = $this->reload();
    $this->assertNotNull($reloaded, 'Archiving never deletes the row.');
    $this->assertSame('archived', $reloaded->get('status')->value);
  }

  /**
   * Without a CSRF token the transition is refused.
   *
   * The route accepts GET, so an untokened URL is reachable from any image
   * tag on any page a maintainer happens to visit. This is the test that
   * makes that a non-event.
   */
  public function testUntokenedRequestIsRejected(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'view petrichor content',
      'manage petrichor content',
    ]));

    $this->drupalGet('/pm/memory/' . $this->memory->id() . '/status/active');

    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame('proposed', $this->reload()->get('status')->value, 'Status is untouched.');
  }

  /**
   * A reader with no edit rights gets no buttons at all.
   */
  public function testReadOnlyUserSeesNoButtons(): void {
    $this->drupalLogin($this->drupalCreateUser(['view petrichor content']));

    $this->drupalGet($this->memory->toUrl('canonical'));

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Confirm');
    $this->assertSession()->linkNotExists('Archive');
  }

  /**
   * The memory, freshly loaded from storage.
   */
  protected function reload() {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory');
    $storage->resetCache([$this->memory->id()]);
    return $storage->load($this->memory->id());
  }

}
