<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_memory\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The status action row, rendered.
 *
 * The functional tests prove the buttons work end to end; this one exists to
 * make a failure legible when they don't. A 500 in a BrowserTestBase run says
 * only "500", and the exception behind it never reaches the test output.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class MemoryStatusRenderTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_memory');
    $this->installSchema('petrichor_memory', ['petrichor_memory_recall']);
    $this->installConfig(['system', 'filter', 'petrichor']);

    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();

    $this->createUser([], 'uid1-superuser');
    $this->setCurrentUser($this->createUser([
      'view petrichor content',
      'manage petrichor content',
      'archive petrichor memories',
    ]));
  }

  /**
   * A proposed memory renders Confirm and Archive, and no Proposed button.
   */
  public function testActionRowRenders(): void {
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create([
        'label' => 'Storage stays in core',
        'fact' => 'Decided during the rewrite.',
        'type' => 'decision',
        'status' => 'proposed',
      ]);
    $memory->save();

    // The extra field has to exist before anything can render it, and it
    // failing here means the hook never ran — a different bug from one that
    // renders nothing.
    $extra = $this->container->get('entity_field.manager')
      ->getExtraFields('petrichor_memory', 'petrichor_memory');
    $this->assertArrayHasKey('petrichor_memory_status_actions', $extra['display'] ?? []);

    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder('petrichor_memory')
      ->view($memory, 'full');
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Confirm', $html);
    $this->assertStringContainsString('Archive', $html);
    $this->assertStringContainsString('token=', $html, 'Every action link is CSRF-tokened.');
    $this->assertStringNotContainsString('Send back for review', $html, 'No button for the status it is already in.');
  }

}
