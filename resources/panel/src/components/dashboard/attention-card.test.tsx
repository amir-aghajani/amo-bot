import type { ReactElement } from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SellingNotice } from '@/components/agency/agency-standing'
import { AttentionCard } from '@/components/dashboard/attention-card'
import type { AgencyStanding, Session, TrafficShortage } from '@/lib/api-types'
import { AGENT_SHOP, OWNER, signedIn } from '@/test/render'
import { agencyStanding } from '@/test/rows'

/*
 * What needs a hand on the dashboard (components/dashboard/attention-card): an agent's bot whose traffic sells nothing
 * comes first, with what to do about it the panel's own way — never under «همه‌چیز مرتب است» —, counts that could not be
 * read are «—», never an "all clear", and the all-clear says only what the shop on screen has (an agent's, no servers).
 */

const GB = 1024 ** 3

const NONE_WAITING = { payments_to_review: 0, stuck_orders: 0, open_tickets: 0, pending_reviews: 0, pending_orders: 0, servers_with_errors: 0, expiring_soon: 0 }

/** The card as the dashboard draws it, in `session`'s shop — once the panel knows the session. */
async function draw(card: ReactElement, session: Session = OWNER) {
  render(card, { wrapper: signedIn({ session }).wrapper })
  await screen.findByText('نیازمند توجه')
}

/** The card with no queue waiting, in an agent's shop whose traffic is as `trafficShortage` says. */
const show = (trafficShortage: TrafficShortage | null) =>
  draw(<AttentionCard attention={NONE_WAITING} trafficShortage={trafficShortage} trafficHelp="خرید حجم: ربات اصلی" expiringDays={3} />, AGENT_SHOP)

describe('an agent’s bot short of traffic', () => {
  it('says its traffic cannot cover its smallest plan, and how to buy more', async () => {
    await show({ balance: 2 * GB, smallest_plan: 10 * GB })

    expect(screen.getByText(/برای کوچک‌ترین پلن فروشی \(۱۰ گیگابایت\) کافی نیست/)).toBeTruthy()
    expect(screen.getByText(/خرید حجم: ربات اصلی/)).toBeTruthy()
    expect(screen.getByText('۱ مورد منتظر اقدام شماست')).toBeTruthy()
    expect(screen.queryByText('همه‌چیز مرتب است')).toBeNull()
  })

  it('says its traffic is used up, a plan on sale or not', async () => {
    await show({ balance: 0, smallest_plan: null })

    expect(screen.getByText('حجم ربات تمام شده است.')).toBeTruthy()
  })

  it('is no news while it sells', async () => {
    await show(null)

    expect(screen.getByText('همه‌چیز مرتب است')).toBeTruthy()
  })
})

describe('an agent’s terms of selling', () => {
  /** The card with no queue waiting, in an agent's shop whose terms are `standing`, said as the agent's panel says them. */
  const terms = (standing: AgencyStanding) =>
    draw(<AttentionCard attention={NONE_WAITING} trafficShortage={null} agencyStanding={standing} standingNotice={(shown) => <SellingNotice standing={shown} />} expiringDays={3} />, AGENT_SHOP)

  it('say first that the bot sells nothing now, and why', async () => {
    await terms(agencyStanding({ suspension: { reason: 'manual', since: '2026-10-14T10:00:00Z' } }))

    expect(screen.getByRole('alert').textContent).toContain('فروش ربات شما متوقف است: فروشگاه اصلی فروش را متوقف کرده است.')
    expect(screen.getByText('۱ مورد منتظر اقدام شماست')).toBeTruthy()
  })

  it('warn of an end within the warned days', async () => {
    await terms(agencyStanding({ expires_at: new Date(Date.now() + 2 * 86_400_000).toISOString() }))

    expect(screen.getByText(/نمایندگی شما .* به پایان می‌رسد \(۲ روز مانده\)/)).toBeTruthy()
  })

  it('are no news while all is well: an end far off, a month that reached its minimum', async () => {
    await terms(
      agencyStanding({
        expires_at: new Date(Date.now() + 30 * 86_400_000).toISOString(),
        monthly_minimum: '500000.00',
        month: { name: 'مهر ۱۴۰۵', purchased: '600000.00', ends_at: new Date(Date.now() + 86_400_000).toISOString(), judged: true },
      }),
    )

    expect(screen.getByText('همه‌چیز مرتب است')).toBeTruthy()
  })
})

describe('nothing waiting', () => {
  it('names payments, orders, tickets, reviews and servers in the main bot’s shop', async () => {
    await draw(<AttentionCard attention={NONE_WAITING} trafficShortage={null} expiringDays={3} />)

    expect(screen.getByText('پرداخت‌ها، سفارش‌ها، تیکت‌ها، نظرات و سرورها در وضعیت عادی هستند.')).toBeTruthy()
  })

  it('names only payments, orders, tickets and reviews in an agent’s shop, which has no servers', async () => {
    await draw(<AttentionCard attention={NONE_WAITING} trafficShortage={null} expiringDays={3} />, AGENT_SHOP)

    expect(screen.getByText('پرداخت‌ها، سفارش‌ها، تیکت‌ها و نظرات در وضعیت عادی هستند.')).toBeTruthy()
    expect(screen.queryByText(/سرورها/)).toBeNull()
  })
})

/** Where the card's row named `name` leads. */
const link = (name: RegExp) => screen.getByRole('link', { name }).getAttribute('href')

describe('the queues', () => {
  it('link to their lists, with the lists’ labels: the orders paid and not delivered, the tickets waiting on an answer, the reviews waiting on support, the services ending soonest first', async () => {
    const attention = { payments_to_review: 2, stuck_orders: 3, open_tickets: 5, pending_reviews: 1, pending_orders: 0, servers_with_errors: 0, expiring_soon: 4 }
    await draw(<AttentionCard attention={attention} trafficShortage={null} expiringDays={3} />)

    expect(link(/سفارش‌های نیازمند رسیدگی/)).toBe('/orders?status=stuck')
    expect(link(/پرداخت‌های در انتظار بررسی/)).toBe('/payments?status=awaiting_review')
    expect(link(/تیکت‌های در انتظار پاسخ/)).toBe('/tickets?status=open')
    expect(link(/نظرات در انتظار بررسی/)).toBe('/reviews?status=pending')
    expect(link(/اشتراک‌های رو به انقضا \(۳ روز\)/)).toBe('/subscriptions?status=expiring&sort=expires&dir=asc')
    expect(screen.queryByRole('link', { name: /سفارش‌های در انتظار پرداخت/ })).toBeNull()
    expect(screen.getByText('۱۵ مورد منتظر اقدام شماست')).toBeTruthy()
  })
})

describe('counts that could not be read', () => {
  it('are «—»: the card says nothing is waiting only of counts that came in', async () => {
    await draw(<AttentionCard attention={undefined} trafficShortage={undefined} expiringDays={undefined} />)

    expect(screen.getByText('—')).toBeTruthy()
    expect(screen.queryByText('همه‌چیز مرتب است')).toBeNull()
    expect(screen.queryByText('موردی برای پیگیری وجود ندارد.')).toBeNull()
    expect(screen.queryAllByRole('link')).toEqual([])
  })

  it('wait under a skeleton while they are read', async () => {
    await draw(<AttentionCard attention={undefined} trafficShortage={undefined} expiringDays={undefined} loading />)

    expect(screen.getByText('در حال بررسی…')).toBeTruthy()
    expect(screen.queryByText('—')).toBeNull()
  })
})
