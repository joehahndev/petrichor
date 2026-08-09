<?php

declare(strict_types=1);

namespace Drupal\Tests\petrichor_tags\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\petrichor_tags\TagResolver;
use Drupal\taxonomy\Entity\Term;

/**
 * The single translation boundary between tag names and term ids.
 *
 * Every write path (remember, create/update task, create/update wiki page,
 * and the install-time migration) goes through resolveNames() rather than
 * creating terms itself, so its dedup/reuse rules are what actually decide
 * whether "Forms" and "forms" typed on two different entities land on one
 * term or two.
 *
 * @group petrichor
 */
class TagResolverTest extends KernelTestBase {

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
    'taxonomy',
    'petrichor',
    'petrichor_projects',
    'petrichor_memory',
    'petrichor_tags',
  ];

  /**
   * The resolver under test.
   *
   * @var \Drupal\petrichor_tags\TagResolver
   */
  protected $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('petrichor_project');
    $this->installEntitySchema('petrichor_memory');
    $this->installConfig(['system', 'filter', 'petrichor', 'petrichor_tags']);
    $this->resolver = $this->container->get('petrichor_tags.resolver');
  }

  /**
   * A name with no existing term creates one.
   */
  public function testResolveNamesCreatesNewTerms(): void {
    $ids = $this->resolver->resolveNames(['Forms']);

    $this->assertCount(1, $ids);
    $term = Term::load($ids[0]);
    $this->assertSame('Forms', $term->label());
    $this->assertSame(TagResolver::VOCABULARY, $term->bundle());
  }

  /**
   * A second call with a different case reuses the term, not a duplicate.
   *
   * This is what keeps "Forms" typed on a task and "forms" typed on a memory
   * pointing at the same term — the whole point of a shared vocabulary.
   */
  public function testResolveNamesReusesExistingTermCaseInsensitively(): void {
    $first = $this->resolver->resolveNames(['Forms']);
    $second = $this->resolver->resolveNames(['forms']);

    $this->assertSame($first, $second);
    $this->assertCount(1, \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->getQuery()->accessCheck(FALSE)->condition('vid', TagResolver::VOCABULARY)->execute());
  }

  /**
   * Blank names and surrounding whitespace do not become tags.
   */
  public function testResolveNamesFiltersBlankAndTrimsWhitespace(): void {
    $ids = $this->resolver->resolveNames([' Forms ', '', '   ', 'Forms']);

    $this->assertCount(1, $ids);
    $this->assertSame('Forms', Term::load($ids[0])->label());
  }

  /**
   * An empty input list resolves to no ids without querying anything.
   */
  public function testResolveNamesWithNoNamesReturnsEmpty(): void {
    $this->assertSame([], $this->resolver->resolveNames([]));
    $this->assertSame([], $this->resolver->resolveNames(['', '   ']));
  }

  /**
   * The same name repeated in one call resolves to one id, not a duplicate.
   */
  public function testResolveNamesDeduplicatesWithinOneCall(): void {
    $ids = $this->resolver->resolveNames(['Forms', 'forms', 'FORMS']);

    $this->assertCount(1, $ids);
  }

  /**
   * A substring match finds a term; an unrelated keyword finds nothing.
   */
  public function testIdsMatchingDoesSubstringSearch(): void {
    $ids = $this->resolver->resolveNames(['Accessibility']);

    $this->assertSame($ids, $this->resolver->idsMatching('access'));
    $this->assertSame([], $this->resolver->idsMatching('nothing like this'));
  }

  /**
   * A blank keyword matches nothing rather than every term.
   */
  public function testIdsMatchingWithBlankKeywordReturnsEmpty(): void {
    $this->resolver->resolveNames(['Accessibility']);

    $this->assertSame([], $this->resolver->idsMatching(''));
    $this->assertSame([], $this->resolver->idsMatching('   '));
  }

  /**
   * An entity with no tags set reports no names.
   */
  public function testNamesForWithNoTagsIsEmpty(): void {
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create(['label' => 'A fact', 'fact' => 'A fact', 'status' => 'active']);
    $memory->save();

    $this->assertSame([], $this->resolver->namesFor($memory));
  }

  /**
   * An entity's tags come back as names, in the order they were set.
   */
  public function testNamesForReturnsResolvedNames(): void {
    $ids = $this->resolver->resolveNames(['Forms', 'Accessibility']);
    $memory = $this->container->get('entity_type.manager')
      ->getStorage('petrichor_memory')
      ->create([
        'label' => 'A fact',
        'fact' => 'A fact',
        'status' => 'active',
        'tags' => $ids,
      ]);
    $memory->save();

    $this->assertSame(['Forms', 'Accessibility'], $this->resolver->namesFor($memory));
  }

}
