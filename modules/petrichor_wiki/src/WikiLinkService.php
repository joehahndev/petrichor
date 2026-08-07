<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Url;
use Drupal\petrichor_markdown\MarkdownExtensionProviderInterface;
use Drupal\petrichor_wiki\CommonMark\WikiLinkExtension;
use Drupal\petrichor_wiki\CommonMark\WikiLinkNode;
use Drupal\petrichor_wiki\CommonMark\WikiLinkParser;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Parser\MarkdownParser;
use Psr\Log\LoggerInterface;

/**
 * Parsing, resolution, rendering metadata and indexing for `[[Page Title]]`.
 *
 * Everything about wiki links that is not a SQL statement lives here;
 * BacklinkStorage holds the two side tables. The service is also the
 * petrichor_markdown extension provider, so the syntax works in any body using
 * the shared Markdown format — task descriptions and worklog comments as much
 * as KB pages — and disappears cleanly, rendering as literal text, if
 * petrichor_wiki is uninstalled.
 */
final class WikiLinkService implements MarkdownExtensionProviderInterface {

  /**
   * Cache tag prefix for "does a page with this title exist".
   *
   * Emitted for every link seen, resolved or not — that is the entire
   * invalidation story: creating a page invalidates its title tag, and every
   * rendered body that carries a wanted link to that title re-renders with a
   * real link. Deliberately NOT petrichor_wiki_page:<id>, because nothing about
   * the target is displayed (the anchor text is what the author typed), and not
   * petrichor_wiki_page_list, which fires on every save and would make the KB's
   * render cache useless exactly as the KB starts being used.
   */
  public const TITLE_TAG_PREFIX = 'petrichor_wiki_title:';

  /**
   * Cache tag prefix for "which pages link to this title".
   */
  public const BACKLINK_TAG_PREFIX = 'petrichor_wiki_backlinks:';

  /**
   * Distinct link targets indexed per page.
   *
   * A body with more distinct targets than this is a generated index, not
   * prose; the cap stops one pathological page from writing an unbounded
   * number of rows on every save.
   */
  public const LINKS_PER_PAGE_CAP = 500;

  /**
   * Pages rebuilt per rebuildAll() batch.
   */
  private const REBUILD_BATCH = 50;

  /**
   * Parser used for extraction only — no renderer, no resolution.
   */
  private ?MarkdownParser $parser = NULL;

  /**
   * Cache tags accumulated by the current conversion, drained by the filter.
   *
   * @var array<string, true>
   */
  private array $tags = [];

  /**
   * Per-request resolution memo, keyed by normalized title.
   *
   * @var array<string, array|null>
   */
  private array $resolved = [];

  public function __construct(
    private readonly BacklinkStorage $storage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The one and only title normalizer.
   *
   * Every comparison in the wiki goes through it — indexer, resolver, title
   * table, rename check. A caller that skips it produces links that resolve on
   * MySQL and fail on SQLite, because `=` on a varchar is case-insensitive on
   * one and case-sensitive on the other.
   *
   * Static because the CommonMark parser has no container and must normalize
   * at parse time; there is no state to depend on.
   */
  public static function titleKey(string $title): string {
    $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($title)));
    // Clamp AFTER folding: mb_strtolower can lengthen a string (Turkish dotted
    // capital I becomes two codepoints), so a legal 255-character title can
    // overflow a 255-character column.
    return mb_substr($key, 0, 255);
  }

  /**
   * The cache tag standing for "a page titled X exists".
   *
   * Hashed because the raw form would run to ~270 characters against a
   * varchar(255) cachetags column and would carry colons and spaces. The cost
   * is that the tag is undebuggable by eye — hence this comment, and hence
   * titleKey() being reachable to reproduce one.
   */
  public static function titleCacheTag(string $title_key): string {
    return self::TITLE_TAG_PREFIX . substr(hash('xxh128', $title_key), 0, 16);
  }

  /**
   * The cache tag standing for "the set of pages linking to X".
   */
  public static function backlinkCacheTag(string $title_key): string {
    return self::BACKLINK_TAG_PREFIX . substr(hash('xxh128', $title_key), 0, 16);
  }

  /**
   * {@inheritdoc}
   */
  public function getExtension(): ExtensionInterface {
    return new WikiLinkExtension($this);
  }

  /**
   * {@inheritdoc}
   */
  public function takeMetadata(): BubbleableMetadata {
    $metadata = new BubbleableMetadata();
    if ($this->tags !== []) {
      $metadata->addCacheTags(array_keys($this->tags));
      // Attached from the filter result rather than from a /kb route: the
      // Markdown format is shared, so a task description or a worklog comment
      // can render a wiki link too, and those pages never load wiki CSS.
      $metadata->addAttachments(['library' => ['petrichor_wiki/wikilink']]);
    }
    $this->tags = [];
    return $metadata;
  }

  /**
   * Resolves normalized titles to pages, recording cacheability as it goes.
   *
   * @param string[] $title_keys
   *   Normalized keys, duplicates allowed.
   *
   * @return array
   *   key => ['id' => int, 'ids' => int[], 'ambiguous' => bool]. Absent keys
   *   are wanted pages.
   */
  public function resolve(array $title_keys): array {
    $keys = array_values(array_unique(array_filter($title_keys, static fn (string $k): bool => $k !== '')));
    $out = [];
    $lookup = [];
    foreach ($keys as $key) {
      $this->tags[self::titleCacheTag($key)] = TRUE;
      if (array_key_exists($key, $this->resolved)) {
        if ($this->resolved[$key] !== NULL) {
          $out[$key] = $this->resolved[$key];
        }
      }
      else {
        $lookup[] = $key;
      }
    }
    if ($lookup !== []) {
      $fresh = $this->storage->resolveTitles($lookup);
      foreach ($lookup as $key) {
        // NULL memoizes "wanted", so a page repeating the same unwritten link
        // does not re-query for each occurrence.
        $this->resolved[$key] = $fresh[$key] ?? NULL;
      }
      $out += $fresh;
    }
    return $out;
  }

  /**
   * The href for a link target.
   *
   * A wanted link points at the add form with the title pre-supplied, so the
   * red link is the thing that starts the page rather than a dead end. The URL
   * carries no user content that Url does not encode.
   */
  public function urlFor(?int $page_id, string $target): string {
    $url = $page_id === NULL
      ? Url::fromRoute('entity.petrichor_wiki_page.add_form', [], ['query' => ['title' => $target]])
      : Url::fromRoute('entity.petrichor_wiki_page.canonical', ['petrichor_wiki_page' => $page_id]);
    // toString(TRUE) rather than the bare cast: filters run inside a
    // #pre_render so an ambient render context exists today, but relying on it
    // means an early-rendering exception on somebody else's uncommon render
    // path. Route cacheability is folded into the drained metadata instead.
    $generated = $url->toString(TRUE);
    foreach ($generated->getCacheTags() as $tag) {
      $this->tags[$tag] = TRUE;
    }
    return $generated->getGeneratedUrl();
  }

  /**
   * Extracts `[[...]]` targets from markdown.
   *
   * Parses with the real tokenizer instead of a regex, and that is not
   * fastidiousness: the renderer never links inside a code span or a fenced
   * block, so a regex indexer would record links the page does not show — and
   * the pages most likely to contain fenced `[[examples]]` are exactly the
   * pages documenting this syntax.
   *
   * @return array
   *   target_key => ['text' => string, 'count' => int], insertion-ordered.
   */
  public function extract(string $markdown): array {
    if (!str_contains($markdown, '[[')) {
      // Cheap pre-filter: most bodies contain no wiki links at all, and
      // parsing a 40 KB page to learn that is waste on every save.
      return [];
    }
    $document = $this->parser()->parse($markdown);
    $links = [];
    foreach ($document->iterator() as $node) {
      if (!$node instanceof WikiLinkNode) {
        continue;
      }
      $key = $node->getTargetKey();
      if ($key === '') {
        continue;
      }
      if (isset($links[$key])) {
        $links[$key]['count']++;
        continue;
      }
      if (count($links) >= self::LINKS_PER_PAGE_CAP) {
        break;
      }
      $links[$key] = ['text' => $node->getTarget(), 'count' => 1];
    }
    return $links;
  }

  /**
   * Reindexes one page after a save.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $page
   *   The saved wiki page.
   * @param bool $body_changed
   *   FALSE to keep the existing link rows. A rename or a re-parent does not
   *   need a 40 KB body rescanned; only the title row moves.
   */
  public function indexPage(ContentEntityInterface $page, bool $body_changed = TRUE): void {
    $id = (int) $page->id();
    $title_key = self::titleKey((string) $page->label());
    $invalidate = [];

    $previous = $this->storage->indexedTitleKey($id);
    if ($previous !== $title_key) {
      $this->storage->setTitle($id, $title_key);
      // The new title: every wanted link naming it now resolves.
      $invalidate[] = self::titleCacheTag($title_key);
      if ($previous !== NULL) {
        // The old title: inbound links to it go back to wanted. Their rows are
        // kept, so the pages are still listed under the old title and the
        // rename is recoverable rather than lost.
        $invalidate[] = self::titleCacheTag($previous);
        $this->warnOnOrphanedInboundLinks($id, $previous, $title_key);
      }
    }

    if ($body_changed) {
      $links = $this->extract((string) $page->get('body')->value);
      $before = $this->storage->outboundKeys($id);
      $this->storage->setLinks($id, $links);
      // Only the targets that gained or lost this page as a source: an edit
      // that leaves the link set alone must not bust anybody's backlink block.
      // The (string) cast is not decorative — PHP turns a numeric array key
      // like "2026" into an int, and every method here is strict about types.
      $after = array_map(strval(...), array_keys($links));
      $moved = array_diff(array_merge($before, $after), array_intersect($before, $after));
      foreach ($moved as $key) {
        $invalidate[] = self::backlinkCacheTag((string) $key);
      }
    }

    $this->resolved = [];
    if ($invalidate !== []) {
      $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($invalidate)));
    }
  }

  /**
   * Drops a deleted page from the index.
   */
  public function forgetPage(ContentEntityInterface $page): void {
    $id = (int) $page->id();
    $title_key = $this->storage->indexedTitleKey($id) ?? self::titleKey((string) $page->label());
    $invalidate = [self::titleCacheTag($title_key), self::backlinkCacheTag($title_key)];
    foreach ($this->storage->outboundKeys($id) as $key) {
      $invalidate[] = self::backlinkCacheTag($key);
    }
    $this->storage->forget($id);
    $this->resolved = [];
    $this->cacheTagsInvalidator->invalidateTags(array_values(array_unique($invalidate)));
  }

  /**
   * Rebuilds the whole index from page bodies.
   *
   * The tables are derived, so this must exist: it is the repair path, the
   * install path for a site that already has pages, and the post-update path
   * after any conversion that bypasses entity hooks. Note that a revisionable
   * conversion copies rows with SqlContentEntityStorage::restore(), which skips
   * pre/post-save operations entirely — no hook fires, so the index has to be
   * seeded here afterwards.
   *
   * @return int
   *   Pages indexed.
   */
  public function rebuildAll(): int {
    $this->storage->truncate();
    $storage = $this->entityTypeManager->getStorage('petrichor_wiki_page');
    // No access check: this is a maintenance rebuild of derived data, not a
    // read on anyone's behalf, and an index missing the rows the running user
    // cannot see would be worse than no index.
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id')->execute();
    $count = 0;
    foreach (array_chunk(array_values($ids), self::REBUILD_BATCH) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $page) {
        assert($page instanceof ContentEntityInterface);
        $id = (int) $page->id();
        $this->storage->setTitle($id, self::titleKey((string) $page->label()));
        $this->storage->setLinks($id, $this->extract((string) $page->get('body')->value));
        $count++;
      }
      $storage->resetCache($chunk);
    }
    $this->resolved = [];
    // Nothing finer is possible: every title and every backlink set may have
    // moved, and the tags are hashed so there is no wildcard to invalidate.
    $this->cacheTagsInvalidator->invalidateTags(['petrichor_wiki_page_list']);
    return $count;
  }

  /**
   * Pages linking to a page, by its current title.
   *
   * @return array
   *   list of ['id' => int, 'occurrences' => int]. Ids, not entities — the
   *   caller owns access checking.
   */
  public function getBacklinks(int $page_id, int $limit = 25, int $offset = 0): array {
    $key = $this->storage->indexedTitleKey($page_id);
    return $key === NULL ? [] : $this->storage->backlinksFor($key, $limit, $offset);
  }

  /**
   * Pages linking to a title, whether or not a page by that name exists.
   *
   * This is the call that makes a rename non-destructive: ask it with the old
   * title before renaming and you get the list of pages that will be orphaned.
   */
  public function getBacklinksByTitle(string $title, int $limit = 25, int $offset = 0): array {
    return $this->storage->backlinksFor(self::titleKey($title), $limit, $offset);
  }

  /**
   * How many pages link to a title.
   */
  public function countBacklinksByTitle(string $title): int {
    return $this->storage->countBacklinks(self::titleKey($title));
  }

  /**
   * A page's outbound links, resolved.
   *
   * @return array
   *   list of ['title' => string, 'key' => string, 'occurrences' => int,
   *   'id' => int|null, 'wanted' => bool].
   */
  public function getOutboundLinks(int $page_id): array {
    $links = $this->storage->outboundLinks($page_id);
    if ($links === []) {
      return [];
    }
    $resolved = $this->storage->resolveTitles(array_column($links, 'key'));
    return array_map(static function (array $link) use ($resolved): array {
      $hit = $resolved[$link['key']] ?? NULL;
      return [
        'title' => $link['text'],
        'key' => $link['key'],
        'occurrences' => $link['occurrences'],
        'id' => $hit['id'] ?? NULL,
        'wanted' => $hit === NULL,
      ];
    }, $links);
  }

  /**
   * Linked-but-unwritten titles, most-linked first.
   */
  public function listWantedPages(?int $project_id = NULL, int $limit = 25, int $offset = 0): array {
    return $this->storage->wantedTitles($project_id, $limit, $offset);
  }

  /**
   * Total distinct wanted titles, for truncation notes.
   */
  public function countWantedPages(?int $project_id = NULL): int {
    return $this->storage->countWantedTitles($project_id);
  }

  /**
   * Pages sharing a title, which is what makes a link ambiguous.
   */
  public function findDuplicateTitles(): array {
    return $this->storage->duplicateTitles();
  }

  /**
   * Says out loud that a rename orphaned inbound links.
   *
   * Storing titles rather than ids means a rename genuinely re-points every
   * inbound link — correct wiki semantics, and the reason no re-sweep is
   * needed. The one unacceptable version of that is the silent one, so the
   * count goes to the log with both titles, and the rows stay put so
   * getBacklinksByTitle() on the old title still lists them. Rewriting inbound
   * bodies is deliberately not done here: it would save N other pages, churning
   * `changed` on each and flooding the activity feed from one rename.
   */
  private function warnOnOrphanedInboundLinks(int $page_id, string $old_key, string $new_key): void {
    $inbound = $this->storage->countBacklinks($old_key);
    if ($inbound === 0) {
      return;
    }
    $this->logger->warning('Wiki page @id was renamed; @count page(s) still link to the old title and now render as wanted links. Old key: "@old", new key: "@new".', [
      '@id' => $page_id,
      '@count' => $inbound,
      '@old' => $old_key,
      '@new' => $new_key,
    ]);
  }

  /**
   * Extraction-only parser.
   *
   * Built here rather than borrowed from the shared filter environment, which
   * would make this service depend on the registry that depends on it. It
   * carries the core extension plus the bare inline parser: same tokenizer,
   * same code-span and fence exclusions, no renderer and no resolution.
   */
  private function parser(): MarkdownParser {
    if ($this->parser === NULL) {
      $environment = new Environment([
        'html_input' => 'escape',
        'allow_unsafe_links' => FALSE,
      ]);
      $environment->addExtension(new CommonMarkCoreExtension());
      $environment->addInlineParser(new WikiLinkParser(), WikiLinkParser::PRIORITY);
      $this->parser = new MarkdownParser($environment);
    }
    return $this->parser;
  }

}
