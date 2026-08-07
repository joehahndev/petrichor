/**
 * @file
 * Board drag, drop, and keyboard card moves.
 *
 * The only JavaScript in the Petrichor suite. Every selector below is the DOM
 * contract published by the board/column/card components — data-petrichor-board,
 * data-petrichor-dropzone, data-petrichor-status, data-id, data-changed and
 * data-move-url. Renaming one in Twig breaks this file silently.
 */

((Drupal, once, Sortable) => {
  // Every move goes through this one chain. Two overlapping drags would
  // otherwise interleave their requests, and a rollback anchored on a sibling
  // captured before the other drag landed puts the card somewhere it was never
  // dragged from — a wrong rollback is worse than none.
  let queue = Promise.resolve();

  const idOf = (el) => (el && el.dataset.id ? Number(el.dataset.id) : null);
  const cardsIn = (list) => [...list.querySelectorAll('[data-petrichor-card]')];
  const listsIn = (root) => [
    ...root.querySelectorAll('[data-petrichor-dropzone]'),
  ];

  const labelOf = (list) => {
    const section = list.closest('section');
    const title = section && section.querySelector('h2');
    // The label is the h2's leading text node; the count badge is a sibling
    // span and must not be read out as part of the column name.
    const text = title && title.firstChild ? title.firstChild.textContent : '';
    return (text || list.dataset.petrichorStatus).trim();
  };

  // Rollback closure. Tolerates an anchor another move has since taken out of
  // this list, which would otherwise make insertBefore throw.
  const restore = (item, list, anchor) => () => {
    list.insertBefore(
      item,
      anchor && anchor.parentElement === list ? anchor : null,
    );
  };

  // Reorders a column to the server's authoritative id list. Appending a node
  // already in position moves nothing observable, so this is a no-op whenever
  // client and server already agree.
  function reconcile(list, order) {
    if (!Array.isArray(order)) {
      return;
    }
    // Re-appending the card a keyboard user just dropped would drop focus to
    // <body> mid-move.
    const focused = list.contains(document.activeElement)
      ? document.activeElement
      : null;
    // Cards the server did not name are below its capped page, so they belong
    // after it. Appending only the named ones would leave an unnamed card
    // ahead of all of them — it would appear to teleport to the top of a full
    // column on any drop.
    const named = new Set(order.map(String));
    const tail = Array.from(
      list.querySelectorAll('[data-petrichor-card]'),
    ).filter((card) => !named.has(card.dataset.id));
    order.forEach((id) => {
      const card = list.querySelector(`[data-petrichor-card][data-id="${id}"]`);
      if (card) {
        list.appendChild(card);
      }
    });
    tail.forEach((card) => list.appendChild(card));
    if (focused) {
      focused.focus();
    }
  }

  // Column headers show the column TOTAL, which can exceed what is rendered,
  // so it cannot be recounted from the DOM. A cross-column move is always
  // exactly minus one and plus one, which is derivable.
  function retally(from, to) {
    if (from === to) {
      return;
    }
    [
      [from, -1],
      [to, 1],
    ].forEach(([list, delta]) => {
      const badge = list
        ?.closest('.petrichor-board__column')
        ?.querySelector('.petrichor-board__count');
      if (badge) {
        badge.textContent = String(
          Math.max(0, (parseInt(badge.textContent, 10) || 0) + delta),
        );
      }
    });
  }

  function setError(root, message) {
    const box = root.querySelector('.petrichor-board__error');
    if (box) {
      box.textContent = message;
    }
  }

  function announcePosition(item, list) {
    const cards = cardsIn(list);
    Drupal.announce(
      Drupal.t('@column, position @position of @total.', {
        '@column': labelOf(list),
        '@position': cards.indexOf(item) + 1,
        '@total': cards.length,
      }),
    );
  }

  // Persists one move. The node has already been moved by Sortable or by the
  // keyboard path — that move is the optimistic update, and `undo` is the only
  // rollback state kept.
  function moveCard(root, item, toList, undo, fromList) {
    queue = queue.then(async () => {
      // A fast round trip must paint nothing: only a move still in flight
      // after 150ms earns the pending affordance.
      const timer = setTimeout(() => {
        item.setAttribute('aria-busy', 'true');
        item.classList.add('petrichor-card--pending');
      }, 150);
      try {
        const response = await fetch(item.dataset.moveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': root.dataset.petrichorCsrf,
          },
          body: JSON.stringify({
            status: toList.dataset.petrichorStatus,
            after_id: idOf(item.previousElementSibling),
            before_id: idOf(item.nextElementSibling),
            // Verbatim. updateTask() raw-string-compares this against its own
            // date('c') output, so reformatting here disables conflict
            // detection without ever failing loudly.
            if_unchanged_since: item.dataset.changed || null,
          }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw data;
        }
        item.dataset.changed = data.changed;
        retally(fromList, toList);
        reconcile(toList, data.order);
        setError(root, '');
      } catch (error) {
        undo();
        // A 409 reports the value that tripped the guard. Adopting it lets
        // the next drag succeed; without it the card resends the same stale
        // timestamp and stays stuck until a full page reload.
        if (error && error.changed) {
          item.dataset.changed = error.changed;
        }
        reconcile(toList, error && error.order);
        const message =
          (error && error.message) ||
          Drupal.t(
            'Could not move that card. Reload to see the current board.',
          );
        // One channel only. The error box is role="status" aria-live="polite",
        // so calling Drupal.announce() as well made a screen reader read every
        // failure twice — once politely, once assertively.
        setError(root, message);
      } finally {
        clearTimeout(timer);
        item.removeAttribute('aria-busy');
        item.classList.remove('petrichor-card--pending');
      }
    });
    return queue;
  }

  // Keyboard moves, sharing moveCard() with the drag path. Grab, arrow, commit
  // — one POST per committed move, so the keyboard path hits exactly the same
  // server contract as a drop. aria-grabbed is deliberately absent (removed in
  // ARIA 1.2); the live-region position announcements are its replacement.
  function keyboard(root) {
    let held = null;
    let origin = null;

    const release = () => {
      if (held) {
        held.classList.remove('petrichor-card--grabbed');
      }
      held = null;
      origin = null;
    };

    const place = (list, index) => {
      const others = cardsIn(list).filter((card) => card !== held);
      list.insertBefore(
        held,
        others[Math.max(0, Math.min(index, others.length))] || null,
      );
      // Moving a node in the DOM drops focus to <body>.
      held.focus();
      announcePosition(held, list);
    };

    root.addEventListener('keydown', (event) => {
      const card = event.target.closest('[data-petrichor-card]');
      // Mirrors Sortable's filter: links and buttons keep their own behaviour.
      if (
        !card ||
        event.target.closest('a, button, input') ||
        (held && card !== held)
      ) {
        return;
      }
      const list = card.parentElement;

      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (!held) {
          held = card;
          origin = { list, anchor: card.nextElementSibling };
          card.classList.add('petrichor-card--grabbed');
          Drupal.announce(
            Drupal.t(
              'Card grabbed. Arrow keys move it, Enter drops it, Escape cancels.',
            ),
          );
          return;
        }
        const undo = restore(card, origin.list, origin.anchor);
        release();
        moveCard(root, card, list, undo, origin.list);
        return;
      }
      if (!held) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        restore(card, origin.list, origin.anchor)();
        card.focus();
        Drupal.announce(Drupal.t('Move cancelled.'));
        release();
        return;
      }
      const index = cardsIn(list).indexOf(card);
      if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        place(list, index + (event.key === 'ArrowDown' ? 1 : -1));
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        const columns = listsIn(root);
        const next =
          columns[
            columns.indexOf(list) + (event.key === 'ArrowRight' ? 1 : -1)
          ];
        if (next) {
          place(next, index);
        }
      }
    });
  }

  Drupal.behaviors.petrichorBoard = {
    attach(context) {
      once('petrichor-board', '[data-petrichor-board]', context).forEach(
        (root) => {
          // Held rather than read once: a user who turns reduced-motion on
          // mid-session otherwise keeps sliding cards until a full reload.
          const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
          const reduced = motion.matches;
          const sortables = listsIn(root).map((list) =>
            Sortable.create(list, {
              group: 'petrichor-board',
              draggable: '[data-petrichor-card]',
              // Without this, Sortable swallows the pointer and card links stop
              // working.
              filter: 'a, button, input',
              // Without the delay a touch drag starts instead of a scroll, and
              // the board cannot be scrolled on a phone.
              delay: 150,
              delayOnTouchOnly: true,
              touchStartThreshold: 5,
              ghostClass: 'petrichor-card--ghost',
              animation: reduced ? 0 : 150,
              onEnd(evt) {
                // Sortable fires for a stray click-drag that landed back where
                // it started.
                if (evt.from === evt.to && evt.oldIndex === evt.newIndex) {
                  return;
                }
                // Read synchronously: evt is pooled and its fields are gone by
                // the time the request settles.
                const { item, from, to, oldIndex, newIndex } = evt;
                // The anchor is read after Sortable has already moved the node.
                // Moving up within one list shifts everything at and below the
                // old slot down by one, so children[oldIndex] is the wrong
                // element and the rollback would land the card one place high.
                const anchor =
                  from === to && newIndex < oldIndex ? oldIndex + 1 : oldIndex;
                // Indexed over cards, not children: the list also holds the
                // empty-state <li>, so children[] and Sortable's index disagree
                // and the rollback would land the card one slot out.
                const cards = [
                  ...from.querySelectorAll('[data-petrichor-card]'),
                ];
                moveCard(
                  root,
                  item,
                  to,
                  restore(item, from, cards[anchor] || null),
                  from,
                );
              },
            }),
          );
          motion.addEventListener('change', (evt) => {
            sortables.forEach((s) =>
              s.option('animation', evt.matches ? 0 : 150),
            );
          });
          keyboard(root);
        },
      );
    },
  };
})(Drupal, once, Sortable);
