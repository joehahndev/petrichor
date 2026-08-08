<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * The wiki link index: two derived side tables, and nothing authoritative.
 *
 * Both tables are rebuilt from page bodies on save and can be dropped and
 * regenerated at any time — the body text is the only source of truth. They are
 * side tables rather than entity fields for the reason petrichor_memory_recall
 * is: editing page A must never touch page B's `changed`, which conflict
 * detection, the activity feed and list ordering all key on. A backlink field
 * on the target would make every edit of A a save of B.
 *
 * WHAT A LINK STORES, AND WHAT RENAMING DOES
 * A row stores the normalized *title* of the target, never its entity id. An id
 * column would be a second source of truth needing a re-sweep across every
 * inbound page on each rename, create and delete, and every sweep is a chance
 * to be wrong. With titles, renaming page A to B needs no sweep at all: pages
 * saying `[[A]]` stop resolving and render as wanted links, pages already
 * saying `[[B]]` light up. That is wiki semantics rather than drift — but it is
 * only acceptable because it is *visible*: the rows survive the rename, so
 * backlinksFor() still lists every page that pointed at the old title and
 * WikiLinkService logs the count when the rename happens. Nothing breaks
 * silently; the KB gains a to-do.
 *
 * @see \Drupal\petrichor_wiki\WikiLinkService
 */
final class BacklinkStorage {

  /**
   * Outbound links, one row per (source page, distinct target title).
   */
  public const LINK_TABLE = 'petrichor_wiki_link';

  /**
   * Normalized title to page id.
   */
  public const TITLE_TABLE = 'petrichor_wiki_title';

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Table definitions for the module's hook_schema().
   *
   * Lives here rather than in petrichor_wiki.install so the schema sits beside
   * the only code that reads or writes it. The install file is expected to be
   * one line:
   * @code
   * function petrichor_wiki_schema(): array {
   *   return \Drupal\petrichor_wiki\BacklinkStorage::schema();
   * }
   * @endcode
   *
   * @return array
   *   Keyed by table name, in hook_schema() format.
   */
  public static function schema(): array {
    return [
      self::LINK_TABLE => [
        'description' => 'Outbound [[wiki links]], rebuilt from the page body on save. Derived data: droppable and rebuildable from bodies at any time.',
        'fields' => [
          'source_id' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'description' => 'The petrichor_wiki_page that contains the link.',
          ],
          'target_key' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'Normalized target title from WikiLinkService::titleKey(). A TITLE, never an id — see the class docblock for what that means on rename.',
          ],
          'target_text' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => 'The target as typed, so a wanted-pages report can show the author their own capitalisation.',
          ],
          'occurrences' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 1,
            'description' => 'How many times this source links this target.',
          ],
        ],
        // One row per distinct target: a page linking the same title five times
        // is one backlink, not five.
        'primary key' => ['source_id', 'target_key'],
        'indexes' => [
          // The backlink lookup — this table is read far more often by target
          // than by source.
          'target' => ['target_key'],
        ],
      ],
      self::TITLE_TABLE => [
        'description' => 'Normalized title -> page id. Exists because `=` on a varchar is case-sensitive on SQLite and case-insensitive on MySQL; link resolution must not depend on the driver. A repeated title_key is legal and means an ambiguous link.',
        'fields' => [
          'page_id' => [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => TRUE,
          ],
          'title_key' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
          ],
        ],
        'primary key' => ['page_id'],
        'indexes' => [
          'title_key' => ['title_key'],
        ],
      ],
    ];
  }

  /**
   * Records a page's normalized title, replacing any previous one.
   */
  public function setTitle(int $page_id, string $title_key): void {
    // key() takes a field NAME and asserts it is a string; the array form is
    // keys(). Passing an array here only fails under assertions, so it works
    // on a production site and dies on a dev one.
    $this->database->merge(self::TITLE_TABLE)
      ->keys(['page_id' => $page_id])
      ->fields(['title_key' => $title_key])
      ->execute();
  }

  /**
   * The title key currently indexed for a page, or NULL if it has none.
   *
   * Read from the index rather than from the entity's original values, so a
   * page whose index row was never written (an import, a rebuild that was
   * interrupted) self-heals on its next save instead of staying invisible.
   */
  public function indexedTitleKey(int $page_id): ?string {
    $key = $this->database->select(self::TITLE_TABLE, 't')
      ->fields('t', ['title_key'])
      ->condition('page_id', $page_id)
      ->execute()
      ->fetchField();
    return $key === FALSE ? NULL : (string) $key;
  }

  /**
   * Maps normalized titles to page ids.
   *
   * @param string[] $title_keys
   *   Normalized keys.
   *
   * @return array
   *   key => ['id' => int, 'ids' => int[], 'ambiguous' => bool]. Keys with no
   *   matching page are absent from the result — that is what "wanted" means.
   */
  public function resolveTitles(array $title_keys): array {
    $title_keys = array_values(array_unique(array_filter($title_keys, static fn (string $k): bool => $k !== '')));
    if ($title_keys === []) {
      return [];
    }
    $rows = $this->database->select(self::TITLE_TABLE, 't')
      ->fields('t', ['title_key', 'page_id'])
      ->condition('title_key', $title_keys, 'IN')
      // Ascending, so the first row per key is the oldest page. The oldest page
      // keeps the name: resolving to the most recently changed would let a
      // link's destination move under the reader because somebody edited a
      // same-titled decoy.
      ->orderBy('page_id')
      ->execute();

    $resolved = [];
    foreach ($rows as $row) {
      $key = (string) $row->title_key;
      $id = (int) $row->page_id;
      $resolved[$key]['id'] ??= $id;
      $resolved[$key]['ids'][] = $id;
      $resolved[$key]['ambiguous'] = count($resolved[$key]['ids']) > 1;
    }
    return $resolved;
  }

  /**
   * Page ids sharing a title, for a KB-health report.
   *
   * @return array
   *   list of ['key' => string, 'ids' => int[]].
   */
  public function duplicateTitles(): array {
    $select = $this->database->select(self::TITLE_TABLE, 't');
    $select->addField('t', 'title_key');
    $select->addExpression('COUNT(t.page_id)', 'pages');
    $select->groupBy('t.title_key');
    // having(), not havingCondition(): the latter escapes its first argument
    // as a field name and turns the aggregate into "COUNTt"."page_id".
    //
    // And the aggregate is repeated rather than referred to by its `pages`
    // alias, because PostgreSQL evaluates HAVING before the select list
    // exists, so `HAVING pages > 1` is an undefined-column error there.
    // MySQL, MariaDB and SQLite all accept the alias, which is exactly why
    // this survived every run until the suite was pointed at PostgreSQL.
    $select->having('COUNT(t.page_id) > :petrichor_min', [':petrichor_min' => 1]);
    $keys = $select->execute()->fetchCol();
    if (!$keys) {
      return [];
    }
    $out = [];
    foreach ($this->resolveTitles($keys) as $key => $resolution) {
      $out[] = ['key' => $key, 'ids' => $resolution['ids']];
    }
    return $out;
  }

  /**
   * Replaces one page's outbound links.
   *
   * Delete-then-insert rather than a diff: a body edit rewrites the whole set
   * anyway, and the two statements are cheaper than working out which rows
   * moved. Wrapped so a page is never left with half an index.
   *
   * @param int $page_id
   *   The source page.
   * @param array $links
   *   Keyed by target_key, each ['text' => string, 'count' => int], as
   *   WikiLinkService::extract() returns them.
   */
  public function setLinks(int $page_id, array $links): void {
    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::LINK_TABLE)
        ->condition('source_id', $page_id)
        ->execute();
      if ($links === []) {
        return;
      }
      $insert = $this->database->insert(self::LINK_TABLE)
        ->fields(['source_id', 'target_key', 'target_text', 'occurrences']);
      foreach ($links as $key => $link) {
        $insert->values([
          'source_id' => $page_id,
          'target_key' => $key,
          // Clamped, not assumed: the column is 255 and the parser's own limit
          // is 255 characters, which can be more than 255 bytes.
          'target_text' => mb_substr((string) $link['text'], 0, 255),
          'occurrences' => max(1, (int) ($link['count'] ?? 1)),
        ]);
      }
      $insert->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * The normalized targets a page links to.
   *
   * @return string[]
   *   One normalized key per distinct target, however many times the page
   *   links to it.
   */
  public function outboundKeys(int $page_id): array {
    return array_map(strval(...), $this->database->select(self::LINK_TABLE, 'l')
      ->fields('l', ['target_key'])
      ->condition('source_id', $page_id)
      ->execute()
      ->fetchCol());
  }

  /**
   * A page's outbound links, as typed.
   *
   * @return array
   *   list of ['key' => string, 'text' => string, 'occurrences' => int].
   */
  public function outboundLinks(int $page_id): array {
    $rows = $this->database->select(self::LINK_TABLE, 'l')
      ->fields('l', ['target_key', 'target_text', 'occurrences'])
      ->condition('source_id', $page_id)
      ->orderBy('target_text')
      ->execute();
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'key' => (string) $row->target_key,
        'text' => (string) $row->target_text,
        'occurrences' => (int) $row->occurrences,
      ];
    }
    return $out;
  }

  /**
   * Pages linking to a title.
   *
   * Returns ids, not loaded entities: access is the caller's job, and this
   * class holds no entity queries by design.
   *
   * @return array
   *   list of ['id' => int, 'occurrences' => int].
   */
  public function backlinksFor(string $title_key, int $limit = 25, int $offset = 0): array {
    $rows = $this->database->select(self::LINK_TABLE, 'l')
      ->fields('l', ['source_id', 'occurrences'])
      ->condition('target_key', $title_key)
      ->orderBy('occurrences', 'DESC')
      ->orderBy('source_id')
      ->range(max(0, $offset), $limit)
      ->execute();
    $out = [];
    foreach ($rows as $row) {
      $out[] = ['id' => (int) $row->source_id, 'occurrences' => (int) $row->occurrences];
    }
    return $out;
  }

  /**
   * How many pages link to a title.
   */
  public function countBacklinks(string $title_key): int {
    return (int) $this->database->select(self::LINK_TABLE, 'l')
      ->condition('target_key', $title_key)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Linked-but-unwritten titles, most-linked first.
   *
   * The best "what should I write next" signal a KB has, and it costs one
   * query: a link row with no matching title row is by definition a page
   * somebody wanted and nobody has written.
   *
   * @param int|null $project_id
   *   Restrict to titles wanted by pages in one project. A wanted title has no
   *   project of its own — it has no page — so the filter is on the pages doing
   *   the wanting, which is the question worth asking.
   * @param int $limit
   *   Titles to return.
   * @param int $offset
   *   Skip this many; negative values are clamped to zero.
   *
   * @return array
   *   list of ['key' => string, 'title' => string, 'links' => int].
   */
  public function wantedTitles(?int $project_id = NULL, int $limit = 25, int $offset = 0): array {
    $select = $this->database->select(self::LINK_TABLE, 'l');
    $select->leftJoin(self::TITLE_TABLE, 't', 'l.target_key = t.title_key');
    $select->isNull('t.page_id');
    $this->restrictToProject($select, $project_id);
    $select->addField('l', 'target_key');
    // MIN over the typed forms, so the label is at least deterministic when
    // two pages spelled the same wanted title differently.
    $select->addExpression('MIN(l.target_text)', 'target_text');
    $select->addExpression('COUNT(DISTINCT l.source_id)', 'links');
    $select->groupBy('l.target_key');
    $select->orderBy('links', 'DESC');
    $select->orderBy('l.target_key');
    $select->range(max(0, $offset), $limit);

    $out = [];
    foreach ($select->execute() as $row) {
      $out[] = [
        'key' => (string) $row->target_key,
        'title' => (string) $row->target_text,
        'links' => (int) $row->links,
      ];
    }
    return $out;
  }

  /**
   * How many distinct wanted titles exist, for truncation notes.
   */
  public function countWantedTitles(?int $project_id = NULL): int {
    $select = $this->database->select(self::LINK_TABLE, 'l');
    $select->leftJoin(self::TITLE_TABLE, 't', 'l.target_key = t.title_key');
    $select->isNull('t.page_id');
    $this->restrictToProject($select, $project_id);
    $select->addField('l', 'target_key');
    $select->groupBy('l.target_key');
    return (int) $select->countQuery()->execute()->fetchField();
  }

  /**
   * Narrows a link query to the pages of one project.
   *
   * Reads the entity base table directly, which is only safe because the
   * column name is derivable: DefaultTableMapping::getFieldColumnName() keeps
   * the field name for single-column fields, so `project` is `project`. If that
   * entity reference ever gains a second stored property the column silently
   * becomes `project__target_id` and this breaks — go through
   * getTableMapping()->getFieldColumnName() if that day comes.
   */
  private function restrictToProject(SelectInterface $select, ?int $project_id): void {
    if ($project_id === NULL) {
      return;
    }
    $select->join('petrichor_wiki_page', 'p', 'l.source_id = p.id');
    $select->condition('p.project', $project_id);
  }

  /**
   * Drops every trace of a page from the index.
   *
   * Only the page's own rows go. Rows in other pages that pointed *at* it stay
   * and become wanted links — deleting a page should leave a visible hole, not
   * quietly erase the fact that four other pages referenced it.
   */
  public function forget(int $page_id): void {
    $this->database->delete(self::LINK_TABLE)
      ->condition('source_id', $page_id)
      ->execute();
    $this->database->delete(self::TITLE_TABLE)
      ->condition('page_id', $page_id)
      ->execute();
  }

  /**
   * Empties both tables, for a full rebuild.
   */
  public function truncate(): void {
    $this->database->truncate(self::LINK_TABLE)->execute();
    $this->database->truncate(self::TITLE_TABLE)->execute();
  }

}
