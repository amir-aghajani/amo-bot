import { useEffect, useRef } from 'react'
import { useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { api, isTransportError } from '@/lib/api'
import type { ChangesResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** How often the panel asks what changed while it is on screen. */
const POLL_MS = 4_000

/** An area of the shop, as the change feed counts its writes (Core\Database\ChangeFeed::AREAS — the API names them). */
type Area = keyof ChangesResponse['versions']

/**
 * What a change to each area of the shop moves on the screens: every read whose rows or figures come from the area's
 * tables — not the names a row borrows from another area (a customer's on an order, a plan's or a server's on a
 * service), which follow the row's own area and the next read. A customer's visit alone moves nothing (the server
 * counts it for no area), so the customer lists and the dashboard follow the `users` area too.
 */
const AREA_QUERIES: Record<Area, readonly QueryKey[]> = {
  // Customers, their wallets and their groups: the users screen and its groups, a customer's page, a wallet, the referral
  // program's people, the agents (their level, credit and wallet) with the program's counts and each level's, the agent's
  // own account (their wallet), the dashboard's newcomers.
  users: [
    queryKeys.users,
    queryKeys.everyUser,
    queryKeys.customerGroups,
    queryKeys.everyUserWallet,
    queryKeys.referrals,
    queryKeys.referrers,
    queryKeys.invitees,
    queryKeys.agency,
    queryKeys.agencyLevels,
    queryKeys.agents,
    queryKeys.account,
    queryKeys.dashboards,
  ],
  // The servers and their inbounds — and whether a plan's entry, a move or the plan form can sell on one —, the mass
  // gift's servers, the dashboard's failing ones.
  servers: [queryKeys.servers, queryKeys.serverChoices, queryKeys.everyServer, queryKeys.plans, queryKeys.planOptions, queryKeys.massGrants, queryKeys.dashboards],
  // A grant under way moves its cursor every second: its cards alone.
  grants: [queryKeys.everyServerGrants, queryKeys.massGrants],
  // The catalogue — and an agent's smallest plan on sale, which their traffic is held against (account, dashboard).
  plans: [queryKeys.plans, queryKeys.planCategories, queryKeys.planOptions, queryKeys.account, queryKeys.dashboards],
  payment_methods: [queryKeys.paymentMethods],
  // The orders, the payments made for them, a customer's and an agent's bot's counts, each plan's sales, the dashboard,
  // the sidebar's count of the stuck ones.
  orders: [
    queryKeys.orders,
    queryKeys.everyOrder,
    queryKeys.payments,
    queryKeys.everyPayment,
    queryKeys.users,
    queryKeys.everyUser,
    queryKeys.agents,
    queryKeys.plans,
    queryKeys.dashboards,
    queryKeys.queues,
  ],
  // The payments, the orders they pay, each method's count, the dashboard's revenue, the sidebar's receipts to review.
  payments: [queryKeys.payments, queryKeys.everyPayment, queryKeys.orders, queryKeys.everyOrder, queryKeys.paymentMethods, queryKeys.dashboards, queryKeys.queues],
  // The services, the orders that sold them, the counts of a customer, an agent's bot, a plan and a server — a server's
  // room, so whether it can sell —, whom a grant would reach, the dashboard.
  subscriptions: [
    queryKeys.subscriptions,
    queryKeys.everySubscription,
    queryKeys.orders,
    queryKeys.everyOrder,
    queryKeys.users,
    queryKeys.everyUser,
    queryKeys.agents,
    queryKeys.plans,
    queryKeys.planOptions,
    queryKeys.servers,
    queryKeys.serverChoices,
    queryKeys.everyServer,
    queryKeys.everyServerGrants,
    queryKeys.massGrants,
    queryKeys.dashboards,
  ],
  // The commissions — and what a customer's referrals earned them, on their page.
  referrals: [queryKeys.referrals, queryKeys.referrers, queryKeys.invitees, queryKeys.referralCommissions, queryKeys.everyUser],
  // The agency's levels and requests, the agents' bots and their traffic — on an agent's page too; an agent's traffic too
  // short to sell anything (account, dashboard), or a plan of theirs (plans) —, the owner's shops.
  agency: [
    queryKeys.agency,
    queryKeys.agencyLevels,
    queryKeys.agencyRequests,
    queryKeys.agents,
    queryKeys.everyUser,
    queryKeys.everyAgentTraffic,
    queryKeys.account,
    queryKeys.accountTraffic,
    queryKeys.plans,
    queryKeys.shops,
    queryKeys.dashboards,
  ],
  channels: [queryKeys.botChannels],
  broadcasts: [queryKeys.broadcasts],
  reports: [queryKeys.reportGroup],
  emoji: [queryKeys.customEmojis],
  // The support tickets: their list (a customer's page's card too), the ticket open on screen with its conversation, the
  // sidebar's and the dashboard's count of those waiting on an answer.
  tickets: [queryKeys.tickets, queryKeys.everyTicket, queryKeys.queues, queryKeys.dashboards],
  // The customers' reviews: their list, the sidebar's and the dashboard's count of those waiting on support.
  reviews: [queryKeys.reviews, queryKeys.queues, queryKeys.dashboards],
}

/**
 * Keeps the panel live: every few seconds while the tab is on screen — and at once when it comes back — it asks the
 * server which areas of the shop changed, and reloads the queries that show them, wherever the change came from: the
 * bot, a scheduled task, the Telegram group's buttons, another tab. Only queries on screen refetch, in place; the rest
 * are marked stale and load fresh when next shown. Forms keep what the admin typed: they only read their data once.
 * The browser offline, the poll waits (react-query pauses it) and asks at once when it is back. It is the panel's
 * heartbeat too: `unreachable` while its last ask — tried three times — got no answer at all (the shell says so).
 */
export function useLiveUpdates(): { unreachable: boolean } {
  const queryClient = useQueryClient()
  const seen = useRef<ChangesResponse['versions'] | null>(null)
  // How many of the query's failures came before the server last answered: the rest are the polls failed since.
  const failedBefore = useRef(0)
  const { data, error } = useQuery({
    queryKey: queryKeys.changes,
    queryFn: () => api.get<ChangesResponse>('/changes'),
    // While the server does not answer, ask less and less often (up to a minute); the first answer brings it back.
    // (fetchFailureCount would not do: it counts one poll's retries, and starts again with every poll.)
    refetchInterval: ({ state }) => {
      if (state.status !== 'error') {
        failedBefore.current = state.errorUpdateCount
        return POLL_MS
      }
      return Math.min(60_000, POLL_MS * 2 ** Math.min(4, state.errorUpdateCount - failedBefore.current))
    },
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: 'always',
    refetchOnReconnect: 'always',
    staleTime: 0,
  })

  useEffect(() => {
    if (!data) return
    const before = seen.current
    seen.current = data.versions
    // The first answer is where the screens already are.
    if (before === null) return

    const moved = (Object.keys(AREA_QUERIES) as Area[]).filter((area) => data.versions[area] !== before[area])
    for (const queryKey of moved.flatMap((area) => AREA_QUERIES[area])) {
      void queryClient.invalidateQueries({ queryKey })
    }
  }, [data, queryClient])

  return { unreachable: isTransportError(error) }
}
