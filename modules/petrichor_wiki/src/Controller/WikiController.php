<?php

declare(strict_types=1);

namespace Drupal\petrichor_wiki\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\petrichor_wiki\WikiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * HTTP surface for reading the knowledge base.
 *
 * Holds no domain logic and issues no entity queries: WikiService owns the
 * tree, the breadcrumb chain, backlinks and the wanted-page report. What lives
 * here is the mapping between those array shapes and the components' prop
 * shapes, plus the one thing the service deliberately does not carry — the
 * text format the body renders through, which is a presentation concern.
 *
 * Bodies are handed to the components as a `processed_text` render array in a
 * slot, never as a pre-rendered string. Wiki links resolve at filter time and
 * bubble their own cache tags out of FilterProcessResult; check_markup() here
 * would strand those tags and leave every page holding a stale "wanted" link
 * after the page it wanted got written.
 */
final class WikiController extends ControllerBase {

  /**
   * Backlinks shown inline on a page before deferring to the full list.
   *
   * Small on purpose: "what links here" is orientation, not an index. A hub
   * page with sixty inbound links should not bury its own children.
   */
  protected const BACKLINKS_INLINE = 10;

  /**
   * Rows on the standalone list pages.
   *
   * There is no pager. A single-user KB does not have a hundred-and-first
   * backlink worth clicking through for, and the service's truncation note
   * says so honestly rather than silently showing a worse page 2.
   */
  protected const LIST_LIMIT = 100;

  public function __construct(
    protected readonly WikiService $wiki,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('petrichor_wiki.service'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The KB index: the whole visible page tree.
   */
  public function index(): array {
    // Unpruned: no active page, no root, no depth limit — so getTree() also
    // promotes any page caught in a parent cycle to a root, and the index is
    // the one surface where every page is guaranteed to appear.
    $tree = $this->wiki->getTree();

    return [
      'tree' => [
        '#type' => 'component',
        '#component' => 'petrichor_wiki:wiki_tree',
        '#props' => [
          'tree' => $this->branch($tree['items']),
          'label' => (string) $this->t('Knowledge base pages'),
          'empty_text' => (string) $this->t('No pages yet. The first one is the hardest.'),
          'note' => isset($tree['note']) ? (string) $tree['note'] : NULL,
        ],
        '#slots' => [
          'footer' => [
            '#type' => 'link',
            '#title' => $this->t('Pages other pages are waiting for'),
            '#url' => Url::fromRoute('entity.petrichor_wiki_page.wanted'),
          ],
        ],
      ],
      '#cache' => [
        // Coarse and correct: core invalidates entity list tags on insert AND
        // update, so any title, parent or weight change — the only three
        // fields the tree reads — busts this.
        'tags' => ['petrichor_wiki_page_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * One page: breadcrumb, body, children, and what links here.
   */
  public function page(EntityInterface $petrichor_wiki_page): array {
    $id = (int) $petrichor_wiki_page->id();
    $page = $this->wiki->getWikiPage($id);

    // getWikiPage() has already fetched backlinks — asking again would repeat
    // the whole lookup for the same rows. It caps them itself and says so in
    // backlinks_note, which is the only signal here that there are more; the
    // service returns the rows bare, without a total.
    $backlinks = $this->branch($page['backlinks'] ?? []);
    $has_more_backlinks = isset($page['backlinks_note']) || count($backlinks) > self::BACKLINKS_INLINE;

    // Omitted, never passed empty: ComponentElement runs every slot through
    // Element::isRenderArray(), which returns FALSE for [] and throws. An
    // absent slot falls through to the template's own empty block, which is
    // exactly the wanted behaviour for a reader with no update access.
    $slots = ['body' => $this->body($petrichor_wiki_page, (string) ($page['body'] ?? ''))];
    if ($petrichor_wiki_page->access('update')) {
      $slots['page_actions'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => $petrichor_wiki_page->toUrl('edit-form'),
        '#attributes' => ['class' => ['petrichor-wiki-page__action']],
      ];
    }

    return [
      'page' => [
        '#type' => 'component',
        '#component' => 'petrichor_wiki:wiki_page',
        '#props' => [
          'page' => [
            'title' => (string) $page['title'],
            'author_name' => isset($page['author']['name']) ? (string) $page['author']['name'] : NULL,
            'author_is_agent' => (bool) ($page['author']['is_agent'] ?? FALSE),
            'changed' => isset($page['changed']) ? (string) $page['changed'] : NULL,
            'changed_label' => $this->when($page['changed'] ?? NULL),
          ],
          'breadcrumb' => $this->breadcrumb($page, $id),
          'children' => $this->branch($page['children'] ?? []),
          'backlinks' => array_slice($backlinks, 0, self::BACKLINKS_INLINE),
          'backlinks_url' => $has_more_backlinks
            ? Url::fromRoute('entity.petrichor_wiki_page.backlinks', ['petrichor_wiki_page' => $id])->toString()
            : NULL,
          // The service's own words for both truncations. Rewriting them here
          // would be a second vocabulary for the same fact, and this one is
          // already the vocabulary agents read over MCP.
          'backlinks_note' => isset($page['backlinks_note']) ? (string) $page['backlinks_note'] : NULL,
          'note' => isset($page['navigation_note']) ? (string) $page['navigation_note'] : NULL,
        ],
        '#slots' => $slots,
      ],
      '#cache' => [
        // The entity's own tag covers the body and title. The list tag covers
        // the two derived sections: a new child, and any other page's body
        // gaining or losing a [[link]] here — both are entity writes, and both
        // invalidate the list tag.
        'tags' => array_merge($petrichor_wiki_page->getCacheTags(), ['petrichor_wiki_page_list']),
        // 'timezone' because changed_label is formatted for the reader, not
        // echoed as a raw timestamp; 'user.permissions' because the edit
        // action and the access-checked child list both vary by it.
        'contexts' => ['user.permissions', 'timezone'],
      ],
    ];
  }

  /**
   * Every page that links here.
   */
  public function backlinks(EntityInterface $petrichor_wiki_page): array {
    $id = (int) $petrichor_wiki_page->id();
    $result = $this->wiki->getBacklinks($id, self::LIST_LIMIT);
    $items = $this->items($result);

    return [
      'backlinks' => [
        '#type' => 'component',
        '#component' => 'petrichor_wiki:wiki_tree',
        '#props' => [
          'tree' => $items,
          'label' => (string) $this->t('Pages linking to @title', ['@title' => $petrichor_wiki_page->label()]),
          'empty_text' => (string) $this->t('Nothing links here yet.'),
          'note' => $this->note($result, count($items)),
        ],
        '#slots' => [
          'footer' => [
            '#type' => 'link',
            '#title' => $this->t('Back to @title', ['@title' => $petrichor_wiki_page->label()]),
            '#url' => $petrichor_wiki_page->toUrl(),
          ],
        ],
      ],
      '#cache' => [
        'tags' => array_merge($petrichor_wiki_page->getCacheTags(), ['petrichor_wiki_page_list']),
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Titles that pages link to but nobody has written.
   *
   * The single best "what should I write next" signal a wiki has, which is
   * why it gets a page rather than a corner of the index.
   */
  public function wanted(): array {
    $result = $this->wiki->listWantedPages(NULL, self::LIST_LIMIT);
    // Offering an add link to someone who cannot create pages is an offer of
    // a 403. The permission is the one checkCreateAccess() asks for.
    $may_create = $this->currentUser()->hasPermission('manage petrichor content');
    $items = array_map(
      fn (array $item): array => $this->wantedItem($item, $may_create),
      $this->rows($result),
    );

    return [
      'wanted' => [
        '#type' => 'component',
        '#component' => 'petrichor_wiki:wiki_tree',
        '#props' => [
          'tree' => $items,
          'label' => (string) $this->t('Wanted pages'),
          'empty_text' => (string) $this->t('Every link in the knowledge base has a page behind it.'),
          'note' => $this->note($result, count($items)),
        ],
        '#slots' => [
          'footer' => [
            '#type' => 'link',
            '#title' => $this->t('Back to the knowledge base'),
            '#url' => Url::fromRoute('entity.petrichor_wiki_page.collection'),
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['petrichor_wiki_page_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Title callback for the backlinks page.
   */
  public function backlinksTitle(EntityInterface $petrichor_wiki_page): string {
    return (string) $this->t('What links to @title', ['@title' => $petrichor_wiki_page->label()]);
  }

  /**
   * Renders one page's body through its own text format.
   *
   * A render array, not a string: the markdown filter resolves [[wiki links]]
   * during process() and returns their cache tags on the FilterProcessResult.
   * Those only reach the page's cache metadata if the renderer does the work.
   */
  protected function body(EntityInterface $page, string $text): array {
    if (trim($text) === '') {
      return [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['petrichor-wiki-page__empty']],
        '#value' => $this->t('This page has no content yet.'),
      ];
    }

    // The stored format, not a hard-coded 'markdown': a host site may have
    // pointed this field at its own format, and rendering someone's body
    // through a filter chain they did not choose is how raw HTML escapes.
    $item = $page->get('body')->first();

    return [
      '#type' => 'processed_text',
      '#text' => $text,
      '#format' => $item?->format ?: 'markdown',
      '#langcode' => $page->language()->getId(),
    ];
  }

  /**
   * The crumb trail, ending at the current page.
   *
   * Self-filtered before appending because the service's breadcrumb is
   * documented as root→parent but is cheap to make inclusive later; this way
   * the trail has exactly one current item either way.
   */
  protected function breadcrumb(array $page, int $id): array {
    $crumbs = [];
    foreach ($page['breadcrumb'] ?? [] as $crumb) {
      if ((int) ($crumb['id'] ?? 0) !== $id) {
        $crumbs[] = $this->link($crumb);
      }
    }
    $crumbs[] = [
      'id' => $id,
      'title' => (string) $page['title'],
      'url' => NULL,
      'wanted' => FALSE,
      'meta' => NULL,
      'children' => [],
    ];
    return $crumbs;
  }

  /**
   * Maps a nested service tree onto the component's node shape.
   */
  protected function branch(array $nodes): array {
    return array_values(array_map(fn (array $node): array => $this->link($node), $nodes));
  }

  /**
   * Maps one service stub onto a node.
   */
  protected function link(array $item): array {
    $id = (int) ($item['id'] ?? 0);
    return [
      'id' => $id,
      'title' => (string) ($item['title'] ?? ''),
      'url' => Url::fromRoute('entity.petrichor_wiki_page.canonical', ['petrichor_wiki_page' => $id])->toString(),
      'wanted' => FALSE,
      'meta' => NULL,
      'children' => $this->branch($item['children'] ?? []),
    ];
  }

  /**
   * Maps one wanted-page row onto a node.
   *
   * `wanted` is what the component styles differently — and it is the whole
   * point of this page: the title exists as an intention, not as a record.
   */
  protected function wantedItem(array $item, bool $may_create): array {
    $title = (string) ($item['title'] ?? '');
    // How badly the KB wants this page — a count of distinct pages linking to
    // it, which is also the order the service returned these rows in.
    $wanted_by = (int) ($item['wanted_by'] ?? 0);

    return [
      'id' => NULL,
      'title' => $title,
      // The add form does not read this query parameter today. It is passed
      // anyway because it costs nothing and is the obvious seam if it ever
      // learns to; the title is on screen to copy in the meantime.
      'url' => $may_create
        ? Url::fromRoute('entity.petrichor_wiki_page.add_form', [], ['query' => ['title' => $title]])->toString()
        : NULL,
      'wanted' => TRUE,
      'meta' => $wanted_by > 0
        ? (string) $this->formatPlural($wanted_by, '1 page links here', '@count pages link here')
        : NULL,
      'children' => [],
    ];
  }

  /**
   * Node list from either a listResult envelope or a bare array of stubs.
   */
  protected function items(array $result): array {
    return $this->branch($this->rows($result));
  }

  /**
   * Unwraps a ProvenanceTrait::listResult() envelope.
   */
  protected function rows(array $result): array {
    return array_values($result['items'] ?? $result);
  }

  /**
   * What a truncated list should admit to.
   *
   * There is no pager on these pages, so the note is the whole of the honesty
   * — the same bargain ProvenanceTrait::listResult() strikes with agents. When
   * the service has already worded it, that wording wins: one fact should not
   * have two vocabularies depending on whether a human or an agent asked.
   */
  protected function note(array $result, int $shown): ?string {
    if (isset($result['note'])) {
      return (string) $result['note'];
    }
    $total = (int) ($result['total'] ?? $shown);
    return $total > $shown
      ? (string) $this->t('Showing @shown of @total.', ['@shown' => $shown, '@total' => $total])
      : NULL;
  }

  /**
   * A stored ISO 8601 timestamp as a date a reader can place.
   */
  protected function when(?string $iso): ?string {
    if ($iso === NULL || ($timestamp = strtotime($iso)) === FALSE) {
      return NULL;
    }
    return $this->dateFormatter->format($timestamp, 'medium');
  }

}
