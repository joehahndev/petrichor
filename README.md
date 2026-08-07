# Petrichor

Project management, a markdown knowledge base, and persistent AI memory — as
Drupal content entities, with an MCP server so agents are first-class users
rather than an afterthought bolted onto a REST API.

Petrichor is built around one rule: **every MCP tool delegates to the same
domain service the UI calls.** There is no second implementation to fall behind,
which is the usual fate of an API maintained beside an application rather than
underneath it.

## Requirements

- Drupal 11.1 or newer
- PHP 8.3+
- `league/commonmark` ^2.6 (installed automatically via Composer)

Optional: [Key authentication](https://www.drupal.org/project/key_auth) for
per-agent API keys on the MCP endpoint. Without it the endpoint still
authenticates over `basic_auth` and session cookies.

## Installation

```
composer require drupal/petrichor
drush en petrichor petrichor_projects petrichor_tasks petrichor_mcp
```

Enable only the submodules you want — see the table below. The MCP tool registry
collects providers per submodule, so the tool surface is exactly what you
enabled.

## Submodules

| Module | Provides |
|---|---|
| `petrichor` | Shared entity base class, access handler, permissions, dashboard |
| `petrichor_projects` | Project entity — the scope everything else hangs off |
| `petrichor_tasks` | Tasks: status, priority, assignee, subtasks, blocked flag |
| `petrichor_milestones` | Milestones; attaches a milestone field to tasks |
| `petrichor_timesheets` | Time entries against tasks |
| `petrichor_comments` | Worklog comments and a project activity feed |
| `petrichor_board` | Drag-and-drop kanban board, built on `core/sortable` |
| `petrichor_markdown` | A "Markdown" text format via `league/commonmark` |
| `petrichor_wiki` | Hierarchical KB pages with `[[wiki links]]`, backlinks, revisions |
| `petrichor_memory` | Persistent, project-scoped memory for AI agents |
| `petrichor_mcp` | MCP server at `/mcp` (Streamable HTTP, JSON-RPC) |

`petrichor_projects` is the join key. Tasks, wiki pages and memories all scope to
a project, which is what makes a single context call possible.

## Configuration

Permissions live at `/admin/people/permissions`:

- `administer petrichor` — full control, including configuration
- `manage petrichor content` — create and edit anything in the suite
- `delete petrichor content` — delete; deliberately **separate** from manage
- `view petrichor content` — read-only, including the dashboard
- `access petrichor mcp` — call the MCP endpoint

Entity access applies on top of MCP access. A role holding `access petrichor mcp`
plus `view petrichor content` gets a read-only agent; that is the recommended
shape for anything autonomous.

**Do not grant `delete petrichor content` to an agent role.** Nothing in the MCP
surface hard-deletes by design — `forget` archives rather than removes — and that
guarantee is enforced in the access handler rather than in shipped config, so it
survives an à-la-carte install.

The PM dashboard is at `/pm`, the knowledge base at `/kb`.

## Theming

The suite ships no theme. Module CSS reads design tokens with fallbacks
(`var(--petrichor-surface, #fff)`), so it inherits your theme's look by default
and picks up the token contract if you define one. Site-wide opinions — a default
theme, a front page — belong in a recipe, never in a module's install hook.

## Running the checks locally

drupal.org runs eight CI jobs against this module. All eight can be run before
pushing, via [ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib),
which assembles a Drupal site around the module and mirrors the pipeline:

```
ddev config --project-type=drupal11 --docroot=web
ddev add-on get ddev/ddev-drupal-contrib
ddev start && ddev poser && ddev symlink-project
ddev exec "cd web/core && yarn install"   # eslint and stylelint need this
```

Then `ddev phpcs`, `ddev phpstan`, `ddev phpunit`, `ddev eslint`, `ddev stylelint`.
Everything it generates is gitignored.

Two things worth knowing, both learned the hard way:

- Core's frontend tooling needs **yarn**. `npm ci` in `web/core` exits 0 and
  installs nothing, leaving the linters silently unavailable.
- Re-run `ddev symlink-project` after adding a file to the module root, or the
  assembled site will not see it.

## Status

This is a `-dev` release and the honest limits are worth stating:

- It has had one user. Every ergonomic decision came from watching one person work.
- It has been exercised on SQLite. Other drivers are untested, not unsupported.
- The à-la-carte install path is architected for and not yet verified end to end.

## Maintainers

- Joe Hahn — [joehahn](https://www.drupal.org/u/joehahn)
