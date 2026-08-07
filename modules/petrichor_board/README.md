# Petrichor Board

Kanban board and Today view for Petrichor PM.

**TL;DR:** two routes and one POST endpoint over the existing task entity. Columns are the five `petrichor_task` statuses; dragging a card writes a status through `TaskService` and a weight into this module's own side table. UI is four Single Directory Components, one of which ships the suite's only JavaScript.

## Routes

| Path | What | Permission |
|---|---|---|
| `/pm/today` | Cross-project focus set for the current user | `view petrichor content` |
| `/pm/board/{petrichor_project}` | One project's board | `view petrichor content` + `petrichor_project.view` |
| `POST /pm/board/task/{petrichor_task}/move` | Persist a drop or a keyboard move | `manage petrichor content` + `petrichor_task.update` + CSRF header |

`/pm/today` is in the admin menu under Petrichor PM. The board is not — it needs a project, so it is reached from a project.

## The move endpoint

`Content-Type: application/json`, plus `X-CSRF-Token` taken from `data-petrichor-csrf` on the board root. The token is minted server-side under `\Drupal\Core\Access\CsrfRequestHeaderAccessCheck::TOKEN_KEY` — it is **not** the `/session/token` value, and a token minted under any other key fails every POST with a bare 403.

```json
{ "status": "in_progress", "after_id": 7, "before_id": 19, "if_unchanged_since": "2026-08-06T09:12:44+00:00" }
```

Neighbours are card IDs, not indices — an agent inserting a task mid-drag shifts indices but not identities. `null` on either side means "end of column". `if_unchanged_since` must be echoed back **verbatim** from the card's `data-changed`; `TaskService::updateTask()` compares it as a raw string.

A 200 returns `{task, status, order, changed}` where `order` is the destination column's authoritative ID list. Failures return the **same shape** — `{error, message, order?}` — so the client has one reconcile path rather than two:

| HTTP | `error` | Cause |
|---|---|---|
| 400 | `bad_request` | Malformed JSON, or no `status`. |
| 403 | — | Framework-level: permission, CSRF, entity access. No JSON body guaranteed. |
| 404 | `not_found` | Task missing or not viewable. |
| 409 | `conflict` | `if_unchanged_since` tripped. Always carries `order`. |
| 422 | `invalid_status` | `status` is not a key of `TaskService::statusOptions()`. |
| 500 | `error` | Anything else. Generic message; details go to the `petrichor_board` logger. |

422 exists because `updateTask()` sets fields with no whitelist and never calls `validate()`, and SQLite has no CHECK constraint on a `list_string` — without the guard a hand-crafted POST would persist `status: "bogus"`.

## Card order

Order lives in `petrichor_board_order` (`task_id` PK, `weight` int), not on the task entity. An entity field would make every drag a save, bumping `changed` — which `list_tasks` sorts by, `get_project_context` reports as activity, and `if_unchanged_since` uses for conflict detection. Same reasoning as `petrichor_memory_recall`.

Weights are sparse integers spaced 1024, inserted at the midpoint of their neighbours, so one drop writes one row. A rebalance only fires when a gap closes — roughly one drop in ten thousand — and is scoped to a single column, because SQLite takes a database-wide write lock and a whole-board renumber inside a transaction would block an in-flight MCP write.

**A card with no row sorts first in its column**, tie-broken `changed DESC`. `petrichor_board.module` deletes a card's row whenever its status changes anywhere other than the board, so a task an agent flips to `done` lands at the top of Done rather than at a stale historical position.

Consequences, both accepted: order is invisible to Views, JSON:API and `list_tasks` (no SQL join reaches it), and columns are capped at 25 with the remainder announced in the column header.

## Components

`board`, `column`, `card`, `today` — in `components/`. `card` is shared by the board and Today, which is why each component carries its own CSS: Today gets card styling without loading board layout.

Only `board` ships JS (`board.js`, drag via `core/sortable` plus a keyboard path). Its `libraryOverrides.dependencies` block is **mandatory** — without it the generated library has zero dependencies and `Drupal`, `once` and `Sortable` are all undefined at runtime.

Prop schemas are a BC contract from the moment this ships: `SchemaCompatibilityChecker` runs outside `assert()`, so a removed or narrowed prop breaks cache rebuild on any site with a theme override. Additive changes only.

The zero-JS floor is the card title link: it opens the task edit form, which has a status select.

## Known rough edge

Card links land in Claro. `Task.php` uses `AdminHtmlRouteProvider`, which marks canonical and edit routes as admin routes. Cosmetic, deliberately not fixed here — switching route providers as a drive-by would change existing behaviour.
