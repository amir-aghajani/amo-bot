import { MutationCache, MutationObserver, QueryCache, QueryClient, type MutationMeta, type QueryKey } from '@tanstack/react-query'
import { ApiError, isTransportError } from '@/lib/api'
import { reportBug } from '@/lib/error-reporting'
import { toastFailure } from '@/lib/failure'

declare module '@tanstack/react-query' {
  interface Register {
    mutationMeta: {
      /** The screen shows the failure itself (a form's error line, a modal's strip): no toast. */
      quiet?: boolean
      /**
       * The reads this write changes besides what its own screen writes into the cache: refetched once it succeeds. A
       * function names them from the answer (a grant run: nothing while its card left it unfinished).
       */
      invalidates?: readonly QueryKey[] | ((answer: unknown) => readonly QueryKey[])
    }
  }
}

/** How long a read no screen shows stays cached: a page opened again within it draws at once and refetches behind. */
const KEEP_UNUSED_MS = 30 * 60_000

/**
 * The panels' query client:
 * - a read that got no answer at all is asked twice more; any answer, even an error, stands and the screen shows it;
 * - a read is fresh for ten seconds, and never refetched on window focus (the live poll asks then);
 * - every write is a mutation: its failure is a toast unless `meta.quiet`, and what it changes elsewhere is named once,
 *   on it (`meta.invalidates`);
 * - a write goes at once whatever the browser says of the network — a form's save and a row's operation alike —, so
 *   offline it fails then and there with the offline words, rather than wait to go out minutes later, once the admin
 *   has left the screen that asked for it (reads wait for the connection, as react-query's do);
 * - a failure that is not an API answer is a bug of the panel's and goes to lib/error-reporting.
 */
export function createQueryClient(): QueryClient {
  const client: QueryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: (failures, error) => failures < 2 && isTransportError(error), refetchOnWindowFocus: false, staleTime: 10_000, gcTime: KEEP_UNUSED_MS },
      mutations: { networkMode: 'always' },
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
 * Run one request as a mutation of the cache (a form's save, lib/use-form), so its `meta` applies as for any
 * useMutation — going at once offline too: the form says when no answer came.
 */
export async function runMutation<R>(client: QueryClient, request: () => Promise<R>, meta: MutationMeta): Promise<R> {
  const observer = new MutationObserver<R, Error>(client, { mutationFn: request, meta })
  try {
    return await observer.mutate()
  } finally {
    // Nobody watches it any more; the cache lets it go in its own time.
    observer.reset()
  }
}
