<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_board\Kernel;

use Drupal\Core\Plugin\Component;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The four board components and the library they generate.
 *
 * Two failure modes this covers are silent in the browser. Prop-schema
 * validation runs inside assert(), so with zend.assertions=-1 a malformed
 * component renders with garbage props and no error; the type checks below run
 * outside assert() and fail on any build. And a component with no
 * libraryOverrides generates a library with zero dependencies, so the board
 * renders, nothing drags, and a ReferenceError in the console is the only clue.
 *
 * @group petrichor
 */
#[RunTestsInSeparateProcesses]
class BoardComponentsTest extends KernelTestBase {

  /**
   * Every component this module ships, by plugin id.
   */
  protected const COMPONENTS = [
    'petrichor_board:board',
    'petrichor_board:column',
    'petrichor_board:card',
    'petrichor_board:today',
  ];

  /**
   * The dependencies the board's drag behaviour needs at runtime.
   */
  protected const BOARD_LIBRARY_DEPENDENCIES = [
    'core/drupal',
    'core/once',
    'core/sortable',
    'core/drupal.announce',
  ];

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
    'petrichor_tasks',
    'petrichor_board',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The component plugin manager and the library discovery parser both go
    // through the active theme, which a kernel test does not otherwise have.
    $this->container->get('theme_installer')->install(['stark']);
    $this->installConfig('system');
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')
      ->setActiveTheme($this->container->get('theme.initialization')->initTheme('stark'));
  }

  /**
   * Every component resolves to a plugin with a template and a prop schema.
   *
   * Instantiation is the check: ComponentMetadata::parseSchemaInfo() throws for
   * a module component with no props block, and Component::__construct() throws
   * when the Twig template is missing — which is what a folder name that
   * disagrees with the YAML basename looks like from here.
   */
  public function testEveryComponentInstantiates(): void {
    $manager = $this->container->get('plugin.manager.sdc');
    foreach (self::COMPONENTS as $id) {
      $component = $manager->createInstance($id);
      $this->assertInstanceOf(Component::class, $component, "$id: instantiates");
      $this->assertTrue($component->metadata->mandatorySchemas, "$id: schemas are mandatory for module components");
      $this->assertFileExists($component->getTemplatePath(), "$id: template resolves");
    }
  }

  /**
   * Every top-level prop declares an explicit type.
   *
   * ParseSchemaInfo() appends 'object' to whatever type it finds, so a prop
   * with no type at all comes back as ['', 'object'] rather than failing. That
   * empty string is the tell, and it is the shape the contract forbids.
   */
  public function testEveryPropDeclaresAnExplicitType(): void {
    $manager = $this->container->get('plugin.manager.sdc');
    foreach (self::COMPONENTS as $id) {
      $schema = $manager->createInstance($id)->metadata->schema;
      $this->assertNotEmpty($schema['properties'] ?? [], "$id: declares props");
      foreach ($schema['properties'] as $name => $prop) {
        $this->assertNotContains('', (array) $prop['type'], "$id: prop '$name' declares an explicit type");
      }
    }
  }

  /**
   * The board's generated library declares the four runtime dependencies.
   *
   * The library name is auto-computed from the plugin id — colons become
   * double dashes and core owns every component library.
   */
  public function testBoardLibraryDeclaresItsDependencies(): void {
    $library = $this->container->get('library.discovery')
      ->getLibraryByName('core', 'components.petrichor_board--board');

    $this->assertIsArray($library, 'core/components.petrichor_board--board exists');
    foreach (self::BOARD_LIBRARY_DEPENDENCIES as $dependency) {
      $this->assertContains($dependency, $library['dependencies'] ?? [], "board library depends on $dependency");
    }
  }

  /**
   * The board library still carries board.js alongside those dependencies.
   *
   * LibraryOverrides merges with array_merge(), so a stray 'js' key in the
   * overrides replaces the auto-discovered board.js entry wholesale — leaving a
   * library that pulls in Sortable and then has nothing to run.
   */
  public function testBoardLibraryShipsBoardJs(): void {
    $library = $this->container->get('library.discovery')
      ->getLibraryByName('core', 'components.petrichor_board--board');

    $paths = array_column($library['js'] ?? [], 'data');
    $this->assertNotEmpty(
      preg_grep('#/petrichor_board/components/board/board\.js$#', $paths),
      'board.js is attached to the board library',
    );
  }

  /**
   * Only the board component ships JavaScript.
   *
   * The budget is the point: the suite is meant to carry one JS file, and an
   * accidental card.js or today.js would be invisible until someone counted.
   */
  public function testNoOtherComponentShipsJavaScript(): void {
    $manager = $this->container->get('plugin.manager.sdc');
    foreach (self::COMPONENTS as $id) {
      $js = $manager->createInstance($id)->library['js'] ?? [];
      if ($id === 'petrichor_board:board') {
        $this->assertNotEmpty($js, 'board ships board.js');
        continue;
      }
      $this->assertSame([], $js, "$id: ships no JavaScript");
    }
  }

}
