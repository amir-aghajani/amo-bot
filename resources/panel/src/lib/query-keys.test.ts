import { describe, expect, it } from 'vitest'
import { queryKeys } from '@/lib/query-keys'

/*
 * The panels' query keys (lib/query-keys) are flat: a screen invalidates exactly the keys it names, so no key may be a
 * prefix of another's — but for the families made to be (a row's key under its `every…` one, a range's under
 * `dashboards`), which the live updates reload whole.
 */

type Key = readonly unknown[]

const FAMILIES: [family: Key, member: (id: number) => Key][] = [
  [queryKeys.everyServer, queryKeys.server],
  [queryKeys.everyServerGrants, queryKeys.serverGrants],
  [queryKeys.everyOrder, queryKeys.order],
  [queryKeys.everyPayment, queryKeys.payment],
  [queryKeys.everySubscription, queryKeys.subscription],
  [queryKeys.everyUser, queryKeys.user],
  [queryKeys.everyUserWallet, queryKeys.userWallet],
  [queryKeys.everyAgentTraffic, queryKeys.agentTraffic],
  [queryKeys.everyTicket, queryKeys.ticket],
  [queryKeys.dashboards, queryKeys.dashboard],
]

const startsWith = (key: Key, prefix: Key) => prefix.every((part, i) => key[i] === part)
const same = (a: Key, b: Key) => JSON.stringify(a) === JSON.stringify(b)

describe('the query keys', () => {
  it('are each their own, and no prefix of one another but for the families made to be', () => {
    // A key of one subject, by whatever names it — a row's number, a picture's address: one value stands for any.
    const keys: Key[] = Object.values(queryKeys).map((key) => (typeof key === 'function' ? (key as (subject: unknown) => Key)(1) : key))
    expect(new Set(keys.map((key) => JSON.stringify(key))).size).toBe(keys.length)
    for (const prefix of keys) {
      for (const key of keys) {
        if (key.length <= prefix.length || !startsWith(key, prefix)) continue
        expect(
          FAMILIES.some(([family, member]) => family === prefix && same(member(1), key)),
          `${JSON.stringify(prefix)} is a prefix of ${JSON.stringify(key)}`,
        ).toBe(true)
      }
    }
  })

  it('put every row of a family under its family’s key', () => {
    for (const [family, member] of FAMILIES) expect(startsWith(member(7), family)).toBe(true)
  })
})
