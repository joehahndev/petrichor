# Petrichor

> **Where this lives.** The project of record is [drupal.org/project/petrichor](https://www.drupal.org/project/petrichor): issues, releases and the GitLab pipeline. [github.com/joehahndev/petrichor](https://github.com/joehahndev/petrichor) is a read-only mirror of the same history.

**A place your AI assistant can do real work — and leave a record you can
check.**

Point Claude Code, or any MCP client, at your Drupal site. It reads the project
brief, the open tasks, the wiki page and the decisions already made, does the
work, and writes back what it did. You review it afterwards in a normal Drupal
UI, on entities you can extend with Field UI and list with Views.

Projects, a wiki and agent memory ship together for one reason: an
agent needs all three **in the same scope** to be useful. What am I working on,
what do we already know about it, and what did we decide last time are three
halves of one question. Split across three tools they are three logins, three id
schemes and no shared idea of a project. Here `petrichor_projects` is the join
key, which is what makes `get_project_context` possible — one call, and an agent
knows where it is.

Everything also works with no agent at all: it is a kanban board, a wiki and a
timesheet made of ordinary content entities.

---

## Install it

```
composer require drupal/petrichor
drush en petrichor petrichor_projects petrichor_tasks petrichor_board \
        petrichor_markdown petrichor_wiki petrichor_comments -y
```

That is the human half of the suite. Add the agent half in
[Connect an agent](#connect-an-agent) below, once you have something to look at.

Now visit **`/pm`**. On a fresh install it asks you to create a project, which
is the right next step — everything else in the suite lives inside one.

### Your first ten minutes

1. **Make a project.** Follow that button, or go to `/pm/project/add` — give it
   a title. The list is at `/pm/projects`, and `/pm` becomes a dashboard of
   project cards with open task counts once one exists.
2. **Add a few tasks.** `/pm/task/add`. Pick the project, set a status
   (backlog, todo, in progress, review, done) and a priority.
3. **Open the board.** `/pm/board/1` — the number is the project id, which is in
   the URL of the project you just made (`/pm/project/1`). Drag a card between
   columns; the move saves immediately.
4. **See what's next.** `/pm/today` collects in-progress work, anything overdue
   or due within a week, and everything high or urgent. It does *not* require
   due dates, on purpose — an empty "what should I do today" is worse than a
   slightly generous one.
5. **Write something down.** `/wiki` is the wiki; `/wiki/add` makes a page. Inside
   the body write `[[Another page]]`. That link works whether or not the page
   exists yet — if it doesn't, the link is dashed and clicking it opens the add
   form with the title filled in. `/wiki/wanted` lists every page something has
   linked to but nobody has written.
6. **Log a comment on a task.** The worklog is what makes the project activity
   feed worth reading, and it is what an agent appends to as it works.

Settings live at **Configuration → User interface → Petrichor PM**
(`/admin/config/user-interface/petrichor`).

---

## Connect an agent

### 1. Install key authentication

```
composer require drupal/key_auth
drush en key_auth -y
```

Not strictly required — the endpoint also accepts `basic_auth` and session
cookies — but a per-agent key is what lets you tell agents apart in the worklog
and revoke one without touching the others.

### 2. Create a user for the agent

One user **per agent**, not one shared "bot" account. Create it at
`/admin/people/create`, then give it exactly one role:

| Role you create | Permissions to give it |
|---|---|
| Agent (read-only) | `access petrichor mcp`, `view petrichor content`, `use key authentication` |
| Agent (contributor) | the above + `manage petrichor content` |
| Agent (maintainer) | the above + `archive petrichor memories` |

Read-only is the right starting point, and the right permanent answer for
anything running unattended.

> **Never give an agent role `delete petrichor content` or
> `administer petrichor`.** Nothing in the MCP surface hard-deletes — `forget`
> archives instead — and that guarantee is enforced in the access handler, so
> granting delete is the one thing that breaks it.

`key_auth` generates the key automatically when the user is saved. Find it on
the user's edit form at `/user/<uid>/edit`.

### 3. Point the client at the site

For Claude Code, add this to your MCP config (`~/.claude.json`, under
`mcpServers`):

```json
{
  "mcpServers": {
    "petrichor": {
      "type": "http",
      "url": "https://example.com/mcp",
      "headers": { "api-key": "THE_KEY_FROM_STEP_2" }
    }
  }
}
```

Any MCP client works — the endpoint speaks Streamable HTTP JSON-RPC and is not
Claude-specific.

Check it from the command line first, which tells you whether the problem is the
site or the client:

```
curl -X POST https://example.com/mcp \
  -H 'api-key: THE_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

A JSON list of tools means you are done. A 403 means the user is missing
`access petrichor mcp`; a 404 means `petrichor_mcp` is not enabled.

### 4. Try it

Ask the agent, in plain language:

- *"What am I working on?"* → `get_my_work`
- *"Get me up to speed on project 1."* → `get_project_context`, which returns the
  brief, open tasks, recent activity, milestones, the wiki index and the
  project's memories in one call
- *"Add a task to fix the contact form, high priority."*
- *"Log what you just did on task 4, and record 45 minutes against it."*

Those prove the connection works. [What to ask it](#what-to-ask-it) below is
the longer answer — the prompts that make the suite worth having.

### 5. Tell it which project it is in

An agent opening a code repository has no idea which Petrichor project that
repository is. The cheapest fix is a line in the repo's own agent instructions
file (`CLAUDE.md`, `AGENTS.md`, or whatever your client reads):

```
Petrichor project: 12 — Acme redesign
```

The client reads that file before its first tool call, so the id costs nothing.
Failing that, `list_projects` takes a `query` and will find a project by name or
client.

---

## What to ask it

Plain language is the interface. There is no command syntax to learn: the
client reads the tool descriptions and maps what you said onto them, so *"what's
overdue on the redesign?"* becomes a filtered `list_tasks` call without you
knowing that tool exists. The one exception is the three server-side prompts —
`daily_standup`, `weekly_review` and `project_status` — which Claude Code
surfaces as slash commands (`/mcp__petrichor__daily_standup` and friends), and
other clients expose however they expose MCP prompts.

What follows is not a syntax reference, then, but a working rhythm — the
prompts that turn a task list an agent can see into a record that pays for
itself.

**Opening a session.** Start with *"get me up to speed on project 12"*
(`get_project_context`): the brief, open tasks, recent activity, milestones,
the wiki index and the project's memories, in one call tuned to cost a few
thousand tokens. Start a day with *"what should I work on?"* (`get_my_work`) —
overdue and in-progress first, blocked work last, no due dates required.

**While working.** *"Add a task to migrate the webform config, high priority,
due Friday."* *"Break that into subtasks."* *"Mark it blocked — waiting on the
client's API key."* Statuses, priorities and the blocked flag are ordinary
fields; the agent sets them the way you would on the form.

**Closing a loop.** *"Log what we just did as a comment on that task, and
record two hours of development."* The comment lands in the worklog the
activity feed reads; the hours land in a timesheet. This is the habit that
makes the project history worth having — an agent that works without logging
leaves a project that looks untouched.

**When a decision gets made.** *"Remember that this client's staging server
ignores `.htaccess`."* Under the default policy the memory is stored as
*proposed* and stays invisible until you say *"confirm that memory"* — so also
ask, now and then, *"list proposed memories."* Later, in another session,
*"what do we know about deployments here?"* (`recall`) brings it back, and
looking at a related task surfaces it unasked.

**Finding what connects.** *"What else is related to this task?"* — tag
siblings across tasks, memories and wiki pages, plus the decisions that mention
it. Tag things as you go and this is what the tags pay for.

**When something is worth keeping.** *"Write up what we learned about that
caching bug as a wiki page under this project."* *"Append today's findings to
the deployment-notes page."* *"What pages are wanted but not written?"* — that
last one is a ready-made backlog of documentation the wiki already knows it is
missing.

**Looking back.** The three slash commands above are canned versions of this:
a standup from live task state, a weekly review from timesheets and decisions,
a project status written to the wiki where the next session will find it.

If the rhythm is too much to hold onto, keep the trio: open with context,
close with a comment and a time entry, say *remember* when something gets
decided. Everything else is reachable from there.

---

## What's in the box

Enable only what you want. The MCP tool registry collects providers per
submodule, so the tool surface is exactly what you turned on.

| Module | Provides | Depends on |
|---|---|---|
| `petrichor` | Shared entity base, access handler, permissions, `/pm` | — |
| `petrichor_projects` | Project entity — the scope everything else hangs off | `petrichor` |
| `petrichor_tasks` | Tasks: status, priority, assignee, subtasks, blocked flag | projects |
| `petrichor_milestones` | Milestones; adds a milestone field to tasks | projects |
| `petrichor_timesheets` | Time entries against tasks | tasks |
| `petrichor_comments` | Worklog comments and the project activity feed | tasks |
| `petrichor_board` | Drag-and-drop kanban board and Today view | tasks |
| `petrichor_markdown` | A "Markdown" text format via `league/commonmark` | — |
| `petrichor_wiki` | wiki pages: hierarchy, `[[wiki links]]`, backlinks, revisions | `petrichor` |
| `petrichor_memory` | Reviewable, project-scoped memory for agents | projects |
| `petrichor_tags` | One shared tag vocabulary across memories, tasks and wiki pages — and the Related section that reads it | `petrichor`, taxonomy |
| `petrichor_mcp` | The MCP server at `/mcp` | projects |

`petrichor_mcp` deliberately depends on nothing but projects. Each of the other
modules registers its own tools, so the server exposes exactly what you have
enabled — an agent is never offered a task tool on a site with no tasks. Enable
or disable a submodule and a connected client's tool list catches up on its
own within a few seconds, via `notifications/tools/list_changed` on the
Streamable HTTP stream — no reconnect required, for any client that supports
it (Claude Code does).

Full docs for the piece people ask about most:
**[Agent memory](modules/petrichor_memory/README.md)** — what it stores, how it
comes back, and how to keep an agent from writing things you did not agree to.

---

## Related

Tasks, memories and wiki pages share one tag vocabulary, so a word typed on a
task and the same word typed on a memory are the same term. **Related** is what
reads that: a short section on a task, a memory or a wiki page listing the other
records that carry its tags, plus — on a task — the decisions and preferences
that mention it, which until now only an agent calling `get_task` ever saw.

Agents get the same lists from **`get_related`**, which is how the page and the
assistant stay in agreement about what connects to what.

Two things it deliberately does not do:

- **It matches on tags and nothing else.** No title similarity, no body search.
  A tag is somebody asserting a connection; everything else is a guess, and
  this suite has already measured what guessing costs — see the *related
  memories* section of the [agent memory docs](modules/petrichor_memory/README.md).
- **It renders nothing when nothing is related.** An untagged record gets no
  section, not an empty one. A panel that is usually empty is a panel people
  stop reading.

It appears wherever `petrichor_tags` is installed, and disappears cleanly when
it is not. Each entity type's Related section is an ordinary extra field, so
you can reorder or switch it off per entity at
**Structure → Petrichor → {entity} → Manage display**.

---

## How it looks in your theme

`/pm` and `/wiki` render in your site's front-end theme by default. The module
ships no theme and no palette: every colour reads a `--petrichor-*` design token
first and falls back to a `currentColor` mix, so it adopts your theme's text and
background rather than fighting them, in light mode and dark.

Measured against stock Olivero, that holds — with one caveat worth knowing
before you judge it:

- The wiki, task pages, Today and the dashboard look native. They are ordinary
  content in your content region.
- **The board is cramped.** Olivero constrains content to a readable prose
  measure of about 700px, and a five-column kanban board is about 1400px wide.
  It stays usable — the columns scroll horizontally rather than clipping — but
  you see roughly half the board at a time. Any theme with a narrow content
  region does this; it is a property of prose layouts, not a bug in either.

If that bothers you, turn on **"Use the Petrichor theme for the workspace"** in
the settings form. Everything under `/pm` and `/wiki` then renders full-width in
`petrichor_theme`, and **nothing else on your site changes** — every other page
keeps the theme it has now. It is off by default because a contrib module
should never quietly repaint a site it was installed into.

The theme ships inside this package, at `themes/petrichor_theme`, so
`composer require drupal/petrichor` brings it with everything else. Install it
from the Appearance page — it does **not** have to be your site default; the
setting above is what puts it in front of the workspace pages and nothing
else.

If you would rather keep your own theme and give the board room, define the
token contract in it (`--petrichor-surface`, `--petrichor-border`,
`--petrichor-accent`, the `--petrichor-space-*` scale) and widen the content
region on `/pm/board/*`. The module reads the tokens if they exist and never
requires them.

---

## Permissions

| Permission | Grants |
|---|---|
| `view petrichor content` | read-only, including the dashboard |
| `manage petrichor content` | create and edit anything in the suite |
| `delete petrichor content` | delete — deliberately **separate** from manage |
| `archive petrichor memories` | the `forget` tool |
| `access petrichor mcp` | call the MCP endpoint |
| `administer petrichor` | full control, including configuration |

Entity access applies on top of MCP access, so a role holding
`access petrichor mcp` and `view petrichor content` really is read-only.

---

## Safety properties

Worth knowing before you let something autonomous near it:

- **Nothing hard-deletes over the wire.** `forget` archives. Enforced in the
  access handler, so it survives an à-la-carte install rather than depending on
  shipped config.
- **Memory is reviewable.** By default an agent's memory is stored but invisible
  until you confirm it; it cannot influence a later session first.
- **Writes use optimistic concurrency.** Pass `if_unchanged_since` from a prior
  read and a person and an agent editing the same task get a conflict instead of
  a silent overwrite.
- **Authorship is in the envelope.** Every response says whether a human or an
  agent wrote the thing, derived from entity metadata and never parsed out of
  content — so content cannot claim to be something it is not.

---

## Developing on it

Every MCP tool delegates to the same domain service the UI calls. There is no
second implementation to fall behind, which is the usual fate of an API
maintained beside an application rather than underneath it. If you add a rule,
add it to the service and both surfaces get it.

drupal.org runs eight CI jobs against this module, and all eight run locally via
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib):

```
ddev config --project-type=drupal11 --docroot=web
ddev add-on get ddev/ddev-drupal-contrib
ddev start && ddev poser && ddev symlink-project
ddev exec "cd web/core && yarn install"   # eslint and stylelint need this
```

Then `ddev phpcs`, `ddev phpstan`, `ddev phpunit`, `ddev eslint`,
`ddev stylelint`. Everything it generates is gitignored.

Two things worth knowing, both learned the hard way:

- Core's frontend tooling needs **yarn**. `npm ci` in `web/core` exits 0 and
  installs nothing, leaving the linters silently unavailable.
- Re-run `ddev symlink-project` after adding a file to the module root, or the
  assembled site will not see it.

---

## Requirements

- Drupal 11.1 or newer
- PHP 8.3+
- `league/commonmark` ^2.6 (pulled in by Composer)

## Status

This is a `-dev` release. The honest limits:

- **It has had one user.** Every ergonomic decision came from watching one
  person work, and it will show in the places nobody else has pushed on yet.
- **Databases:** the full suite runs green on **MariaDB 11.8 and PostgreSQL
  16**, and the reference site runs SQLite. MySQL proper is untested, though it
  is the closest relative of the two that are.
- **No security advisory coverage** while it is `-dev`. Report anything you
  find in the issue queue and it gets fixed in the open.

### Updating

`-dev` means the branch moves under you, so the policy is worth stating:
**anything that changes stored data ships a `hook_update_N`.** Take a newer
commit, run `drush updb`, carry on — you should never have to uninstall and
reinstall to move forward, and config shipped in `config/install` gets an
update hook too, since Drupal only installs it once.

If a change ever cannot be done that way, the release note will say so in as
many words rather than letting you find out.

Three update hooks exist so far, in `petrichor_memory` and `petrichor_wiki`.

### Verified rather than hoped for

Two things that used to be listed as caveats are now checked by the test suite:

- **Every module installs, uninstalls and reinstalls cleanly**, and each has
  help you can read from the Extend page — Drupal's own `GenericModuleTestBase`
  is enrolled for all twelve.
- **The à-la-carte install works.** The MCP server runs on `petrichor_projects`
  alone, and the tool list an agent is offered is exactly what you enabled.

## Maintainers

- Joe Hahn — [joehahn](https://www.drupal.org/u/joehahn)
