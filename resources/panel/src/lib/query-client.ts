import { MutationCache, MutationObserver, QueryCache, QueryClient, type MutationMeta, type QueryKey } from '@tanstack/react-query'
import { ApiError, isTransportError } from '@/lib/api'
import { reportBug } from '@/lib/error-reporting'
import { toastFailure } from '@/lib/failure'

declare module '@tanstack/react-query' {
  interface Register {
    mutationMeta: {
      /** The mutation shows its own failure (a form's error line, a modal's strip): no toast. */
      quiet?: boolean
      /**
       * What its success changes elsewhere on the screens — the reads it does not write itself (an answer put into the
       * cache is no reason to read that again): these go stale, and the ones on screen run again. A run whose reach is
       * known only once it ends names it from its answer (a grant worked on: nothing when its card left it unfinished).
       */
      invalidates?: readonly QueryKey[] | ((answer: unknown) => readonly QueryKey[])
    }
  }
}

/**
 * How long a read no screen shows is kept: a page opened again within it is drawn at once from what it last read (and
 * read again behind it) — the live poll keeps what it shows from being wrong meanwhile (lib/use-live-updates).
 */
const KEEP_UNUSED_MS = 30 * 60_000

/**
 * The panels' cache. A read that got no answer at all (a network blip) is asked again, twice; an answer — even an
 * error — stands, and the screen shows it (ErrorState); a read or a write that failed for any other reason is the
 * panel's own bug, told to the console and the shop's log too (lib/error-reporting). A read is fresh for ten seconds — a
 * page opened again sooner is not read again —, and none is read again because the window got the focus: the live poll
 * asks then, and reloads what moved. Every write is a mutation of it:
 * its failure is told in a toast in lib/failure's words — the API's own where they are the admin's —, unless the screen
 * shows it itself (`meta.quiet`) — so none fails without a word; and what it changes elsewhere is named once, on it
 * (`meta.invalidates`) — the kits take it as their `invalidates`, a form's save in `submit()`'s.
 */
export function createQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: (failures, error) => failures < 2 && isTransportError(error), refetchOnWindowFocus: false, staleTime: 10_000, gcTime: KEEP_UNUSED_MS },
    },
    queryCache: new QueryCache({
      onError: (error) => {
        if (!(error instanceof ApiError)) reportBug(error)
      },
    }),
    mutationCache: new MutationCache({
      onError: (error, _variables, _context, mutation) => {
        if (!(error instanceof ApiError)) reportBug(error)
        if (!mutation.meta?.quiet) toastFailure(error)
      },
      onSuccess: (answer, _variables, _context, mutation) => {
        const named = mutation.meta?.invalidates ?? []
        for (const queryKey of typeof named === 'function' ? named(answer) : named) void client.invalidateQueries({ queryKey })
      },
    }),
  })

  return client
}

/**
 * One request run as a mutation of the panels' cache — a form's save (lib/use-form) —, its `meta` heeded as any
 * useMutation's is. It goes at once, whatever the browser says of the network: its form says when no answer came.
 */
export async function runMutation<R>(client: QueryClient, request: () => Promise<R>, meta: MutationMeta): Promise<R> {
  const observer = new MutationObserver<R, Error>(client, { mutationFn: request, meta, networkMode: 'always' })
  try {
    return await observer.mutate()
  } finally {
    // Nobody watches it any more: the cache lets it go in its own time.
    observer.reset()
  }
}
