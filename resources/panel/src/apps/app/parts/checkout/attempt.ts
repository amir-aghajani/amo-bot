import { useRef } from 'react'
import { useLocation } from 'react-router'
import { requestKey } from '@/apps/app/lib/store'
import { ApiError } from '@/lib/api'
import { forgetStored, readStored, writeStored } from '@/lib/storage'

/*
 * One Idempotency-Key per attempt at a request that moves money — an order (a purchase, a built service, a renewal, an
 * extra, a top-up) or a wallet transfer: made as the customer first presses, sent again with the very same request after
 * no answer came or a server failure (the first may have been made: the shop answers what it made), and ended by the
 * shop's answer — made, or refused. A request changed is another attempt, with a key of its own.
 *
 * An attempt still open outlives its screen: kept in this tab's storage by the screen's address and the request, so the
 * screen opened again (a reload, Telegram bringing the app back) sends it with the same key — never a second charge for
 * an answer that was lost. Storage may refuse: the key then lives as long as the screen does.
 */

/** The shop answered and refused the request (a 4xx): nothing was made, and the next press is a new attempt. */
export function refused(error: unknown): boolean {
  return error instanceof ApiError && error.status > 0 && error.status < 500
}

const PREFIX = 'amobot-app:attempt:'

/** How long an open attempt is kept: past it, the same request is a new attempt (its order would be long cancelled). */
const KEPT_MS = 86_400_000

interface Attempt {
  /** The key for `request`: its open attempt's while there is one, else a new attempt's. */
  key: (request: unknown) => string
  /** After a failure of `request`: a refusal ends its attempt; no answer, or a server failure, keeps it. */
  failed: (error: unknown, request: unknown) => void
  /** `request` was answered (a 2xx): its attempt is over, and the same request again is a new one. */
  done: (request: unknown) => void
}

export function useAttempt(): Attempt {
  const { pathname } = useLocation()
  // The attempts while storage refuses; else storage's copy.
  const open = useRef(new Map<string, string>())
  // Its place: the screen's address and the request as sent, exactly (one changed is another attempt).
  const slot = (request: unknown) => `${PREFIX}${pathname} ${JSON.stringify(request) ?? ''}`
  const end = (request: unknown) => {
    const name = slot(request)
    open.current.delete(name)
    forgetStored(name, 'session')
  }

  return {
    key: (request) => {
      const name = slot(request)
      const kept = keptKey(name) ?? open.current.get(name)
      if (kept !== undefined) return kept
      const key = requestKey()
      open.current.set(name, key)
      writeStored(name, JSON.stringify({ key, at: Date.now() }), 'session')
      return key
    },
    failed: (error, request) => {
      if (refused(error)) end(request)
    },
    done: end,
  }
}

/** The key storage keeps under `name` while it is recent; undefined for none (or none readable). */
function keptKey(name: string): string | undefined {
  const kept = readStored(name, 'session')
  if (kept === null) return undefined
  try {
    const { key, at } = JSON.parse(kept) as { key?: unknown; at?: unknown }
    if (typeof key === 'string' && typeof at === 'number' && Date.now() - at < KEPT_MS) return key
  } catch {
    // Not one of ours: a new attempt takes its place.
  }
  forgetStored(name, 'session')
  return undefined
}
