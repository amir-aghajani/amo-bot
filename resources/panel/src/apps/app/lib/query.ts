import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { problemOf, signedOut } from '@/apps/app/lib/store'
import { isTransportError } from '@/lib/api'

// A mutation's `meta.quiet` (lib/query-client registers the type): the screen shows the failure itself, no toast.

type Listener = () => void
const sessionEnded = new Set<Listener>()

/** Hear of a request answered "your session ended" (lib/session signs in again with the launch data). */
export function onSessionEnded(listener: Listener): () => void {
  sessionEnded.add(listener)
  return () => sessionEnded.delete(listener)
}

/**
 * The mini app's query client: a read that got no answer is asked twice more, any answer stands; a read is fresh for
 * twenty seconds and asked again when the app comes back to the screen (Telegram minimises it often); a write's failure
 * is a toast in the customer's words unless the screen shows it (`meta.quiet`); and a request answered "your session
 * ended" tells lib/session, which signs the customer in again.
 */
export const queryClient: QueryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: (failures, error) => failures < 2 && isTransportError(error), staleTime: 20_000, refetchOnWindowFocus: true },
    mutations: { networkMode: 'always' },
  },
  queryCache: new QueryCache({
    onError: (error) => {
      if (signedOut(error)) sessionEnded.forEach((listener) => listener())
    },
  }),
  mutationCache: new MutationCache({
    onError: (error, _variables, _context, mutation) => {
      if (signedOut(error)) {
        sessionEnded.forEach((listener) => listener())
        return
      }
      if (!mutation.meta?.quiet) toast.error(problemOf(error))
    },
  }),
})

/**
 * Every read's key, in one place so a write names exactly what it changes. Reads of the same thing share a key prefix:
 * invalidating `keys.subscriptions` refetches the list and every service's own page.
 */
export const keys = {
  shop: ['shop'] as const,
  me: ['me'] as const,
  plans: ['plans'] as const,
  sales: ['sales'] as const,
  status: ['status'] as const,
  tutorial: ['tutorial'] as const,
  customServices: ['custom-services'] as const,
  paymentMethods: ['payment-methods'] as const,
  subscriptions: ['subscriptions'] as const,
  subscription: (id: number) => ['subscriptions', id] as const,
  orders: ['orders'] as const,
  order: (id: number) => ['orders', id] as const,
  wallet: ['wallet'] as const,
  transactions: ['wallet', 'transactions'] as const,
  referral: ['referral'] as const,
  notifications: ['notifications'] as const,
  tickets: ['tickets'] as const,
  ticket: (id: number) => ['tickets', id] as const,
  reviews: ['reviews'] as const,
  testAccount: ['test-account'] as const,
  wheel: ['wheel'] as const,
  lottery: ['lottery'] as const,
  sessions: ['me', 'sessions'] as const,
}
