# Petrichor board components

Four Single Directory Components: `board`, `column`, `card`, `today`.
Only `board` ships JavaScript. `card` is shared by the board and the Today view,
which is why it carries its own CSS and its own token block — Today never loads
`board.css`.

```
components/
  board/   board.component.yml  board.twig  board.css  board.js   README.md
  column/  column.component.yml column.twig column.css
  card/    card.component.yml   card.twig   card.css
  today/   today.component.yml  today.twig  today.css
```

## Rules that are not negotiable

**Folder name == YAML basename == twig/css/js basename.** The component ID comes
from the *YAML filename*, not the folder, and the sibling `.twig`/`.css`/`.js`
are found by that same basename. A `board.component.yml` in a folder called
anything else resolves to a working component with no template and no assets,
and nothing warns you. Core asserts this failure mode in
`ComponentDiscoveryTest::testMismatchingFolderName()`.

**Every prop needs an explicit `type:`.** Components declared in *modules* have
`shouldEnforceSchemas() === TRUE` unconditionally (themes opt in via
`enforce_prop_schemas`), and `ComponentMetadata::parseSchemaInfo()` throws an
`InvalidComponentException` outside `assert()` when the schema is missing.
Nullable props must spell the null as a quoted string — `type: ['string', 'null']`.
Unquoted YAML `null` decodes to PHP `NULL` and
`ComponentValidator::validateDefinition()` rejects non-string types by name.

**Every root element must print `{{ attributes }}`.** That is the only path by
which `ComponentsTwigExtension` gets `data-component-id` (and
`data-component-variant`) into the DOM.

**`default:` in a prop schema is documentation only.** Use `|default()` in Twig;
nothing in the render pipeline reads the schema default.

**Prop schemas are a BC contract from the moment they ship.** Drupal's
`SchemaCompatibilityChecker` runs *outside* `assert()`, so a host theme that
overrides one of these templates breaks cache rebuild — not just its own render
— if we narrow a type or add a required prop. Additive changes only.

## `board` — libraryOverrides

`board.component.yml` must carry, and must carry *only*, this block:

```yaml
libraryOverrides:
  dependencies:
    - core/drupal
    - core/once
    - core/sortable
    - core/drupal.announce
```

Without it the generated library `core/components.petrichor_board--board` has
zero dependencies: `board.js` loads, `Sortable` is undefined, the board renders
and nothing drags, and the console error is the only clue.

Do **not** add a `js:` or `css:` key here. `libraryOverrides` is merged with
`array_merge()`, so either key replaces the auto-discovered `board.js` /
`board.css` entry wholesale instead of extending it.

`core/sortable` is SortableJS 1.15.6, already vendored in core
(`core/assets/vendor/sortable/`). It carries no BC promise across core minors;
if a minor drops it, vendor `Sortable.min.js` into `petrichor_board/js/` and
switch to an explicit `petrichor_board.libraries.yml` — the components do not
otherwise care.

## DOM contract with `board.js`

`board.js` selects on these verbatim. Renaming any of them silently disables
drag, keyboard moves, or conflict detection.

| Selector / attribute | Element | Meaning |
|---|---|---|
| `[data-petrichor-board]` | board root `<div>` | `once()` target for the behaviour. |
| `data-petrichor-csrf` | board root `<div>` | Token sent as the `X-CSRF-Token` header. Rendered server-side so no `/session/token` round trip is needed. |
| `.petrichor-board__error[role=status][aria-live=polite]` | board root child `<p>` | Failure text. Rendered **always**, empty; CSS collapses it while `:empty` rather than hiding it, because `display:none` / `visibility:hidden` would drop the live region from the accessibility tree and the message would not be announced. |
| `[data-petrichor-dropzone]` | column `<ul>` | Sortable container. |
| `data-petrichor-status` | column `<ul>` | Destination status. On the `<ul>`, **not** the `<section>`, because Sortable reports `evt.from` / `evt.to` as the list elements. |
| `[data-petrichor-card]` | card `<li>` | `draggable` selector. |
| `data-id` | card `<li>` | Task ID, read for `after_id` / `before_id` from the sibling nodes. |
| `data-move-url` | card `<li>` | POST target. |
| `data-changed` | card `<li>` | ISO string echoed back **unmodified** as `if_unchanged_since`; `TaskService::updateTask()` compares it as a raw string. Reformatting it in JS breaks conflict detection. |
| `tabindex="0"` | card `<li>` | Entry point for the keyboard path. |

## CSS class hooks for JS state

`board.js` owns these; the CSS is here.

| Class | Applied to | Contract |
|---|---|---|
| `.petrichor-card--pending` | card | In-flight request. `opacity: .6` only. Added on a 150 ms timer so a 60 ms round trip paints nothing. |
| `.petrichor-card--ghost` | card | Sortable's `ghostClass`. |
| `.petrichor-card--grabbed` | card | Keyboard grab state. Available, not required — if the keyboard path lands with a different name, change it here too. |

All three are **opacity and outline only**. Anything that changes `display`,
`margin` or box size reflows the column under the pointer mid-drag and the drop
lands in the wrong slot.

## Styling posture

Everything is scoped under `.petrichor-*`; there are no bare element selectors
and no site-wide opinions. This suite ships as contrib and may not restyle a
host site.

Colours are mixed from `currentColor` (`color-mix(in srgb, currentColor N%,
transparent)`) instead of being hard-coded, so the components adopt the host
theme's background and text colour in both light and dark rather than imposing a
palette. Only the two semantic colours — overdue/urgent and high-priority — are
literal, and `@media (prefers-color-scheme: dark)` lightens those. Focus rings
are `currentColor`, which contrasts with the surface by construction.

Tokens (`--petrichor-line`, `--petrichor-muted`, `--petrichor-sunken`,
`--petrichor-raised`, `--petrichor-alert`, `--petrichor-flag`) are declared in a
`:where()` block so a host theme overrides them at any specificity.
`board.css` declares them for `.petrichor-board`; `card.css` declares them again
for `.petrichor-card`, because Today loads card.css alone. `column.css` and
`today.css` inherit and pass literal fallbacks to `var()`.

`prefers-reduced-motion: reduce` drops the card transition; `board.js` sets
Sortable's `animation` to 0 under the same query.

There is no `petrichor_theme` yet, so these must look right in Olivero.

## The empty-column placeholder

`column.twig` renders `<li class="petrichor-board__empty">` when a column has no
cards. It is markup, not state: after a drop the server-rendered placeholder is
still in the list. `column.css` hides it with
`.petrichor-board__cards:has([data-petrichor-card]) .petrichor-board__empty`, so
`board.js` never has to manage it. It is not `[data-petrichor-card]`, so
Sortable ignores it.
