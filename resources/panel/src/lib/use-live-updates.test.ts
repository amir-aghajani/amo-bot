import { onlineManager, type QueryKey } from '@tanstack/react-query'
import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ChangesResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { useLiveUpdates } from '@/lib/use-live-updates'
import { providers, until } from '@/test/render'
import { json, offline, server, type Answer } from '@/test/server'

/*
 * The panel follows the shop (lib/use-live-updates): every few seconds it asks which areas changed and reloads the
 * screens that show them — not on its first answer, which is where the screens already are —, and while the server does
 * not answer it asks less and less often, up to a minute, and says so (the shell's strip); offline, it waits.
 */

type Area = keyof ChangesResponse['versions']

/**
 * Every read a screen makes of each area — its rows or its figures come from the area's tables (the server's
 * presenters) —, written out apart from the map it checks: a screen that starts reading an area belongs here first.
 */
const DERIVED: Record<Area, QueryKey[]> = {
  users: [
    queryKeys.users, // the users screen: profile, ban, role, balance, groups
    queryKeys.everyUser, // a customer's page: the same, and who brought them
    queryKeys.customerGroups, // the groups and their members
    queryKeys.everyUserWallet, // a wallet's lines
    queryKeys.referrals, // the program's numbers: who brought whom (users.referred_by)
    queryKeys.referrers,
    queryKeys.invitees,
    queryKeys.agency, // the agents counted (users.agency_level_id)
    queryKeys.agencyLevels, // each level's agents
    queryKeys.agents, // an agent's level, credit and wallet; their bot's customers
    queryKeys.account, // the agent's own level, credit and wallet
    queryKeys.dashboards, // new customers, the customers in all, the latest ones and their balance
  ],
  servers: [
    queryKeys.servers,
    queryKeys.everyServer,
    queryKeys.serverChoices, // where a service may move: whether a server sells
    queryKeys.plans, // whether each plan's entry can sell on its server
    queryKeys.planOptions, // the plan form's servers and inbounds
    queryKeys.massGrants, // the mass gift's servers
    queryKeys.dashboards, // the servers whose panel failed
  ],
  grants: [queryKeys.everyServerGrants, queryKeys.massGrants],
  plans: [
    queryKeys.plans,
    queryKeys.planCategories,
    queryKeys.planOptions, // the plan form's categories
    queryKeys.account, // an agent's smallest plan on sale, held against their traffic
    queryKeys.dashboards, // the same, on an agent's dashboard
  ],
  payment_methods: [queryKeys.paymentMethods],
  orders: [
    queryKeys.orders,
    queryKeys.everyOrder,
    queryKeys.payments, // a payment's order: its state, what may be done
    queryKeys.everyPayment,
    queryKeys.users, // a customer's orders counted
    queryKeys.everyUser, // on their page too
    queryKeys.agents, // what an agent's bot sold
    queryKeys.plans, // each plan's sales
    queryKeys.dashboards,
    queryKeys.queues, // the sidebar's stuck orders
  ],
  payments: [
    queryKeys.payments,
    queryKeys.everyPayment,
    queryKeys.orders, // an order's payments
    queryKeys.everyOrder,
    queryKeys.paymentMethods, // each method's payments counted
    queryKeys.dashboards, // the revenue, the receipts to review
    queryKeys.queues, // the sidebar's receipts to review
  ],
  subscriptions: [
    queryKeys.subscriptions,
    queryKeys.everySubscription,
    queryKeys.orders, // the service an order sold
    queryKeys.everyOrder,
    queryKeys.users, // a customer's services counted
    queryKeys.everyUser, // on their page too
    queryKeys.agents, // an agent's bot's services that run
    queryKeys.plans, // each plan's running services; a server's room
    queryKeys.planOptions, // a server's room
    queryKeys.servers, // each server's running services, its room
    queryKeys.serverChoices,
    queryKeys.everyServer,
    queryKeys.everyServerGrants, // whom a grant would reach
    queryKeys.massGrants,
    queryKeys.dashboards,
  ],
  referrals: [
    queryKeys.referrals,
    queryKeys.referrers,
    queryKeys.invitees,
    queryKeys.referralCommissions,
    queryKeys.everyUser, // what a customer's referrals earned them, on their page
  ],
  agency: [
    queryKeys.agency,
    queryKeys.agencyLevels,
    queryKeys.agencyRequests,
    queryKeys.agents, // their bots, their traffic
    queryKeys.everyUser, // an agent's page: their bot, its traffic
    queryKeys.everyAgentTraffic,
    queryKeys.account, // the agent's bot and traffic
    queryKeys.accountTraffic,
    queryKeys.plans, // an agent's plans their traffic no longer covers, or covers again
    queryKeys.shops, // the owner's picker: the agents' bots
    queryKeys.dashboards, // an agent's traffic too short to sell anything
  ],
  channels: [queryKeys.botChannels],
  broadcasts: [queryKeys.broadcasts],
  reports: [queryKeys.reportGroup],
  emoji: [queryKeys.customEmojis],
  tickets: [
    queryKeys.tickets, // the tickets screen; a customer's tickets on their page
    queryKeys.everyTicket, // a ticket's page: its state, its conversation, its rating
    queryKeys.queues, // the sidebar's tickets waiting on an answer
    queryKeys.dashboards, // the same, on the attention card
  ],
  reviews: [
    queryKeys.reviews, // the reviews screen: each one's state, who decided it
    queryKeys.queues, // the sidebar's reviews waiting on support
    queryKeys.dashboards, // the same, on the attention card
  ],
}

const CHANGES = '/api/admin/changes'

/** The live updates mounted, the server answering `answer()` each time it is asked. */
async function live(answer: () => Answer) {
  server().on('GET', CHANGES, () => answer())
  const { client, wrapper } = providers()
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const { result } = renderHook(() => useLiveUpdates(), { wrapper })
  await until(() => expect(asked()).toBe(1))
  return { invalidated, reloaded: () => invalidated.mock.calls.map(([filters]) => filters?.queryKey), heard: () => result.current }
}

const asked = () => server().sent('GET', CHANGES).length

const wait = (ms: number) => act(() => vi.advanceTimersByTimeAsync(ms))

const versions = (numbers: ChangesResponse['versions']) => json({ versions: numbers } satisfies ChangesResponse)

beforeEach(() => {
  vi.useFakeTimers()
})

// The browser's network state is react-query's, one for the page: back online for the next test.
afterEach(() => onlineManager.setOnline(true))

describe('the live updates', () => {
  it('reload what a moved area shows, and only that — never on the first answer', async () => {
    let numbers: ChangesResponse['versions'] = { orders: 4, channels: 2 }
    const { invalidated, reloaded } = await live(() => versions(numbers))
    expect(invalidated).not.toHaveBeenCalled()

    numbers = { orders: 5, channels: 2 }
    await wait(4_000)
    await until(() => expect(asked()).toBe(2))

    await until(() => expect(reloaded()).toContainEqual(queryKeys.orders))
    expect(reloaded()).toContainEqual(queryKeys.everyPayment)
    expect(reloaded()).toContainEqual(queryKeys.dashboards)
    expect(reloaded()).not.toContainEqual(queryKeys.botChannels)
  })

  it('count an area nothing was written to before as moved once it is', async () => {
    let numbers: ChangesResponse['versions'] = { orders: 4 }
    const { reloaded } = await live(() => versions(numbers))

    numbers = { orders: 4, emoji: 1 }
    await wait(4_000)

    await until(() => expect(reloaded()).toEqual([queryKeys.customEmojis]))
  })

  it.each(Object.entries(DERIVED) as [Area, QueryKey[]][])('reload, for the %s area, every read a screen makes of it', async (area, derived) => {
    let numbers: ChangesResponse['versions'] = {}
    const { reloaded } = await live(() => versions(numbers))

    numbers = { [area]: 1 }
    await wait(4_000)

    await until(() => expect(reloaded().length).toBeGreaterThan(0))
    expect(new Set(reloaded().map((key) => JSON.stringify(key)))).toEqual(new Set(derived.map((key) => JSON.stringify(key))))
    expect(reloaded()).toHaveLength(derived.length)
  })

  it('ask less and less often while the server does not answer, up to a minute, and every few seconds again once it does', async () => {
    let answer: Answer = versions({ orders: 1 })
    await live(() => answer)
    answer = offline

    // 4 s, then 8, 16, 32, 60 and 60 between the failed asks.
    for (const [gap, count] of [
      [4_000, 2],
      [8_000, 3],
      [16_000, 4],
      [32_000, 5],
      [60_000, 6],
      [60_000, 7],
    ] as const) {
      await wait(gap - 1)
      expect(asked()).toBe(count - 1)
      await wait(1)
      await until(() => expect(asked()).toBe(count))
    }

    answer = versions({ orders: 1 })
    await wait(60_000)
    await until(() => expect(asked()).toBe(8))
    await wait(4_000)
    await until(() => expect(asked()).toBe(9))
  })

  it('say the server unreachable while its asks get no answer, and no more once one does', async () => {
    let answer: Answer = versions({ orders: 1 })
    const { heard } = await live(() => answer)
    expect(heard().unreachable).toBe(false)

    answer = offline
    await wait(4_000)
    await until(() => expect(heard().unreachable).toBe(true))

    answer = versions({ orders: 1 })
    await wait(8_000)
    await until(() => expect(heard().unreachable).toBe(false))
  })

  it('wait while the browser is offline — no ask, no failure —, and ask at once when it is back', async () => {
    const { heard } = await live(() => versions({ orders: 1 }))

    act(() => onlineManager.setOnline(false))
    await wait(30_000)
    expect(asked()).toBe(1)
    expect(heard().unreachable).toBe(false)

    act(() => onlineManager.setOnline(true))
    await until(() => expect(asked()).toBe(2))
  })
})
