<?php

declare(strict_types=1);

namespace Drupal\petrichor_board\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\petrichor_board\BoardService;
use Drupal\petrichor_tasks\TaskService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP surface for the kanban board and the Today view.
 *
 * Holds no domain logic: BoardService owns ordering and persistence,
 * TaskService owns the focus set. What lives here is the mapping between
 * their array shapes and the components' prop shapes, plus the error
 * vocabulary the board's JS reconciles against.
 */
final class BoardController extends ControllerBase {

  public function __construct(
    protected BoardService $boards,
    protected TaskService $tasks,
    protected CsrfTokenGenerator $csrfToken,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('petrichor_board.service'),
      $container->get('petrichor_tasks.service'),
      $container->get('csrf_token'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders one project's board.
   */
  public function board(EntityInterface $petrichor_project): array {
    $board = $this->boards->getBoard((int) $petrichor_project->id());
    $columns = [];
    foreach ($board['columns'] as $column) {
      // Column labels come off the status field's allowed_values, so they
      // are TranslatableMarkup, not string — and the component's prop schema
      // says string. Cast at the boundary rather than teaching the schema
      // about markup objects.
      $columns[] = [
        'id' => (string) $column['id'],
        'label' => (string) $column['label'],
        'cards' => $this->cards($column['cards']),
        'total' => (int) $column['total'],
        'note' => isset($column['note']) ? (string) $column['note'] : NULL,
      ];
    }

    return [
      'board' => [
        '#type' => 'component',
        '#component' => 'petrichor_board:board',
        '#props' => [
          'project' => [
            'id' => (int) $board['project']['id'],
            'title' => (string) $board['project']['title'],
          ],
          'columns' => $columns,
          // The constant, never the literal: CsrfRequestHeaderAccessCheck
          // validates against 'X-CSRF-Token request header', and a token
          // minted under any other key fails every POST with a bare 403.
          'csrf_token' => $this->csrfToken->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY),
          'empty_text' => (string) $this->t('Nothing here'),
        ],
      ],
      '#cache' => [
        // petrichor_board_order is this module's own tag: a pure reorder
        // writes only the side table, so nothing invalidates the entity
        // list tag and the board would render stale.
        'tags' => ['petrichor_task_list', 'petrichor_board_order'],
        // 'session' because the CSRF token above is session-scoped —
        // handing user B a cached copy of user A's token 403s every drop.
        'contexts' => ['user', 'session'],
        // Cards carry `overdue`, resolved against today's date. Nothing
        // writes at midnight, so without this the board keeps yesterday's
        // answer until someone happens to save a task.
        'max-age' => $this->secondsUntilMidnight(),
      ],
    ];
  }

  /**
   * Renders the cross-project Today view.
   */
  public function today(): array {
    $focus = $this->tasks->getFocusSet((int) $this->currentUser()->id());

    return [
      'today' => [
        '#type' => 'component',
        '#component' => 'petrichor_board:today',
        '#props' => [
          'items' => $this->cards($focus['items'] ?? []),
          'as_of' => (string) ($focus['as_of'] ?? date('Y-m-d')),
          'note' => isset($focus['note']) ? (string) $focus['note'] : NULL,
          'empty_text' => (string) $this->t('Nothing needs you today.'),
        ],
      ],
      '#cache' => [
        // A per-user list. 'user.permissions' is not enough — two accounts
        // with identical permissions have different focus sets, and dynamic
        // page cache will happily serve one to the other.
        'contexts' => ['user'],
        'tags' => ['petrichor_task_list'],
        // as_of, overdue, and the 7-day horizon are all date-derived; the
        // focus set changes at midnight with no write to invalidate it.
        'max-age' => $this->secondsUntilMidnight(),
      ],
    ];
  }

  /**
   * Cache lifetime for date-derived output, in seconds.
   */
  protected function secondsUntilMidnight(): int {
    $now = $this->time->getRequestTime();
    return max(60, strtotime('tomorrow midnight', $now) - $now);
  }

  /**
   * Persists a card drop or a keyboard move.
   */
  public function move(Request $request, EntityInterface $petrichor_task): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['status']) || !is_string($payload['status'])) {
      return $this->fail(400, 'bad_request', 'Expected a JSON object with a status.');
    }

    try {
      // isset() rather than array_key_exists(): a null neighbour and an
      // absent one both mean "no card on that side".
      $result = $this->boards->moveCard(
        (int) $petrichor_task->id(),
        $payload['status'],
        isset($payload['after_id']) ? (int) $payload['after_id'] : NULL,
        isset($payload['before_id']) ? (int) $payload['before_id'] : NULL,
        isset($payload['if_unchanged_since']) ? (string) $payload['if_unchanged_since'] : NULL,
      );
    }
    catch (\DomainException $e) {
      return $this->fail(422, 'invalid_status', $e->getMessage());
    }
    catch (\InvalidArgumentException $e) {
      // BoardService and TaskService both signal not-found and conflict with
      // this one exception type; the message is the only discriminator.
      if (stripos($e->getMessage(), 'conflict') !== FALSE) {
        // The card's data-changed is what tripped the guard, so the client
        // must be told the current value. Without it the card sends the same
        // stale timestamp forever and stays undraggable until a full reload.
        $response = $this->fail(409, 'conflict', $e->getMessage(), $this->knownOrder($petrichor_task, $payload['status']));
        $body = json_decode($response->getContent(), TRUE);
        $body['changed'] = date('c', (int) $petrichor_task->get('changed')->value);
        $body['status'] = $petrichor_task->get('status')->value;
        return $response->setData($body);
      }
      return $this->fail(404, 'not_found', $e->getMessage());
    }
    catch (\Throwable $e) {
      Error::logException($this->getLogger('petrichor_board'), $e);
      return $this->fail(500, 'error', 'Could not move the card.');
    }

    return $this->json($result);
  }

  /**
   * Maps a task stub onto the card component's prop shape.
   *
   * Runs on both paths so every prop key is present with an explicit value
   * whatever the source: getFocusSet() returns bare stubs, getBoard() returns
   * cards already most of the way there. The mapping is idempotent, so the
   * overlap costs a little work rather than correctness.
   *
   * Assignee names are resolved for the whole batch in one load; per-card
   * resolution would be a user query per card.
   */
  protected function cards(array $items): array {
    $uids = array_filter(array_column($items, 'assignee'));
    $users = $uids
      ? $this->entityTypeManager()->getStorage('user')->loadMultiple(array_unique($uids))
      : [];
    $today = date('Y-m-d');

    return array_map(function (array $item) use ($users, $today): array {
      $id = (int) $item['id'];
      $due = $item['due_date'] ?? NULL;
      $assignee = $item['assignee'] ?? NULL;
      return [
        'id' => $id,
        'title' => (string) $item['title'],
        'url' => Url::fromRoute('entity.petrichor_task.canonical', ['petrichor_task' => $id])->toString(),
        'move_url' => Url::fromRoute('petrichor_board.move', ['petrichor_task' => $id])->toString(),
        'status' => (string) $item['status'],
        'priority' => (string) $item['priority'],
        'due_date' => $due,
        // Plain string comparison against 'Y-m-d'; no date objects anywhere
        // on this path. getFocusSet() already computes it, getBoard() does
        // not, so accept either.
        'overdue' => (bool) ($item['overdue'] ?? ($due !== NULL && $due < $today)),
        'blocked' => (bool) ($item['blocked'] ?? FALSE),
        'blocked_note' => $item['blocked_note'] ?? NULL,
        'changed' => (string) ($item['changed'] ?? ''),
        'project_title' => $item['project_title'] ?? NULL,
        'reason' => $item['reason'] ?? NULL,
        'assignee_name' => isset($users[$assignee])
          ? (string) $users[$assignee]->getDisplayName()
          : NULL,
      ];
    }, array_values($items));
  }

  /**
   * The destination column's order, or NULL when it cannot be established.
   *
   * A conflict body must carry `order` so the client has one reconcile path
   * for success and failure alike — but computing it must never turn a 409
   * into a 500.
   */
  protected function knownOrder(EntityInterface $task, string $status): ?array {
    try {
      $project_id = (int) $task->get('project')->target_id;
      return $project_id ? $this->boards->columnOrder($project_id, $status) : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Builds an error response in the same shape as a successful one.
   */
  protected function fail(int $code, string $error, string $message, ?array $order = NULL): JsonResponse {
    $body = ['error' => $error, 'message' => $message];
    if ($order !== NULL) {
      $body['order'] = $order;
    }
    return $this->json($body, $code);
  }

  /**
   * Wraps a body as an uncacheable JSON response.
   */
  protected function json(array $body, int $code = 200): JsonResponse {
    $response = new JsonResponse($body, $code);
    $response->setMaxAge(0);
    return $response;
  }

}
