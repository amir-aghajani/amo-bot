import { useContext, useEffect, useRef, useState } from 'react'
import { QueryClientContext, type Mutation } from '@tanstack/react-query'

/**
 * One press at a time for a control whose press writes (a confirm): a press that starts mutations of the query cache —
 * useMutation's `mutate`, a form's runMutation — holds the control until they settle, or until it goes (its dialog
 * closed). React hears that a mutation runs only on a later tick (react-query notifies in batches), so without it a fast
 * second press — a double click — sends the write again before the control shows it running. A press that starts no
 * mutation holds nothing.
 */
export function usePressHold(): { held: boolean; press: (run: () => void) => void } {
  // Outside a query client there is no mutation to wait for.
  const client = useContext(QueryClientContext)
  const [held, setHeld] = useState(false)
  const holding = useRef(false)
  const stop = useRef<(() => void) | null>(null)

  useEffect(() => () => stop.current?.(), [])

  const press = (run: () => void) => {
    if (holding.current) return
    if (client === undefined) {
      run()
      return
    }
    const cache = client.getMutationCache()
    // What the press starts: the cache tells it as it happens, and a mutation is pending from the moment it starts.
    const started: Mutation[] = []
    const watch = cache.subscribe((event) => {
      if (event.type === 'added') started.push(event.mutation)
    })
    try {
      run()
    } finally {
      watch()
    }
    const running = () => started.some((mutation) => mutation.state.status === 'pending')
    if (!running()) return

    holding.current = true
    setHeld(true)
    const release = cache.subscribe(() => {
      if (running()) return
      release()
      stop.current = null
      holding.current = false
      setHeld(false)
    })
    stop.current = release
  }

  return { held, press }
}
