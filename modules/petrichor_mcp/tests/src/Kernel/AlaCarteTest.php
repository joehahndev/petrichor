<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The MCP server with nothing but projects underneath it.
 *
 * The suite advertises an à-la-carte install, and the code was written for it:
 * ContextService takes comments, memory and tasks as optional '@?' arguments,
 * and McpResources checks hasDefinition() before it touches the wiki. None of
 * that was reachable, because petrichor_mcp listed petrichor_tasks and
 * petrichor_wiki as hard dependencies — so installing the server installed them
 * too, and the graceful branches had never once run.
 *
 * This is the minimum viable install: a project, and a server to talk about it.
 * If it breaks, the à-la-carte claim is false again and the README is lying.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class AlaCarteTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The minimum install: a project, and a server to talk about it.
   *
   * Deliberately no petrichor_tasks, petrichor_wiki, petrichor_memory or
   * petrichor_comments — that absence is the test.
   *
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
    'petrichor_mcp',
  ];

  /**
   * The project everything is scoped to.
   *
   * @var int
   */
  protected int $projectId;

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

    $project = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_project')
      ->create(['label' => 'Solo', 'status' => 'open']);
    $project->save();
    $this->projectId = (int) $project->id();
  }

  /**
   * The optional services really are absent, not quietly pulled in.
   *
   * Asserted first: every other assertion here is meaningless if the module
   * list dragged tasks or the wiki back in.
   */
  public function testTheOptionalModulesAreNotInstalled(): void {
    $handler = $this->container->get('module_handler');
    foreach (['petrichor_tasks', 'petrichor_wiki', 'petrichor_memory', 'petrichor_comments'] as $module) {
      $this->assertFalse($handler->moduleExists($module), "$module should not be installed.");
    }
    $entity_types = $this->container->get('entity_type.manager');
    $this->assertFalse($entity_types->hasDefinition('petrichor_task'));
    $this->assertFalse($entity_types->hasDefinition('petrichor_wiki_page'));
  }

  /**
   * The container builds and the optional arguments resolve to NULL.
   */
  public function testTheContextServiceStillBuilds(): void {
    $this->assertNotNull($this->container->get('petrichor_mcp.context'));
    $this->assertNotNull($this->container->get('petrichor_mcp.tool_registry'));
    $this->assertNotNull($this->container->get('petrichor_mcp.resources'));
  }

  /**
   * A context bundle degrades to the sections that exist.
   */
  public function testProjectContextReturnsWhatItCan(): void {
    $context = $this->container->get('petrichor_mcp.context')
      ->getProjectContext($this->projectId);

    $this->assertSame('Solo', $context['project']['title']);
    // Sections whose modules are absent are omitted, not empty or errored.
    $this->assertArrayNotHasKey('open_tasks', $context);
    $this->assertArrayNotHasKey('wiki_pages', $context);
    $this->assertArrayNotHasKey('memories', $context);
    $this->assertArrayNotHasKey('recent_worklog', $context);
  }

  /**
   * The my-work tool says why it is empty rather than erroring.
   */
  public function testMyWorkExplainsItself(): void {
    $result = $this->container->get('petrichor_mcp.context')->getMyWork();
    $this->assertArrayHasKey('note', $result);
    $this->assertStringContainsString('not enabled', $result['note']);
  }

  /**
   * Wiki resources are an empty list, not a fatal.
   */
  public function testWikiResourcesAreEmptyWithoutTheWiki(): void {
    $resources = $this->container->get('petrichor_mcp.resources');
    $this->assertSame([], $resources->listResources());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/not enabled/');
    $resources->readResource('petrichor://wiki/1');
  }

  /**
   * The tool surface is exactly what is installed.
   *
   * This is the property the whole packaging rule exists to deliver: an agent
   * connecting to a minimal install is told about project and context tools and
   * never offered a task tool that would fail.
   */
  public function testTheToolListMatchesTheInstall(): void {
    $names = array_column($this->container->get('petrichor_mcp.tool_registry')->getDefinitions(), 'name');

    foreach (['list_projects', 'get_project', 'get_project_context', 'get_my_work'] as $expected) {
      $this->assertContains($expected, $names);
    }
    foreach (['list_tasks', 'get_task', 'search_wiki', 'remember', 'add_comment'] as $absent) {
      $this->assertNotContains($absent, $names);
    }
  }

  /**
   * Every advertised tool dispatches to a provider that owns it.
   *
   * A definition nothing answers to is worse than a missing tool: the agent
   * believes the list, calls it, and gets back an error it cannot act on.
   *
   * Called with no arguments, so most of these fail on validation — that is
   * fine and is the point. The only failure this rejects is "Unknown tool",
   * which is the registry saying no provider claimed the name it advertised.
   */
  public function testEveryListedToolDispatches(): void {
    $registry = $this->container->get('petrichor_mcp.tool_registry');
    $names = array_column($registry->getDefinitions(), 'name');
    $this->assertNotEmpty($names);

    foreach ($names as $name) {
      try {
        $registry->call($name, []);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringNotContainsString(
          'Unknown tool',
          $e->getMessage(),
          "The registry advertises $name but nothing handles it.",
        );
      }
    }
  }

}
