# Petrichor Memory

Agents start every session knowing nothing. You re-explain the same
constraints — we use SQLite, don't touch that table, the client hates
carousels — and the next session you explain them again.

This module gives them somewhere to write it down, and gives you control over
what gets written.

## What a memory is

A short titled fact, stored as a Drupal content entity. It has:

- a **scope** — global, or attached to one project
- a **type** — `decision`, `preference`, `reference`, or `learning`
- an **author** — which agent or person recorded it, kept in the entity, never
  parsed out of the text
- a **status** — `active`, `proposed`, or `archived`
- optional **tags**

Because it is a content entity, it is a normal Drupal thing: it has a URL, it
appears at `/pm/memories`, you can add fields to it, list it in a View, and
control access to it with permissions.

The important part is where it lives. Most agent memory is a file in the
client's config directory or a vector store the client owns — it belongs to one
tool on one machine. This lives on your server, so every agent and every client
you point at the site reads the same facts, and you can read them too.

## The three ways an agent gets one back

**1. Asked for.** `recall` searches active memories by keyword and returns the
full text. Project-scoped matches rank above globals, and globals still appear —
a fact that applies everywhere applies here too.

**2. At the start of a session.** `get_project_context` hydrates a whole session
in one call — brief, open tasks, recent activity, milestones, wiki index — and
includes the project's memories in that bundle.

**3. Without being asked.** `get_task` returns `related_memories`: the ids and
titles of decisions and preferences that match the task, capped at three. This
is the one that matters most, because it is the only one that does not depend on
somebody remembering to look. The read already happens before the work, so a
decision already made arrives in time to change what gets done.

That third path is deliberately narrow, and the narrowness was measured, not
guessed. It searches memory titles and tags but not the fact bodies, and a task
title offering two or more useful words has to match two of them. A looser
version matched 15 of 19 real tasks and was wrong on nearly all of them —
"Build the theme" pulling up a decision about database backups. **A field that
is usually wrong is worse than no field, because it teaches you to skip it.**

## Deciding what gets recorded

An agent writing durable facts into your site unsupervised is a reasonable thing
to be uneasy about. One setting at
**Configuration → User interface → Petrichor PM** controls it:

| Setting | What happens when an agent calls `remember` |
|---|---|
| **Record it** | Stored `active`. Recallable straight away. |
| **Propose it** *(default)* | Stored `proposed`. Real and durable, but invisible. |
| **Only when asked** | Refused, unless the agent confirms you asked for it. |

**Propose it** is the default and the interesting one. `recall`, the context
bundle and `related_memories` all filter to `active`, so a proposed memory
cannot influence any later session until a human confirms it — and that holds
whether or not the agent cooperates. It is enforced state, not an instruction an
agent might ignore or forget between sessions.

It also does not interrupt you. The agent records, tells you what it recorded,
and carries on; the memories queue up at `/pm/memories` and you review them when
you feel like it. Confirm one with the `confirm_memory` tool or by editing it.

Under **Only when asked**, the refusal tells the agent how to proceed if you
genuinely did ask (`requested: true`) rather than just failing. In every mode the
response says what happened and what to do about it, so an agent that has never
seen your site behaves correctly on its first call without being briefed.

## What an agent cannot do

`forget` sets a memory to `archived`. Nothing in the MCP surface hard-deletes,
and archiving needs `archive petrichor memories`, which is not in the default
contributor role — so a plain agent can add facts and read them, and removing
one is a deliberate escalation you grant on purpose.

Never give an agent role `delete petrichor content` or `administer petrichor`.
The no-hard-delete guarantee is enforced in the access handler and depends on it.

## Permissions

| Permission | Lets you |
|---|---|
| `view petrichor content` | read memories, call `recall` |
| `manage petrichor content` | record and confirm memories |
| `archive petrichor memories` | call `forget` |

## Tools

`remember`, `recall`, `list_memories`, `confirm_memory`, `forget`.

See the [main README](../../README.md) for installation and the rest of the
suite.
