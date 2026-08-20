<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\petrichor\ProvenanceTrait;
use Drupal\petrichor_tags\TagResolver;

/**
 * Domain service for wiki pages.
 *
 * Everything that reads or writes a page goes through here: controllers and
 * MCP tools hold no entity queries. Navigation and search are deliberately
 * built on one memoized skeleton plus PHP array work rather than per-node
 * queries — a sidebar at depth five must cost the same two queries as a flat
 * list, or the wiki stops being usable at the size where it starts to matter.
 */
class WikiService {

  use ProvenanceTrait;

  /**
   * Rows scanned before ranking. Ranking is PHP over the loaded candidates.
   *
   * A wider net would rank better and cost linearly more; 200 rows of title
   * plus body is a few milliseconds even on SQLite, and paging past it is
   * disclosed in the result note rather than silently degrading.
   */
  private const SEARCH_CANDIDATE_CAP = 200;

  /**
   * Query terms honoured. Beyond this the AND narrows to nothing useful.
   */
  private const SEARCH_MAX_TERMS = 6;

  /**
   * Characters of body either side of the match in a snippet.
   */
  private const SNIPPET_RADIUS = 120;

  /**
   * Hard cap on snippet length after markdown is stripped.
   */
  private const SNIPPET_MAX = 240;

  /**
   * Pages loaded into the navigation skeleton.
   *
   * No longer bounded by the database's bound-parameter limit — the raw
   * select behind this chunks its `IN` list (see ID_CHUNK), so the cap is
   * free to be a judgement about how large a tree is worth drawing rather
   * than about what SQLite will accept. A tree that silently truncates is
   * still better than one that fatals, so the cap stays and says so.
   */
  private const TREE_CAP = 2000;

  /**
   * Ids per `IN` clause when reading the skeleton.
   *
   * SQLite has historically compiled with a 999 bound-parameter limit, and
   * one parameter is bound per id. Chunking under that is what let TREE_CAP
   * rise past it: the rows are re-sorted into the entity query's order
   * afterwards regardless, so splitting the read changes nothing a caller
   * can observe.
   */
  private const ID_CHUNK = 500;

  /**
   * Ancestor walk stop.
   *
   * `parent` is writable over MCP with no validation on the entity, so a
   * cycle is reachable even though updateWikiPage() refuses to create one.
   * Every walk carries a visited set as well; this is the second belt.
   */
  private const MAX_DEPTH = 32;

  /**
   * Backlinks embedded in a single-page read before truncation is announced.
   */
  private const BACKLINK_CAP = 25;

  /**
   * Memoized navigation skeleton.
   *
   * Keyed by page id, each row [id, title, parent_id, project_id, weight].
   */
  private array $skeletonCache = [];

  /**
   * Whether the skeleton hit TREE_CAP, per project scope (0 = wiki-wide).
   *
   * Keyed rather than a single bool: the global skeleton overflowing says
   * nothing about whether one project's tree is complete.
   */
  private array $skeletonTruncated = [];

  /**
   * Memoized answer to revisionsInstalled().
   */
  private ?bool $revisionsInstalledCache = NULL;

  /**
   * Constructs a WikiService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Reaches the wiki page storage and its entity type definition, which is
   *   also where the base table and column names come from.
   * @param \Drupal\Core\Database\Connection $database
   *   Used for the raw column reads that avoid hydrating entities.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Request time, for search recency scoring.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager
   *   Asked whether the entity type is revisionable *in the database* rather
   *   than in code. Between the update hook that adds revisions and the schema
   *   landing, those two disagree, and trusting the code definition makes
   *   setNewRevision() fatal on every save in that window.
   * @param \Drupal\petrichor_wiki\WikiLinkService|null $linkResolver
   *   Owns the one title normalizer every `[[link]]` comparison goes through.
   *   Optional so the wiki still reads and writes before the link tables are
   *   installed — backlink-derived features return empty and say so rather
   *   than re-implementing normalization here, which is exactly the bug class
   *   a second normalizer would create.
   * @param \Drupal\petrichor_tags\TagResolver|null $tags
   *   NULL when petrichor_tags is not installed — the 'tags' field is then
   *   simply absent, same as every other Petrichor entity's optional
   *   cross-cutting fields.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityDefinitionUpdateManagerInterface $entityDefinitionUpdateManager,
    protected ?WikiLinkService $linkResolver = NULL,
    protected ?TagResolver $tags = NULL,
  ) {}

  /**
   * Storage handler for wiki pages.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_wiki_page');
  }

  /**
   * Entity type definition for wiki pages.
   *
   * Read for the base table and revision keys, which the raw selects below
   * need and must not hard-code.
   */
  protected function entityType() {
    return $this->entityTypeManager->getDefinition('petrichor_wiki_page');
  }

  /**
   * Ranked keyword search with a plain-text snippet per hit.
   *
   * Two passes: an access-checked entity query picks candidates (every term
   * must appear in title or body, plus the whole phrase as its own arm so an
   * exact phrase always matches), then scoring and snipping happen in PHP
   * over four raw columns. Only the returned page is loaded as an entity —
   * ranking 200 pages must not mean hydrating 200 entities.
   *
   * `total` is the honest database match count, but ranking only ever sees
   * SEARCH_CANDIDATE_CAP rows, so a deep offset is disclosed in the note.
   *
   * @param string $keywords
   *   Whitespace-separated terms; the whole string also matches as a phrase.
   * @param int|null $project_id
   *   Restrict to one project's pages, or NULL for the whole wiki.
   * @param int $limit
   *   Hits per page, applied after ranking rather than in the query.
   * @param int $offset
   *   Skip this many ranked hits. Anything past SEARCH_CANDIDATE_CAP is
   *   unservable, which is what the truncation note in the result says.
   * @param bool $snippets
   *   Snippets are plain text with a separate `snippet_terms` list, never
   *   pre-marked HTML: this service feeds MCP, and body content reaching an
   *   agent stays data rather than becoming markup.
   */
  public function searchWiki(string $keywords, ?int $project_id = NULL, int $limit = 25, int $offset = 0, bool $snippets = TRUE): array {
    if (trim($keywords) === '') {
      throw new \InvalidArgumentException('query is required.');
    }
    $phrase = trim((string) preg_replace('/\s+/u', ' ', $keywords));
    $terms = $this->terms($phrase);
    $offset = max(0, $offset);

    $query = $this->candidateQuery($phrase, $terms, $project_id);
    $total = (int) (clone $query)->count()->execute();
    if ($total === 0) {
      return $this->listResult([], 0, $offset);
    }

    // Recency picks which slice of a very broad match gets ranked. A wiki's
    // stale corners are exactly the part worth ranking last.
    $ids = array_map(intval(...), array_values(
      $query->sort('changed', 'DESC')->range(0, self::SEARCH_CANDIDATE_CAP)->execute()
    ));
    $rows = $this->searchRows($ids);

    $titles = [];
    foreach ($rows as $id => $row) {
      $titles[$id] = $row['title'];
    }
    // The one ranking signal a wiki has that a generic search engine does not:
    // pages other pages point at are usually the page you meant.
    $inbound = $this->inboundCounts($titles);

    $now = $this->time->getRequestTime();
    $ranked = [];
    foreach ($rows as $id => $row) {
      $ranked[] = [
        'id' => $id,
        'score' => $this->score($row, $phrase, $terms, $inbound[$id] ?? 0, $now),
        'changed' => $row['changed'],
      ];
    }
    usort($ranked, static fn(array $a, array $b): int =>
      [$b['score'], $b['changed'], $a['id']] <=> [$a['score'], $a['changed'], $b['id']]);

    $page_ids = array_column(array_slice($ranked, $offset, $limit), 'id');
    $pages = $this->storage()->loadMultiple($page_ids);
    $items = [];
    foreach ($page_ids as $page_id) {
      if (!isset($pages[$page_id])) {
        continue;
      }
      $item = $this->stub($pages[$page_id]);
      if ($snippets) {
        [$snippet, $matched] = $this->snippet($rows[$page_id]['body'], $phrase, $terms);
        if ($snippet !== '') {
          $item['snippet'] = $snippet;
          $item['snippet_terms'] = $matched;
        }
      }
      $items[] = $item;
    }

    // Paged against what ranking can actually serve, not the database count:
    // listResult() derives next_offset from the total it is given, so passing
    // the full count advertised pages past the candidate cap that would come
    // back empty. The honest count is still reported, as `matches`.
    $servable = min($total, count($ranked));
    $result = $this->listResult($items, $servable, $offset);
    if ($servable !== $total) {
      $result['matches'] = $total;
    }
    if ($total > self::SEARCH_CANDIDATE_CAP) {
      // Same spirit as listResult()'s truncation note: an agent silently
      // handed a worse page 9 than page 1 is worse off than one told why.
      $cap_note = sprintf(
        'ranking covers the %d most recently changed of %d matches — narrow the query rather than paging past that',
        self::SEARCH_CANDIDATE_CAP,
        $total,
      );
      $result['note'] = isset($result['note']) ? $result['note'] . '; ' . $cap_note : $cap_note;
    }
    return $result;
  }

  /**
   * One page: raw markdown body, where it sits, and what links to it.
   *
   * @param int $id
   *   The wiki page id.
   * @param bool $with_navigation
   *   Adds breadcrumb, children and backlinks. Costs the two skeleton queries
   *   plus one backlink lookup; pass FALSE when only the body is wanted.
   */
  public function getWikiPage(int $id, bool $with_navigation = TRUE): array {
    $page = $this->storage()->load($id);
    if (!$page || !$page->access('view')) {
      throw new \InvalidArgumentException('Wiki page not found.');
    }
    $stub = $this->withCreated($this->stub($page), $page);
    $stub['body'] = $page->get('body')->value;

    if (!$with_navigation) {
      return $stub;
    }

    if ($breadcrumb = $this->getBreadcrumb($id)) {
      $stub['breadcrumb'] = $breadcrumb;
    }
    if ($children = $this->getChildren($id)) {
      $stub['children'] = $children;
    }
    // No outbound link list: the caller already has the raw body above, and
    // every `[[link]]` in it is visible there. Restating them would be the
    // same data twice in a payload an agent pays for on every read.
    $backlinks = $this->getBacklinksByTitle((string) $page->label(), self::BACKLINK_CAP);
    if ($backlinks['items']) {
      $stub['backlinks'] = $backlinks['items'];
      $shown = count($backlinks['items']);
      if ($backlinks['total'] > $shown) {
        $stub['backlinks_note'] = sprintf('showing %d of %d pages that link here', $shown, $backlinks['total']);
      }
    }
    if ($this->truncated()) {
      $stub['navigation_note'] = sprintf(
        'the wiki exceeds %d pages, so breadcrumb and children may be incomplete',
        self::TREE_CAP,
      );
    }
    return $stub;
  }

  /**
   * The page tree, nested and ready to render without further queries.
   *
   * Two queries at any depth: an access-checked entity query for the id set,
   * then one raw select of four columns. Everything after that is array work
   * over the memoized skeleton, so a controller calling getTree(),
   * getBreadcrumb() and getSiblings() still issues two queries in total.
   *
   * @param int|null $project_id
   *   NULL means every visible page. An id means that project's pages only —
   *   a page whose parent lives in another project then reads as a root.
   * @param int|null $active_id
   *   When given, the tree is pruned to the spine: all roots, plus the
   *   branches on the path to this page, plus its own children. A sidebar
   *   wants context, not the whole wiki expanded.
   * @param int|null $root_id
   *   Start from this page's children instead of the roots.
   * @param int|null $max_depth
   *   Levels below the starting point, 1 being the starting level only.
   *
   * @return array
   *   ['items' => nested nodes, 'total' => int, 'truncated' => bool,
   *   'note' => string|null]. A node is ['id', 'title', 'depth'] plus
   *   'active'/'in_trail'/'children' only when they apply — a boolean FALSE
   *   repeated across 500 nodes is pure weight.
   */
  public function getTree(?int $project_id = NULL, ?int $active_id = NULL, ?int $root_id = NULL, ?int $max_depth = NULL): array {
    $skeleton = $this->skeleton($project_id);

    $children_of = [];
    foreach ($skeleton as $node) {
      // An unresolvable parent — deleted, or filtered out by the project
      // scope — reads as a root. The alternative is a page that exists and
      // appears in no tree at all.
      $parent = $node['parent_id'] !== NULL && isset($skeleton[$node['parent_id']]) ? $node['parent_id'] : 0;
      $children_of[$parent][] = $node['id'];
    }

    $trail = $active_id !== NULL ? array_flip(array_column($this->getBreadcrumb($active_id), 'id')) : [];
    $roots = $root_id !== NULL ? ($children_of[$root_id] ?? []) : ($children_of[0] ?? []);

    $visited = [];
    $items = $this->buildTree($roots, $children_of, $skeleton, $trail, $active_id, $max_depth, 0, $visited);

    // Pages in a parent cycle are reachable from no root, so the walk above
    // never sees them. Surfacing them as extra roots keeps the guarantee that
    // every visible page appears in the tree exactly once.
    //
    // A depth limit does not disable that, because depth and roots are
    // different questions: a shallow walk still shows every root, so a
    // cycle-trapped page still has to be promoted to one or it appears
    // nowhere at all. An active-page trail or an explicit root_id *is* a
    // deliberate narrowing of the root set, and there promoting orphans
    // would undo exactly the pruning the caller asked for.
    $promote_orphans = $active_id === NULL && $root_id === NULL;
    $orphans = $promote_orphans
      ? array_values(array_diff(array_keys($skeleton), $this->reachable($roots, $children_of)))
      : [];
    if ($orphans) {
      $items = array_merge($items, $this->buildTree($orphans, $children_of, $skeleton, $trail, $active_id, $max_depth, 0, $visited));
    }

    return [
      'items' => $items,
      'total' => count($skeleton),
      'truncated' => $this->truncated($project_id),
      'note' => $this->truncated($project_id)
        ? sprintf('tree capped at %d pages', self::TREE_CAP)
        : NULL,
    ];
  }

  /**
   * Every id reachable from the given roots, ignoring any depth limit.
   *
   * Orphan promotion needs "is this page reachable at all", which is a
   * different question from "did the walk draw it". Diffing against what the
   * depth-limited walk visited answers the second, and on a shallow index
   * that promotes every pruned grandchild to a root — turning a table of
   * contents back into the wall it was meant to replace.
   *
   * @param int[] $roots
   *   Ids to start from.
   * @param array $children_of
   *   Child ids keyed by parent id, as assembled by getTree().
   *
   * @return int[]
   *   Reachable ids. Cycles terminate: an id already seen is not re-walked.
   */
  private function reachable(array $roots, array $children_of): array {
    $seen = [];
    $stack = $roots;
    while ($stack) {
      $id = array_pop($stack);
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      foreach ($children_of[$id] ?? [] as $child) {
        $stack[] = $child;
      }
    }
    return array_keys($seen);
  }

  /**
   * Everything a page controller needs to draw its navigation, in two queries.
   *
   * The tree here is wiki-wide on purpose: it shares the one memoized skeleton
   * with the breadcrumb. A project-scoped sidebar calls getTree() directly.
   */
  public function getPageNavigation(int $id): array {
    $skeleton = $this->skeleton();
    $breadcrumb = $this->getBreadcrumb($id);
    $parent_id = $skeleton[$id]['parent_id'] ?? NULL;
    return [
      'breadcrumb' => $breadcrumb,
      'parent' => $parent_id !== NULL && isset($skeleton[$parent_id])
        ? ['id' => $parent_id, 'title' => $skeleton[$parent_id]['title']]
        : NULL,
      'siblings' => $this->getSiblings($id),
      'children' => $this->getChildren($id),
      'tree' => $this->getTree(NULL, $id)['items'],
      'depth' => count($breadcrumb),
      'truncated' => $this->truncated(),
    ];
  }

  /**
   * Ancestors root-first, excluding the page itself.
   *
   * @return array
   *   List of ['id' => int, 'title' => string].
   */
  public function getBreadcrumb(int $id): array {
    $skeleton = $this->skeleton();
    $trail = [];
    $seen = [$id => TRUE];
    $current = $skeleton[$id]['parent_id'] ?? NULL;
    $depth = 0;
    while ($current !== NULL && isset($skeleton[$current]) && !isset($seen[$current]) && $depth < self::MAX_DEPTH) {
      $seen[$current] = TRUE;
      array_unshift($trail, ['id' => $current, 'title' => $skeleton[$current]['title']]);
      $current = $skeleton[$current]['parent_id'];
      $depth++;
    }
    return $trail;
  }

  /**
   * Pages sharing a parent, in tree order, including the page itself.
   *
   * Self is included because a sibling list that hides the page you are on is
   * a list a sidebar cannot render.
   */
  public function getSiblings(int $id): array {
    $skeleton = $this->skeleton();
    if (!isset($skeleton[$id])) {
      return [];
    }
    $parent_id = $skeleton[$id]['parent_id'];
    $siblings = [];
    foreach ($skeleton as $node) {
      if ($node['parent_id'] === $parent_id) {
        $siblings[] = ['id' => $node['id'], 'title' => $node['title']];
      }
    }
    return $siblings;
  }

  /**
   * Direct children in tree order.
   */
  public function getChildren(int $id): array {
    $children = [];
    foreach ($this->skeleton() as $node) {
      if ($node['parent_id'] === $id) {
        $children[] = ['id' => $node['id'], 'title' => $node['title']];
      }
    }
    return $children;
  }

  /**
   * Pages linking to this one.
   */
  public function getBacklinks(int $id, int $limit = 25, int $offset = 0): array {
    $page = $this->storage()->load($id);
    if (!$page || !$page->access('view')) {
      throw new \InvalidArgumentException('Wiki page not found.');
    }
    return $this->getBacklinksByTitle((string) $page->label(), $limit, $offset);
  }

  /**
   * Pages linking to a title, whether or not a page by that title exists.
   *
   * Title-keyed rather than id-keyed because that is what the link table
   * stores: a `[[link]]` names a title, so asking by title is the question
   * that can be answered before a rename or a create, not only after.
   *
   * @return array
   *   ['items' => list of ['id' => int, 'title' => string], 'total' => int].
   */
  public function getBacklinksByTitle(string $title, int $limit = 25, int $offset = 0): array {
    if ($this->linkResolver === NULL) {
      return ['items' => [], 'total' => 0];
    }
    $key = $this->linkResolver->titleKey($title);
    $total = (int) $this->database->select('petrichor_wiki_link', 'l')
      ->fields('l', ['source_id'])
      ->condition('l.target_key', $key)
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($total === 0) {
      return ['items' => [], 'total' => 0];
    }
    $source_ids = array_map(intval(...), $this->database->select('petrichor_wiki_link', 'l')
      ->fields('l', ['source_id'])
      ->condition('l.target_key', $key)
      ->orderBy('l.source_id')
      ->range(max(0, $offset), $limit)
      ->execute()
      ->fetchCol());
    if (!$source_ids) {
      return ['items' => [], 'total' => $total];
    }

    // The link table has no access dimension of its own, so the ids it hands
    // back are re-gated through an entity query before anything is disclosed.
    // Equivalent today (access is all-or-nothing) and correct on the day
    // per-project membership lands.
    $visible = array_map(intval(...), array_values($this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('id', $source_ids, 'IN')
      ->execute()));
    $pages = $this->storage()->loadMultiple($visible);
    $items = [];
    foreach ($source_ids as $source_id) {
      if (isset($pages[$source_id])) {
        $items[] = ['id' => $source_id, 'title' => $pages[$source_id]->label()];
      }
    }
    return ['items' => $items, 'total' => $total];
  }

  /**
   * Titles that are linked to but have no page — "what to write next".
   *
   * Falls straight out of the link table: a link row whose target_key has no
   * row in the title index is a wanted page, and the count of distinct
   * sources ranks them by how badly the wiki wants them.
   */
  public function listWantedPages(?int $project_id = NULL, int $limit = 25, int $offset = 0): array {
    if ($this->linkResolver === NULL) {
      return ['items' => [], 'total' => 0, 'note' => 'wiki link indexing is not installed'];
    }
    // target_text is free text lifted verbatim out of page bodies, so a wanted
    // title may only be disclosed when the page that wants it is visible. The
    // link table has no access dimension of its own, so the visible source set
    // is resolved through an access-checked entity query first and gates the
    // aggregate — filtering afterwards would have already leaked the titles.
    // This also carries the project filter, replacing a raw join on the base
    // table that bypassed access entirely.
    $scope = $this->storage()->getQuery()->accessCheck(TRUE);
    if ($project_id !== NULL) {
      $scope->condition('project', $project_id);
    }
    $visible = array_map(intval(...), array_values($scope->execute()));
    if (!$visible) {
      return ['items' => [], 'total' => 0, 'offset' => max(0, $offset)];
    }

    $select = $this->database->select('petrichor_wiki_link', 'l');
    $select->leftJoin('petrichor_wiki_title', 't', 't.title_key = l.target_key');
    $select->isNull('t.page_id');
    $select->condition('l.source_id', $visible, 'IN');
    $select->addField('l', 'target_key');
    // The title as somebody actually typed it, not the normalized key.
    $select->addExpression('MIN(l.target_text)', 'title');
    $select->addExpression('COUNT(DISTINCT l.source_id)', 'wanted_by');
    $select->groupBy('l.target_key');

    $total = (int) (clone $select)->countQuery()->execute()->fetchField();
    $rows = $select->orderBy('wanted_by', 'DESC')
      ->orderBy('l.target_key')
      ->range(max(0, $offset), $limit)
      ->execute()
      ->fetchAll();

    $items = array_map(static fn(object $row): array => [
      'title' => (string) $row->title,
      'wanted_by' => (int) $row->wanted_by,
    ], $rows);
    return ['items' => $items, 'total' => $total, 'offset' => max(0, $offset)];
  }

  /**
   * A page's revision history, newest first.
   *
   * Degrades rather than fatals when the entity type is not revisionable yet:
   * the conversion is a batched update hook, so code and database can
   * legitimately disagree for the length of a deploy.
   */
  public function getWikiPageRevisions(int $id, int $limit = 25, int $offset = 0): array {
    $page = $this->storage()->load($id);
    if (!$page || !$page->access('view')) {
      throw new \InvalidArgumentException('Wiki page not found.');
    }
    $storage = $this->storage();
    $entity_type = $this->entityType();
    if (!$this->revisionsInstalled() || !$storage instanceof RevisionableStorageInterface) {
      return [
        'items' => [],
        'total' => 0,
        'note' => 'this site does not keep wiki page revisions yet',
      ];
    }

    $offset = max(0, $offset);
    $id_key = $entity_type->getKey('id');
    $revision_key = $entity_type->getKey('revision');
    // accessCheck(FALSE) matches core's VersionHistoryController: view access
    // to the page was settled above, and asking the access handler about
    // every historical row adds cost without adding a guarantee.
    $revision_query = fn() => $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition($id_key, $id);

    $total = (int) $revision_query()->count()->execute();
    $revision_ids = array_keys($revision_query()
      ->sort($revision_key, 'DESC')
      ->range($offset, $limit)
      ->execute());
    if (!$revision_ids) {
      return $this->listResult([], $total, $offset);
    }
    $revisions = $storage->loadMultipleRevisions($revision_ids);

    $items = [];
    foreach ($revision_ids as $revision_id) {
      if (!isset($revisions[$revision_id])) {
        continue;
      }
      $items[] = $this->revisionStub($revisions[$revision_id]);
    }
    return $this->listResult($items, $total, $offset);
  }

  /**
   * Creates a page and returns its stub.
   *
   * The body format is pinned to 'markdown' rather than read from $values:
   * this is the MCP write path, and a caller able to name a text format is a
   * caller able to name one that renders raw HTML.
   *
   * @param array $values
   *   Keys title (required), body, parent_id, project_id, revision_log.
   *
   * @return array
   *   The stub of the saved page, the same shape a listing returns.
   */
  public function createWikiPage(array $values): array {
    if (empty($values['title'])) {
      throw new \InvalidArgumentException('title is required.');
    }
    $page = $this->storage()->create([
      'label' => $values['title'],
      'body' => ['value' => $values['body'] ?? '', 'format' => 'markdown'],
      'parent' => $values['parent_id'] ?? NULL,
      'project' => $values['project_id'] ?? NULL,
    ]);
    // Not in the create() array above: 'tags' is an entity_reference field
    // that only exists when petrichor_tags is enabled, and resolving names
    // to term ids needs a service call Entity::create() can't make on its
    // own — same reasoning as TaskService::createTask()'s milestone/tags.
    if (!empty($values['tags']) && $this->tags && $page->hasField('tags')) {
      $page->set('tags', $this->tags->resolveNames((array) $values['tags']));
    }
    if (!$page->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create wiki pages.');
    }
    $this->stampRevision($page, $values, FALSE);
    $page->save();
    $this->skeletonCache = [];
    $this->skeletonTruncated = [];
    return $this->stub($page);
  }

  /**
   * Edits a page, refusing any re-parent that would close a cycle.
   *
   * Absent keys are left alone rather than cleared, so a caller can send one
   * field without having to read the page first. `append` beats `body` when
   * both arrive, which is the only ordering that makes an append safe against
   * a stale body.
   *
   * @param int $id
   *   The wiki page id.
   * @param array $values
   *   Keys title, body, append, parent_id, revision_log, tags.
   *
   * @return array
   *   The stub of the saved page.
   */
  public function updateWikiPage(int $id, array $values): array {
    $page = $this->storage()->load($id);
    if (!$page || !$page->access('update')) {
      throw new \InvalidArgumentException('Wiki page not found or not editable.');
    }
    if (array_key_exists('title', $values)) {
      $page->set('label', $values['title']);
    }
    // Append avoids resending an entire page to add a line — the running-log
    // pattern this wiki is built for.
    if (!empty($values['append'])) {
      $existing = (string) $page->get('body')->value;
      $page->set('body', [
        'value' => $existing === '' ? $values['append'] : $existing . "\n\n" . $values['append'],
        'format' => 'markdown',
      ]);
    }
    elseif (array_key_exists('body', $values)) {
      $page->set('body', ['value' => $values['body'], 'format' => 'markdown']);
    }
    if (array_key_exists('parent_id', $values)) {
      $parent_id = $values['parent_id'] === NULL || $values['parent_id'] === ''
        ? NULL
        : (int) $values['parent_id'];
      if ($parent_id !== NULL) {
        // Nothing in the entity layer validates this, and the damage is
        // silent and total: a page re-parented under its own descendant makes
        // a loop reachable from no root, so both pages drop out of every tree
        // and every breadcrumb until someone edits the database.
        if ($parent_id === $id || $this->isDescendantOf($parent_id, $id)) {
          throw new \InvalidArgumentException(sprintf(
            'Page %d is page %d or one of its descendants; re-parenting there would create a cycle.',
            $parent_id,
            $id,
          ));
        }
      }
      $page->set('parent', $parent_id);
    }
    if (array_key_exists('tags', $values) && $page->hasField('tags')) {
      $names = (array) ($values['tags'] ?? []);
      $page->set('tags', $names ? ($this->tags?->resolveNames($names) ?? []) : []);
    }
    $this->stampRevision($page, $values, TRUE);
    $page->save();
    $this->skeletonCache = [];
    $this->skeletonTruncated = [];
    return $this->stub($page);
  }

  /**
   * The shared page summary, plus the two references that place it.
   *
   * @param mixed $page
   *   A loaded wiki page entity.
   *
   * @return array
   *   The base stub with parent_id and project_id, NULL when unset.
   */
  protected function stub($page): array {
    $stub = $this->baseStub($page) + [
      'parent_id' => $page->get('parent')->target_id ? (int) $page->get('parent')->target_id : NULL,
      'project_id' => $page->get('project')->target_id ? (int) $page->get('project')->target_id : NULL,
    ];
    // $this->tags is NULL when petrichor_tags is not installed — the field
    // is then simply absent, same as every other Petrichor entity's
    // optional cross-cutting fields.
    $tags = $this->tags?->namesFor($page) ?? [];
    if ($tags) {
      $stub['tags'] = $tags;
    }
    return $stub;
  }

  /**
   * Records who changed a page and why, and forks a revision on edit.
   *
   * Nothing in core does this for us — ContentEntityForm only forks a
   * revision when a user ticks a checkbox, and MCP never sees a form. Without
   * it the entity is revisionable and the history stays one row forever,
   * which looks exactly like a working wiki until someone needs the history.
   */
  private function stampRevision($page, array $values, bool $new_revision): void {
    if (!$this->revisionsInstalled() || !$page instanceof RevisionLogInterface) {
      return;
    }
    // Only the log message is set here. Forking and the created/user stamps
    // belong to WikiPage::preSave(), which is the one path every write goes
    // through — form, drush and MCP alike. Doing them in both places meant
    // this method could fork a revision that preSave()'s syncing guard was
    // deliberately suppressing.
    $log = trim((string) ($values['revision_log'] ?? ''));
    if ($log !== '') {
      $page->setRevisionLogMessage($log);
    }
  }

  /**
   * One revision as a history row.
   */
  private function revisionStub(RevisionableInterface $revision): array {
    $item = ['revision_id' => (int) $revision->getRevisionId()];
    if ($revision->isDefaultRevision()) {
      $item['current'] = TRUE;
    }
    $item['title'] = $revision->label();
    if ($revision instanceof RevisionLogInterface) {
      if ($created = $revision->getRevisionCreationTime()) {
        $item['created'] = date('c', (int) $created);
      }
      if ($author = $this->author($revision->getRevisionUser())) {
        $item['author'] = $author;
      }
      $log = trim((string) $revision->getRevisionLogMessage());
      if ($log !== '') {
        $item['log'] = $log;
      }
    }
    if (!isset($item['created'])) {
      // The revisionable conversion seeds one revision per existing page with
      // no author, timestamp or log, because there was no history to invent.
      // Saying so beats rendering a blank row that reads as data loss.
      $item['note'] = 'seed revision: predates revision history on this site';
    }
    return $item;
  }

  /**
   * Whether the database — not just the code — has the revision tables.
   *
   * Deliberately the last-installed definition rather than
   * EntityTypeInterface::isRevisionable(): the schema conversion is a separate
   * batched update hook, so between deploying the revisionable entity class
   * and running `drush updb` the code says revisionable while the database
   * has no revision table. Trusting the code definition in that window makes
   * setNewRevision() fatal on every wiki save.
   */
  private function revisionsInstalled(): bool {
    $this->revisionsInstalledCache ??= (bool) $this->entityDefinitionUpdateManager
      ->getEntityType('petrichor_wiki_page')?->isRevisionable();
    return $this->revisionsInstalledCache;
  }

  /**
   * Author block for a revision.
   *
   * Mirrors ProvenanceTrait::baseStub()'s agent detection — that derives the
   * author from the entity owner, and a revision's author is a different
   * field. The `agent_` role prefix is the threat model's human-vs-agent
   * signal; keep the two in step.
   */
  private function author(?AccountInterface $account): ?array {
    if (!$account) {
      return NULL;
    }
    $name = $account->getAccountName();
    if ($name === '') {
      $name = $account->isAnonymous() ? 'system' : 'uid-' . $account->id();
    }
    return [
      'name' => $name,
      'is_agent' => (bool) array_filter($account->getRoles(), static fn(string $role): bool => str_starts_with($role, 'agent_')),
    ];
  }

  /**
   * Whether $id sits anywhere under $ancestor_id.
   */
  private function isDescendantOf(int $id, int $ancestor_id): bool {
    $skeleton = $this->skeleton();
    $seen = [];
    $current = $id;
    $depth = 0;
    while ($current !== NULL && isset($skeleton[$current]) && !isset($seen[$current]) && $depth < self::MAX_DEPTH) {
      if ($current === $ancestor_id) {
        return TRUE;
      }
      $seen[$current] = TRUE;
      $current = $skeleton[$current]['parent_id'];
      $depth++;
    }
    return FALSE;
  }

  /**
   * Whether the skeleton for one scope was capped.
   */
  private function truncated(?int $project_id = NULL): bool {
    return $this->skeletonTruncated[$project_id ?? 0] ?? FALSE;
  }

  /**
   * Id => [id, title, parent_id, project_id, weight] for every visible page.
   *
   * Two queries, memoized for the request. The entity query is the access
   * gate and returns ids only; the raw select then reads five small columns —
   * deliberately not loadMultiple(), which would drag every body into memory
   * to draw a sidebar. Splitting it this way is also what keeps the access
   * check honest on the day per-project membership lands.
   *
   * @param int|null $project_id
   *   Scope to one project, or NULL for the whole wiki. The scope
   *   is part of the query, never a filter over a shared result — see below.
   *
   * @return array
   *   Rows in the entity query's order: weight, then title.
   */
  private function skeleton(?int $project_id = NULL): array {
    $key = $project_id ?? 0;
    if (isset($this->skeletonCache[$key])) {
      return $this->skeletonCache[$key];
    }
    // The project scope belongs in the query, not in a filter over the result.
    // Capping first and filtering afterwards made a project's tree whatever
    // subset of its pages happened to fall inside the global first TREE_CAP —
    // so a project could render a tree missing pages it definitely owns.
    $query = $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight')
      ->sort('label')
      ->range(0, self::TREE_CAP + 1);
    if ($project_id !== NULL) {
      $query->condition('project', $project_id);
    }
    $ids = array_map(intval(...), array_values($query->execute()));
    $this->skeletonTruncated[$key] = count($ids) > self::TREE_CAP;
    $ids = array_slice($ids, 0, self::TREE_CAP);
    if (!$ids) {
      return $this->skeletonCache[$key] = [];
    }

    $columns = [
      'id' => $this->column('id', 'value'),
      'title' => $this->column('label', 'value'),
      'parent_id' => $this->column('parent', 'target_id'),
      'project_id' => $this->column('project', 'target_id'),
      'weight' => $this->column('weight', 'value'),
    ];
    $rows = [];
    foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
      $rows = array_merge($rows, $this->database->select($this->entityType()->getBaseTable(), 'p')
        ->fields('p', array_values($columns))
        ->condition('p.' . $columns['id'], $chunk, 'IN')
        ->execute()
        ->fetchAll());
    }

    $by_id = [];
    foreach ($rows as $row) {
      $by_id[(int) $row->{$columns['id']}] = [
        'id' => (int) $row->{$columns['id']},
        'title' => (string) $row->{$columns['title']},
        'parent_id' => $row->{$columns['parent_id']} !== NULL ? (int) $row->{$columns['parent_id']} : NULL,
        'project_id' => $row->{$columns['project_id']} !== NULL ? (int) $row->{$columns['project_id']} : NULL,
        'weight' => (int) $row->{$columns['weight']},
      ];
    }
    // Re-imposed in the entity query's order (weight, then title) — the raw
    // select returns whatever the database felt like.
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($by_id[$id])) {
        $ordered[$id] = $by_id[$id];
      }
    }
    return $this->skeletonCache[$key] = $ordered;
  }

  /**
   * Recursive tree assembly over the in-memory skeleton. No queries.
   */
  private function buildTree(array $ids, array $children_of, array $skeleton, array $trail, ?int $active_id, ?int $max_depth, int $depth, array &$visited): array {
    if ($max_depth !== NULL && $depth >= $max_depth) {
      return [];
    }
    $nodes = [];
    foreach ($ids as $id) {
      if (!isset($skeleton[$id]) || isset($visited[$id])) {
        continue;
      }
      $visited[$id] = TRUE;
      $node = [
        'id' => $id,
        'title' => $skeleton[$id]['title'],
        'depth' => $depth,
      ];
      if ($id === $active_id) {
        $node['active'] = TRUE;
      }
      elseif (isset($trail[$id])) {
        $node['in_trail'] = TRUE;
      }
      // With an active page the tree is pruned to the spine: only the branch
      // leading to it, and its own children, expand. A sidebar wants context,
      // not the entire wiki unfolded.
      $expand = $active_id === NULL || $id === $active_id || isset($trail[$id]);
      if ($expand && !empty($children_of[$id])) {
        $children = $this->buildTree($children_of[$id], $children_of, $skeleton, $trail, $active_id, $max_depth, $depth + 1, $visited);
        if ($children) {
          $node['children'] = $children;
        }
      }
      elseif (!empty($children_of[$id])) {
        // Collapsed, not childless — a caret has to know there is something
        // behind it.
        $node['has_children'] = TRUE;
      }
      $nodes[] = $node;
    }
    return $nodes;
  }

  /**
   * Access-checked candidate query: every term must hit, or the phrase must.
   */
  private function candidateQuery(string $phrase, array $terms, ?int $project_id) {
    $query = $this->storage()->getQuery()->accessCheck(TRUE);
    if (!$terms) {
      $query->condition($this->titleOrBody($query, $phrase));
    }
    else {
      $all = $query->andConditionGroup();
      foreach ($terms as $term) {
        $all->condition($this->titleOrBody($query, $term));
      }
      if (count($terms) > 1) {
        // The phrase gets its own arm so an exact phrase always matches, even
        // when a word of it was dropped as too short or past the term cap.
        $query->condition($query->orConditionGroup()
          ->condition($all)
          ->condition($this->titleOrBody($query, $phrase)));
      }
      else {
        $query->condition($all);
      }
    }
    if ($project_id !== NULL) {
      $query->condition('project', $project_id);
    }
    return $query;
  }

  /**
   * A condition group matching one needle in either the title or the body.
   *
   * The needle is escaped for LIKE here, so callers pass it raw.
   *
   * @param mixed $query
   *   The entity query the group is attached to.
   * @param string $needle
   *   A single term, or the whole phrase.
   *
   * @return mixed
   *   An OR condition group, ready to hand to condition().
   */
  private function titleOrBody($query, string $needle) {
    $like = '%' . $this->database->escapeLike($needle) . '%';
    return $query->orConditionGroup()
      ->condition('label', $like, 'LIKE')
      ->condition('body', $like, 'LIKE');
  }

  /**
   * Title, body and changed time for the candidate ids — no entity loads.
   */
  private function searchRows(array $ids): array {
    if (!$ids) {
      return [];
    }
    $columns = [
      'id' => $this->column('id', 'value'),
      'title' => $this->column('label', 'value'),
      'body' => $this->column('body', 'value'),
      'changed' => $this->column('changed', 'value'),
    ];
    $rows = [];
    foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
      $rows = array_merge($rows, $this->database->select($this->entityType()->getBaseTable(), 'p')
        ->fields('p', array_values($columns))
        ->condition('p.' . $columns['id'], $chunk, 'IN')
        ->execute()
        ->fetchAll());
    }
    $out = [];
    foreach ($rows as $row) {
      $out[(int) $row->{$columns['id']}] = [
        'title' => (string) $row->{$columns['title']},
        'body' => (string) ($row->{$columns['body']} ?? ''),
        'changed' => (int) $row->{$columns['changed']},
      ];
    }
    return $out;
  }

  /**
   * Distinct inbound link counts, keyed by the page id that owns each title.
   */
  private function inboundCounts(array $titles): array {
    if (!$titles || $this->linkResolver === NULL) {
      return [];
    }
    $keys = [];
    foreach ($titles as $id => $title) {
      $keys[$id] = $this->linkResolver->titleKey((string) $title);
    }
    $select = $this->database->select('petrichor_wiki_link', 'l');
    $select->addField('l', 'target_key');
    // DISTINCT source: ten mentions on one page is one page's worth of
    // opinion, not ten.
    $select->addExpression('COUNT(DISTINCT l.source_id)', 'inbound');
    $select->condition('l.target_key', array_values(array_unique($keys)), 'IN');
    $select->groupBy('l.target_key');
    $counts = $select->execute()->fetchAllKeyed();

    $out = [];
    foreach ($keys as $id => $key) {
      if (isset($counts[$key])) {
        $out[$id] = (int) $counts[$key];
      }
    }
    return $out;
  }

  /**
   * Query terms: lowercased, deduplicated, one-character noise dropped.
   */
  private function terms(string $phrase): array {
    $parts = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = array_values(array_unique(array_filter(
      $parts,
      static fn(string $term): bool => mb_strlen($term) >= 2,
    )));
    return array_slice($terms, 0, self::SEARCH_MAX_TERMS);
  }

  /**
   * Integer relevance score for one candidate row.
   *
   * A title hit has to beat any amount of body repetition — that is the whole
   * point of the ranking — so the title bands sit an order of magnitude above
   * the body ones. Recency is capped at 60 so it breaks ties and never drives
   * the rank: a two-point relevance gap must not be flipped by age.
   */
  private function score(array $row, string $phrase, array $terms, int $inbound, int $now): int {
    $title = mb_strtolower($row['title']);
    $body = mb_strtolower($row['body']);
    $needle = mb_strtolower($phrase);
    $score = 0;

    if ($needle !== '') {
      if ($title === $needle) {
        $score += 1000;
      }
      elseif (str_starts_with($title, $needle)) {
        $score += 600;
      }
      elseif (str_contains($title, $needle)) {
        $score += 400;
      }
      if (str_contains($body, $needle)) {
        $score += 150;
      }
    }
    foreach ($terms as $term) {
      if (str_contains($title, $term)) {
        $score += 120;
      }
      // Repetition saturates at five: a page that says a word forty times is
      // a glossary entry, not forty times more relevant.
      $count = substr_count($body, $term);
      if ($count > 0) {
        $score += 40 + min($count, 5) * 4;
      }
    }
    $score += min($inbound, 10) * 3;

    $age = max(0, $now - $row['changed']);
    $score += match (TRUE) {
      $age < 604800 => 60,
      $age < 2592000 => 45,
      $age < 7776000 => 30,
      $age < 15552000 => 20,
      $age < 31536000 => 10,
      default => 0,
    };
    return $score;
  }

  /**
   * Plain-text excerpt around the best match, plus the terms it contains.
   *
   * The window is located in the raw markdown (so offsets are real) and only
   * then stripped of markup, which is why a match sitting inside link syntax
   * can fall out of the excerpt. Returns [snippet, matched terms].
   */
  private function snippet(string $body, string $phrase, array $terms): array {
    $body = trim($body);
    if ($body === '') {
      return ['', []];
    }
    $haystack = mb_strtolower($body);
    $needle = mb_strtolower($phrase);
    $matched = [];
    $position = NULL;
    $length = 0;

    if ($needle !== '' && ($found = mb_strpos($haystack, $needle)) !== FALSE) {
      $position = $found;
      $length = mb_strlen($needle);
      $matched[] = $phrase;
    }
    $best = NULL;
    $best_count = 0;
    foreach ($terms as $term) {
      $count = substr_count($haystack, $term);
      if ($count > 0) {
        $matched[] = $term;
        if ($count > $best_count) {
          $best_count = $count;
          $best = $term;
        }
      }
    }
    if ($position === NULL && $best !== NULL) {
      $position = (int) mb_strpos($haystack, $best);
      $length = mb_strlen($best);
    }
    // No literal hit anywhere (a term matched the title only): the opening of
    // the page is still a better answer than nothing.
    $position ??= 0;

    $start = max(0, $position - self::SNIPPET_RADIUS);
    $window = mb_substr($body, $start, $length + self::SNIPPET_RADIUS * 2);
    // Recorded before the word-boundary snap shortens the window, so the
    // trailing ellipsis reflects the body, not the trim.
    $end = $start + mb_strlen($window);
    if ($start > 0) {
      // Snap forward to a word boundary, but only a short way — a 120-char
      // run with no space is a URL or a table row, not a word to preserve.
      $cut = mb_strpos($window, ' ');
      if ($cut !== FALSE && $cut < 30) {
        $window = mb_substr($window, $cut + 1);
      }
    }

    $snippet = $this->plainText($window);
    if (mb_strlen($snippet) > self::SNIPPET_MAX) {
      $snippet = rtrim(mb_substr($snippet, 0, self::SNIPPET_MAX));
    }
    if ($snippet === '') {
      return ['', []];
    }
    if ($start > 0) {
      $snippet = '…' . $snippet;
    }
    if ($end < mb_strlen($body)) {
      $snippet .= '…';
    }
    return [$snippet, array_values(array_unique($matched))];
  }

  /**
   * Flattens markdown to readable prose for an excerpt.
   *
   * Underscores are deliberately left alone: this wiki is full of identifiers
   * like petrichor_wiki_page, and stripping them as emphasis would corrupt
   * the exact strings a reader is scanning for.
   */
  private function plainText(string $markdown): string {
    $text = (string) preg_replace('/^\s*```.*$/m', '', $markdown);
    $text = (string) preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text);
    $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
    $text = (string) preg_replace('/\[\[([^\]|]+)(?:\|[^\]]+)?\]\]/u', '$1', $text);
    $text = (string) preg_replace('/[*`>]+/u', '', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Storage column for a base field property.
   *
   * Resolved through the table mapping rather than spelled out, because the
   * rule is silent when it changes: a single-column field keeps the field
   * name ('parent'), a multi-column one gains a suffix ('body__value'), so
   * the day 'parent' grows a second stored property every raw select here
   * would quietly read a column that no longer exists.
   */
  private function column(string $field, string $property): string {
    $storage = $this->storage();
    assert($storage instanceof SqlEntityStorageInterface);
    $columns = $storage->getTableMapping()->getColumnNames($field);
    return $columns[$property] ?? $field;
  }

}
