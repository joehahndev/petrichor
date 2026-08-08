<?php

declare(strict_types=1);

namespace Drupal\petrichor_memory;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\petrichor\ProvenanceTrait;

/**
 * Domain service for agent memory.
 */
class MemoryService {

  use ProvenanceTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Storage handler for memory entities.
   */
  protected function storage() {
    return $this->entityTypeManager->getStorage('petrichor_memory');
  }

  /**
   * Stores a fact, project-scoped when a project id is given.
   *
   * The site's memory_policy decides whether the result is immediately
   * recallable. The returned stub carries a `note` explaining what happened
   * and what the caller should do about it — an agent should not have to know
   * the policy in advance, or carry it between sessions, to behave correctly.
   */
  public function remember(array $values): array {
    if (empty($values['title']) || empty($values['fact'])) {
      throw new \InvalidArgumentException('title and fact are required.');
    }
    $policy = $this->policy();
    if ($policy === 'on_demand' && empty($values['requested'])) {
      throw new \InvalidArgumentException(
        'This site records memories only when the user asks. If they did ask, call remember again with requested: true; otherwise suggest it and wait.'
      );
    }
    $memory = $this->storage()->create([
      'label' => $values['title'],
      'fact' => ['value' => $values['fact'], 'format' => 'markdown'],
      'project' => $values['project_id'] ?? NULL,
      'type' => $values['type'] ?? 'learning',
      'tags' => $values['tags'] ?? [],
      'status' => $policy === 'ask' ? 'proposed' : 'active',
    ]);
    if (!$memory->access('create')) {
      throw new \InvalidArgumentException('Not allowed to create memories.');
    }
    $memory->save();

    $stub = $this->stub($memory);
    $stub['note'] = match ($policy) {
      'ask' => 'Proposed, not yet active: it will not appear in recall or any project context until confirmed. Tell the user what you recorded and why, so they can confirm or discard it.',
      default => 'Recorded and active: it will surface in future recalls for this scope.',
    };
    return $stub;
  }

  /**
   * Confirms a proposed memory, making it recallable.
   */
  public function confirmMemory(int $id): array {
    $memory = $this->storage()->load($id);
    if (!$memory || !$memory->access('update')) {
      throw new \InvalidArgumentException('Memory not found or not editable.');
    }
    if ($memory->get('status')->value !== 'proposed') {
      throw new \InvalidArgumentException(sprintf(
        'Memory %d is %s, not proposed; there is nothing to confirm.',
        $id,
        $memory->get('status')->value,
      ));
    }
    $memory->set('status', 'active');
    $memory->save();
    return ['id' => (int) $memory->id(), 'status' => 'active'];
  }

  /**
   * The site's memory policy: 'automatic', 'ask', or 'on_demand'.
   */
  protected function policy(): string {
    $policy = (string) $this->configFactory->get('petrichor.settings')->get('memory_policy');
    return in_array($policy, ['automatic', 'ask', 'on_demand'], TRUE) ? $policy : 'ask';
  }

  /**
   * Keyword recall: scope matches rank first, then recall/changed recency.
   *
   * The one entry point that records a recall. Everything else that reads
   * memories does so through matchMemories() and leaves the table alone.
   */
  public function recall(string $keywords, ?int $project_id = NULL, int $limit = 10): array {
    if (trim($keywords) === '') {
      throw new \InvalidArgumentException('query is required.');
    }
    // One term, not tokenised: a deliberate query is a phrase the caller
    // chose, and splitting it would quietly widen what they asked for. Fact
    // bodies are in scope because somebody asked — see relatedTo() for why
    // passive surfacing cannot afford them.
    [$memories, $total] = $this->matchMemories(
      [$keywords],
      $project_id,
      [],
      $limit,
      ['label', 'fact', 'tags'],
      1,
    );

    $this->touchRecalled(array_map(fn($m) => (int) $m->id(), $memories));
    $items = array_map(function ($memory) {
      $stub = $this->stub($memory);
      $stub['fact'] = $memory->get('fact')->value;
      return $stub;
    }, $memories);
    return $this->listResult($items, $total);
  }

  /**
   * Memories worth knowing before touching something — id and title only.
   *
   * The read-only sibling of recall(), for passive surfacing on reads the
   * user never asked to search: same matching, same ranking, no write.
   *
   * It must not call touchRecalled(). last_recalled means "somebody went
   * looking and this answered", and it feeds the recency half of the ranking
   * above. Bumping it every time any task is read would flatten that signal
   * with hits nobody asked for and nobody acted on — the same trap as a
   * derived value written on read, which this suite has now hit three times.
   *
   * Bodies are left out deliberately. Three titles cost roughly forty tokens
   * on a call agents make constantly, and recall() is one hop away when one
   * of them turns out to matter.
   *
   * $keywords here is a title, not a query — the caller passes whatever the
   * thing is called and gets nothing back rather than an error when that says
   * too little to search on.
   *
   * Two rules keep this honest, both learned by running it against real data
   * and getting three-quarters of tasks matched to memories about something
   * else — a field that is usually wrong is worse than no field, because it
   * teaches the reader to skip it:
   *
   * - Titles and tags are searched, fact bodies are not. A body is prose, and
   *   prose about storage mentions building, modules and data in passing; a
   *   memory's title is the one place somebody said what it is about.
   * - A title offering two or more terms must match two of them. One shared
   *   common word is a coincidence at this corpus size, and the corpus is
   *   small enough that coincidences outnumber the real hits.
   *
   * @return array<int, array{id: int, title: string}>
   *   Matching memories, most relevant first; empty when nothing matches.
   */
  public function relatedTo(string $keywords, ?int $project_id = NULL, array $types = ['decision', 'preference'], int $limit = 3): array {
    $terms = $this->terms($keywords);
    if (!$terms) {
      return [];
    }
    [$memories] = $this->matchMemories(
      $terms,
      $project_id,
      $types,
      $limit,
      ['label', 'tags'],
      count($terms) >= 2 ? 2 : 1,
    );
    return array_map(fn($m) => ['id' => (int) $m->id(), 'title' => (string) $m->label()], $memories);
  }

  /**
   * Words worth searching on, out of a title nobody wrote as a query.
   *
   * Matching a whole title as one substring finds nothing: "Comment storage
   * rewrite" only hits a memory that contains that exact phrase, and titles do
   * not repeat between a task and the decision about it. So it is split, and
   * any term matching is a hit — matching more of them ranks higher.
   *
   * Short words and a handful of common ones are dropped because they match
   * everything and rank nothing. The list is deliberately small: this is
   * substring matching over a few hundred rows, not a search index, and a
   * longer stop-word list would be pretending otherwise.
   *
   * @return string[]
   *   Lowercased terms, deduplicated; empty when the title says too little.
   */
  protected function terms(string $text): array {
    $stop_words = [
      'about', 'after', 'also', 'been', 'before', 'both', 'each', 'from',
      'have', 'into', 'just', 'like', 'made', 'make', 'more', 'only', 'onto',
      'over', 'some', 'such', 'than', 'that', 'then', 'their', 'there', 'this',
      'used', 'using', 'were', 'what', 'when', 'which', 'while', 'will',
      'with', 'your',
    ];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = array_filter($words, fn(string $w) => mb_strlen($w) >= 4 && !in_array($w, $stop_words, TRUE));
    return array_values(array_unique($terms));
  }

  /**
   * Active memories matching any term, ranked and sliced. Reads only.
   *
   * Ranking, in order: how many of the terms a memory matches, then scope
   * (project-scoped above global), then recency. Term count comes first
   * because with a multi-term title a memory that matches two of them is
   * about the subject, and one that matches a single common word is not.
   *
   * With a project id the result includes globals — a fact that applies
   * everywhere applies here too. Recency takes the later of last-recalled and
   * changed, so a memory nobody has recalled yet still has a position.
   *
   * @param string[] $terms
   *   Search terms; any one matching is a hit.
   * @param int|null $project_id
   *   Restrict to this project plus globals, or NULL for every scope.
   * @param string[] $types
   *   Restrict to these memory types, or empty for all of them.
   * @param int $limit
   *   How many to return.
   * @param string[] $fields
   *   Which fields to search: any of 'label', 'fact', 'tags'.
   * @param int $min_terms
   *   How many distinct terms a memory must match to qualify. Above 1 the
   *   returned total counts qualifying matches within the first 100 rows the
   *   query returned, not every row in the table.
   *
   * @return array{0: array<int, object>, 1: int}
   *   The ranked page, and the total number of matches before slicing.
   */
  protected function matchMemories(array $terms, ?int $project_id, array $types, int $limit, array $fields, int $min_terms): array {
    $terms = array_values(array_filter(array_map(trim(...), $terms), fn(string $t) => $t !== ''));
    if (!$terms) {
      return [[], 0];
    }
    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('status', 'active');
    $group = $query->orConditionGroup();
    foreach ($terms as $term) {
      $like = '%' . $this->database->escapeLike($term) . '%';
      foreach ($fields as $field) {
        $group->condition($field, $like, 'LIKE');
      }
    }
    $query->condition($group);
    if ($types) {
      $query->condition('type', $types, 'IN');
    }
    if ($project_id !== NULL) {
      $scope = $query->orConditionGroup()
        ->condition('project', $project_id)
        ->notExists('project');
      $query->condition($scope);
    }
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->range(0, 100)->execute();
    $memories = array_values($this->storage()->loadMultiple($ids));

    $matched = [];
    foreach ($memories as $memory) {
      $matched[(int) $memory->id()] = $this->termsMatched($memory, $terms, $fields);
    }
    if ($min_terms > 1) {
      $memories = array_values(array_filter(
        $memories,
        fn($m) => $matched[(int) $m->id()] >= $min_terms,
      ));
      $total = count($memories);
    }
    $recalled = $this->lastRecalledMap(array_keys($matched));
    usort($memories, function ($a, $b) use ($project_id, $recalled, $matched) {
      $terms_matched = $matched[(int) $b->id()] <=> $matched[(int) $a->id()];
      if ($terms_matched !== 0) {
        return $terms_matched;
      }
      if ($project_id !== NULL) {
        $scope = (int) ((bool) $b->get('project')->target_id) <=> (int) ((bool) $a->get('project')->target_id);
        if ($scope !== 0) {
          return $scope;
        }
      }
      $a_recency = max($recalled[(int) $a->id()] ?? 0, (int) $a->get('changed')->value);
      $b_recency = max($recalled[(int) $b->id()] ?? 0, (int) $b->get('changed')->value);
      return $b_recency <=> $a_recency;
    });
    return [array_slice($memories, 0, $limit), $total];
  }

  /**
   * How many of the terms this memory matches, over the given fields.
   *
   * Takes the same field list the query used, so the score cannot rank a row
   * the database matched as matching nothing.
   */
  protected function termsMatched($memory, array $terms, array $fields): int {
    $values = [
      'label' => (string) $memory->label(),
      'fact' => (string) $memory->get('fact')->value,
      'tags' => implode(' ', array_column($memory->get('tags')->getValue(), 'value')),
    ];
    $haystack = mb_strtolower(implode(' ', array_intersect_key($values, array_flip($fields))));
    $hits = array_filter($terms, fn(string $t) => str_contains($haystack, mb_strtolower($t)));
    return count($hits);
  }

  /**
   * Archives a memory. There is no hard delete on the wire.
   *
   * Maintainer-tier only: contributors remember and recall, but removing
   * facts from the active set is a deliberate promotion (threat model).
   */
  public function forget(int $id): array {
    if (!$this->currentUser->hasPermission('archive petrichor memories')) {
      throw new \InvalidArgumentException('forget requires the agent_maintainer role (archive petrichor memories permission).');
    }
    $memory = $this->storage()->load($id);
    if (!$memory || !$memory->access('update')) {
      throw new \InvalidArgumentException('Memory not found or not editable.');
    }
    $memory->set('status', 'archived');
    $memory->save();
    return ['id' => (int) $memory->id(), 'status' => 'archived'];
  }

  /**
   * Lists memories.
   *
   * With include_global, a project filter also returns global memories —
   * matching recall()'s semantics, so a fact that applies everywhere isn't
   * invisible to project-scoped callers.
   */
  public function listMemories(array $filters = [], int $limit = 50): array {
    $query = $this->storage()->getQuery()->accessCheck(TRUE)
      ->condition('status', $filters['status'] ?? 'active');
    if (!empty($filters['project_id'])) {
      $project_id = (int) $filters['project_id'];
      if (!empty($filters['include_global'])) {
        $scope = $query->orConditionGroup()
          ->condition('project', $project_id)
          ->notExists('project');
        $query->condition($scope);
      }
      else {
        $query->condition('project', $project_id);
      }
    }
    if (!empty($filters['type'])) {
      $query->condition('type', $filters['type']);
    }
    $offset = $this->offset($filters);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->range($offset, $limit)->execute();
    $items = array_map($this->stub(...), array_values($this->storage()->loadMultiple($ids)));
    return $this->listResult($items, $total, $offset);
  }

  /**
   * Last-recall timestamps keyed by memory id, for the ids that have one.
   *
   * Recall recency lives in a plain table rather than on the entity, so that
   * reading a memory does not bump the `changed` timestamp that recall()
   * falls back to for memories nobody has recalled yet.
   */
  protected function lastRecalledMap(array $ids): array {
    if (!$ids) {
      return [];
    }
    return $this->database->select('petrichor_memory_recall', 'r')
      ->fields('r', ['memory_id', 'last_recalled'])
      ->condition('memory_id', $ids, 'IN')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Marks these memories as recalled now.
   *
   * Merge rather than insert: a memory is recalled repeatedly and only the
   * most recent time is ever read back.
   */
  protected function touchRecalled(array $ids): void {
    $now = $this->time->getRequestTime();
    foreach ($ids as $id) {
      $this->database->merge('petrichor_memory_recall')
        ->key('memory_id', $id)
        ->fields(['last_recalled' => $now])
        ->execute();
    }
  }

  /**
   * The wire shape of a memory: base stub plus scope, type, tags and status.
   *
   * The fact body is deliberately absent. recall() adds it back for the rows
   * it returns; listing stays light.
   */
  protected function stub($memory): array {
    return $this->baseStub($memory) + [
      'type' => $memory->get('type')->value,
      'project_id' => $memory->get('project')->target_id ? (int) $memory->get('project')->target_id : NULL,
      'tags' => array_column($memory->get('tags')->getValue(), 'value'),
      'status' => $memory->get('status')->value,
    ];
  }

}
