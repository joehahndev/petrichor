<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_projects\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Finding a project by name.
 *
 * Everything project-scoped — context bundles, memory recall, task lists —
 * needs an id, and until this filter existed there was no way to get one from
 * a name. An agent opening an unfamiliar repository had to list every project
 * and guess.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class ProjectQueryTest extends KernelTestBase {

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
  ];

  /**
   * The project service under test.
   *
   * @var \Drupal\petrichor_projects\ProjectService
   */
  protected $projects;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('petrichor_project');
    $this->installConfig(['system', 'filter']);

    $this->createUser([], 'uid1-superuser');
    $this->setUpCurrentUser([], ['view petrichor content', 'manage petrichor content']);
    $this->projects = $this->container->get('petrichor_projects.service');
  }

  /**
   * Creates a project and returns its id.
   */
  protected function project(string $title, ?string $client = NULL, string $status = 'open'): int {
    return (int) $this->projects->createProject([
      'title' => $title,
      'client' => $client,
      'status' => $status,
    ])['id'];
  }

  /**
   * A substring of the title finds the project.
   */
  public function testQueryMatchesOnTitle(): void {
    $id = $this->project('Petrichor Drupal PM');
    $this->project('Something else');

    $result = $this->projects->listProjects(['query' => 'drupal']);
    $this->assertSame([$id], array_column($result['items'], 'id'));
    $this->assertSame(1, $result['total']);
  }

  /**
   * The client name matches too — a repo is as often named for the client.
   */
  public function testQueryMatchesOnClient(): void {
    $id = $this->project('Website rebuild', 'Numpad Labs');
    $this->project('Something else', 'Another client');

    $result = $this->projects->listProjects(['query' => 'numpad']);
    $this->assertSame([$id], array_column($result['items'], 'id'));
  }

  /**
   * No match returns an empty list, not everything.
   */
  public function testNonMatchingQueryReturnsNothing(): void {
    $this->project('Petrichor Drupal PM');
    $result = $this->projects->listProjects(['query' => 'nothing like this']);

    $this->assertSame([], $result['items']);
    $this->assertSame(0, $result['total']);
  }

  /**
   * A literal '%' is escaped, not treated as "match everything".
   *
   * Without escapeLike this returns the whole table, and a caller reading the
   * result has no way to tell that from a genuine list of all their projects.
   */
  public function testWildcardCharactersAreEscaped(): void {
    $this->project('Petrichor Drupal PM');
    $this->project('Something else');

    $this->assertSame([], $this->projects->listProjects(['query' => '%'])['items']);
  }

  /**
   * The query filter composes with the status filter rather than replacing it.
   */
  public function testQueryCombinesWithStatus(): void {
    $open = $this->project('Petrichor open');
    $this->project('Petrichor closed', NULL, 'closed');

    $result = $this->projects->listProjects(['query' => 'petrichor', 'status' => 'open']);
    $this->assertSame([$open], array_column($result['items'], 'id'));
  }

  /**
   * An absent or empty query still lists everything.
   */
  public function testEmptyQueryListsEverything(): void {
    $this->project('One');
    $this->project('Two');

    $this->assertSame(2, $this->projects->listProjects()['total']);
    $this->assertSame(2, $this->projects->listProjects(['query' => ''])['total']);
  }

}
