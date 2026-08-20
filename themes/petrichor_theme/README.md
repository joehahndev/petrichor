# Petrichor theme

The calm front end for the Petrichor PM workspace.

**TL;DR:** a Starterkit-generated standalone theme (no base theme) whose only job
is to get out of the way. Six regions, one header strip, no sidebars, no
contextual-link overlay. It is **optional** — every `petrichor_*` module renders
correctly in Olivero, Claro or any host theme, and nothing in the module suite
declares a dependency on this.

## Why it exists

`/pm/board/2` used to render in admin chrome: a fixed sidebar, a full-width
`<h1>Board</h1>`, a breadcrumb trail, and the kanban columns squeezed into what
was left. It read like a CMS admin screen. The product is a low-choice-density
workspace, so the chrome had to shrink until the board *is* the page.

## Why Starterkit and not a sub-theme

Contextual links. Drupal's contextual module puts an absolutely positioned pencil
button over the top-right corner of anything carrying `contextual-region`, and
binds mouse handlers to that region. On the board, that region is the main
content block — the whole drag surface — so the overlay sits on top of cards and
its handlers compete with SortableJS for the same pointer events. You cannot
reliably out-specificity a bound event listener. Owning the entire theme means
the placeholder is never rendered and the library is never attached.

Suppression is in two halves, both in `src/Hook/PetrichorThemeHooks.php`:

| Half | Hook | What it removes |
|---|---|---|
| Markup | `hook_preprocess()` (generic) | The `contextual_links` placeholder that `ContextualThemeHooks::preprocess()` puts in `title_suffix`, plus the `contextual-region` class |
| Behaviour | `hook_page_attachments_alter()` | `contextual/drupal.contextual-links` and `contextual/drupal.contextual-toolbar` |

Ordering works because contextual's implementation is a *global* preprocess,
which `Registry` invokes before the per-hook module and theme callbacks — so by
the time the theme's generic preprocess runs, the placeholder is there to remove.

`petrichor_board` guards its own routes independently. This theme is belt; that
is braces; and `css/components/…` has a third, inert copy in CSS. None of the
three is allowed to be the only one.

## Regions

Six, and the shortness of the list is the point.

| Region | Ships with |
|---|---|
| `header` | Site branding (name only), user account menu |
| `primary_menu` | Main navigation, one level |
| `breadcrumb` | Breadcrumbs — **not rendered on workspace routes**, see below |
| `highlighted` | Status messages, local task tabs, local actions |
| `content` | Page title, main content |
| `footer` | Empty by default |

No `secondary_menu`, no `help`, no sidebars, no `content_above` / `content_below`.
`petrichor_theme.info.yml` records why each absent region is absent. `header` is
listed first, which makes it the theme's default region: a block whose configured
region this theme lacks lands somewhere visible instead of being swallowed.

Default placements ship as `config/optional/block.block.petrichor_theme_*.yml`,
the same way core themes do it. They install with the theme and are ordinary
site config afterwards — move or delete them freely.

## Workspace routes

`/pm*` and `/wiki*` are "workspace"; everything else is an ordinary page.
`PetrichorThemeHooks::preprocessHtml()` adds two body classes:

- `petrichor-workspace`
- `petrichor-section-pm` or `petrichor-section-wiki`

**Use these, not the generated `path-*` classes**, for anything load-bearing.
`path-pm` is derived from `root_path`, which is empty on the front page — so it
disappears the day a recipe points the front page at `/pm`. The section classes
are derived from the *route* path and do not move.

`preprocessPage()` exposes the same answer to Twig as `is_workspace`.

### The h1 and the breadcrumb are treated differently, on purpose

- **Breadcrumb: removed from the markup** on workspace routes
  (`page.html.twig`). It is a `<nav>` landmark containing tab stops; hiding it in
  CSS would leave it in the accessibility tree and the tab order, announcing a
  trail a sighted user cannot see.
- **Page title: always rendered.** The board's own headings start at `<h2>`
  (project name, then one per column), so deleting the `<h1>` would leave the
  document with no top-level heading. What was wrong was its *size*, and size is
  CSS's job — `.page-title` is the hook.

Local task tabs follow the same rule as the h1: always in the DOM, never
`display: none`, quieted by CSS off the body class. They are the only route from
a task page to its edit form.

## What a host site may override

Everything here is a normal theme, so normal override rules apply.

- **Templates.** Copy any file out of `templates/` into your own theme. The
  board's own markup is *not* here — it lives in
  `petrichor_board/components/{board,column,card,today}/` as Single Directory
  Components, and those are overridden per SDC, not per theme template.
- **Design tokens.** `css/base/tokens.css` declares the whole
  `--petrichor-*` contract on `:root`, with a `prefers-color-scheme: dark` block.
  Redeclare any of them later in the cascade to reskin the workspace without
  touching a template. Module CSS always writes `var(--petrichor-surface, #fff)`
  with a fallback, precisely so the modules survive without this theme.
- **Block placement.** The shipped blocks are site config once installed.
- **Regions.** Adding regions means forking `petrichor_theme.info.yml` and
  `page.html.twig`. That is a deliberate speed bump.

## What was deleted from the generated set

Starterkit copies every core template. These overrides were removed because the
product does not use the subsystems they belong to — Petrichor's data is custom
content entities, not nodes (see `ARCHITECTURE.md`), and there is no core Search:

```
content/node.html.twig                     content/search-result.html.twig
content/links--node.html.twig              dataset/item-list--search-results.html.twig
content/mark.html.twig                     block/block--search-form-block.html.twig
content/taxonomy-term.html.twig            field/field--node--created.html.twig
content-edit/node-add-list.html.twig       field/field--node--title.html.twig
                                           field/field--node--uid.html.twig
```

Deleting a theme override is never a missing template — Drupal falls back to the
module's own default — so if a host site does install Node or Search, those pages
still render, just without a Petrichor-specific copy.

Everything else was kept. Forms, fields, Views, comments, files and users are all
either in use or explicitly on the roadmap.

## Constraints this theme is built under

No build step, no npm, no CDN: plain CSS and plain JS only. Visible focus rings,
`prefers-reduced-motion` honoured, colour never the sole signal, touch targets
≥ 44px. The theme ships no JavaScript of its own.
