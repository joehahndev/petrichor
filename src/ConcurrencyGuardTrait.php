<?php

declare(strict_types=1);

namespace Drupal\petrichor;

/**
 * The optimistic-concurrency guard, in one implementation.
 *
 * Callers read an entity, keep its `changed` value, and send it back as
 * `if_unchanged_since` on the next write. If the entity moved in between, the
 * write is refused instead of silently overwriting whoever edited first.
 *
 * This used to be a raw string comparison against `date('c')` output, repeated
 * in two services. That works only while both sides render the same UTC offset,
 * because RFC 3339 strings sort lexicographically only within one offset:
 *
 * @code
 * '2026-08-07T23:00:00-04:00'  // 2026-08-08T03:00Z — the later instant
 * '2026-08-08T01:00:00+00:00'  // 2026-08-08T01:00Z — the earlier instant
 * // strcmp says the second is later. It is not.
 * @endcode
 *
 * The server always emitted site-local time, so the board's JavaScript stayed
 * correct by echoing the string back untouched. Any other client did not have
 * that luck: `new Date().toISOString()` always produces `Z`, and an MCP agent
 * that normalises a timestamp before sending it back would silently disable
 * its own conflict detection. That failure was invisible, and it **failed
 * open**: the guard passed and the write clobbered.
 *
 * Both sides are now parsed to instants and compared numerically, so any valid
 * ISO 8601 spelling of the same moment behaves identically.
 *
 * An unreadable token **fails closed**. Refusing a write we cannot verify costs
 * the caller one re-read; permitting it costs somebody their edit.
 */
trait ConcurrencyGuardTrait {

  /**
   * Refuses the write if the record changed after the caller last read it.
   *
   * @param int|string $changed
   *   The record's current changed time: a UNIX timestamp, or any string
   *   PHP can parse (the RFC 3339 value these services put on the wire).
   * @param string|null $token
   *   The caller's `if_unchanged_since`. NULL or empty skips the check —
   *   the guard is opt-in, and a caller that omits it accepts last-write-wins.
   * @param string $label
   *   Noun for the message, e.g. 'task'.
   *
   * @throws \InvalidArgumentException
   *   If the record moved, or the token could not be read. The message always
   *   begins 'Conflict:' — BoardController discriminates on that to answer 409
   *   rather than 404.
   */
  protected function assertUnchangedSince(int|string $changed, ?string $token, string $label = 'record'): void {
    if ($token === NULL || trim($token) === '') {
      return;
    }
    $since = $this->toInstant($token);
    if ($since === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'Conflict: could not read if_unchanged_since "%s" as a timestamp, so the write was refused. Re-read the %s first.',
        $token,
        $label,
      ));
    }
    $current = is_int($changed) ? $changed : $this->toInstant($changed);
    if ($current === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'Conflict: the %s has an unreadable changed time, so the write was refused.',
        $label,
      ));
    }
    if ($current > $since) {
      throw new \InvalidArgumentException(sprintf(
        'Conflict: %s changed since %s. Re-read it first.',
        $label,
        $token,
      ));
    }
  }

  /**
   * Parses a timestamp string to a UNIX instant, or NULL if unreadable.
   *
   * Uses strtotime() rather than a DateTime format whitelist, because callers
   * legitimately send 'Z', '+00:00', and with or without fractional seconds —
   * rejecting a valid spelling of the right moment would be its own bug.
   */
  private function toInstant(string $value): ?int {
    $instant = strtotime($value);
    return $instant === FALSE ? NULL : $instant;
  }

}
