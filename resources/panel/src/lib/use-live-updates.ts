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
 * The reads a change in each area refetches: every read whose rows or figures come from the area's tables. Not the
 * names a row borrows from another area (a customer's name on an order), which catch up with the next read. A new
 * table a screen shows needs its area here, and a screen that starts reading an area adds its key.
 */
const AREA_QUERIES: Record<Area, readonly QueryKey[]> = {
  // Customers, their wallets and their groups: the users screen and its groups, a customer's page, a wallet, the referral
  // program's people, the agents (their level, credit and wallet) with the program's counts and each level's, the agent's
  // own account (their wallet), the dashboard's newcomers, whom a mass gift to a customer group would reach.
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
    queryKeys.massGrants,
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
  // the sidebar's count of the stuck ones, each discount code's uses, each loyalty tier's customers.
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
    queryKeys.discountCodes,
    queryKeys.loyaltyTiers,
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
  // The commissions and the alerts on referrers — and what a customer's referrals earned them, on their page.
  referrals: [queryKeys.referrals, queryKeys.referrers, queryKeys.invitees, queryKeys.referralCommissions, queryKeys.referralAlerts, queryKeys.everyUser],
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
  // The shop's prizes and gifts: the gift codes and their batches, the lucky wheel's slices and its spins; its loyalty
  // tiers — a customer's on their page —, its cashback rules and its nightly draws with their winners.
  rewards: [queryKeys.giftCodes, queryKeys.giftCodeBatches, queryKeys.wheelPrizes, queryKeys.wheelSpins, queryKeys.loyaltyTiers, queryKeys.everyUser, queryKeys.cashbackRules, queryKeys.lotteryDraws],
  // The discount codes and the timed sales.
  discounts: [queryKeys.discountCodes, queryKeys.sales],
}

/**
 * Keeps the panel live (mounted once by AppShell): every POLL_MS while the tab is visible, and at once when it comes
 * back, it asks `/changes` which areas moved — whoever moved them (the bot, a scheduled task, another tab) — and
 * invalidates their reads. Reads on screen refetch in place; the rest refetch when next shown. Forms keep what was
 * typed (they read their data once). Offline, the poll pauses. It is the panel's heartbeat too: `unreachable` while
 * its last ask got no answer at all.
 */
export function useLiveUpdates(): { unreachable: boolean } {
  const queryClient = useQueryClient()
  const seen = useRef<ChangesResponse['versions'] | null>(null)
  // The query's error count when the server last answered: what it has gained since is the polls failed in a row.
  const failedBefore = useRef(0)
  const { data, error } = useQuery({
    queryKey: queryKeys.changes,
    queryFn: () => api.get<ChangesResponse>('/changes'),
    // While the server does not answer, back off (up to a minute); the first answer restores the pace.
    // (fetchFailureCount would not do: it counts one poll's retries and restarts with every poll.)
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
