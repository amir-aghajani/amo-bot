import { useRef } from 'react'
import { randomToken } from '@/lib/random'

/** A form's change by hand as it goes: its parts as the server reads them — texts trimmed, a picked file itself. */
type Parts = readonly unknown[]

interface RequestKey {
  /** The key to send `parts` under: the one held while the change is the same, else a new one. */
  keyFor: (parts: Parts) => string
  /** The change went through: the next one, even the same again, is a change of its own. */
  done: () => void
}

/**
 * The `Idempotency-Key` of the change a form sends (lib/api's `idempotencyKey`): kept while the same change is sent
 * again — a retry after no answer, or after a failure, is answered by what the first one did, never done twice —, and
 * a new one once the change is another (the form edited) or went through (`done`). Each part is compared as it is.
 */
export function useRequestKey(): RequestKey {
  const held = useRef<{ key: string; parts: Parts } | null>(null)

  return {
    keyFor: (parts) => {
      const current = held.current
      if (current !== null && current.parts.length === parts.length && current.parts.every((part, index) => Object.is(part, parts[index]))) return current.key
      const next = { key: randomToken(16), parts }
      held.current = next
      return next.key
    },
    done: () => {
      held.current = null
    },
  }
}
