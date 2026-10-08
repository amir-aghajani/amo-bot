import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { StatusCard } from '@/components/servers/status-card'
import type { DriverDescription, ServerRow } from '@/lib/api-types'
import { AGENT_SHOP, signedIn } from '@/test/render'
import { serverDriverRow, serverRow } from '@/test/rows'

/*
 * A server's state (components/servers/status-card): its running services are every shop's — what its capacity counts —,
 * but its link leads to the open shop's subscriptions screen, which lists that shop's alone: so the link says whose they
 * are, and the rest are said to be other shops'. Its connection is said in its connector's own words.
 */

const COUNTS = serverRow().counts

function showCard(counts: Partial<ServerRow['counts']>, session = signedIn()) {
  render(<StatusCard server={serverRow({ counts: { ...COUNTS, ...counts } })} probe={null} />, { wrapper: session.wrapper })
}

describe('a server’s active services', () => {
  it('all the open shop’s: the count is the way to them', async () => {
    showCard({ active_subscriptions: 4, shop_active_subscriptions: 4 })

    expect((await screen.findByRole('link', { name: '۴' })).getAttribute('href')).toBe('/subscriptions?server=1&status=active')
  })

  it('with other shops’ too: the link is the open shop’s, the rest are the agents’', async () => {
    showCard({ active_subscriptions: 3, shop_active_subscriptions: 1 })

    expect((await screen.findByRole('link', { name: /^۱ در «\s*فروشگاه اصلی\s*»$/ })).getAttribute('href')).toBe('/subscriptions?server=1&status=active')
    expect(screen.getByText('۲ در فروشگاه نماینده‌ها')).toBeTruthy()
  })

  it('from an agent’s shop, the rest are other shops’', async () => {
    showCard({ active_subscriptions: 3, shop_active_subscriptions: 1 }, signedIn({ session: AGENT_SHOP }))

    expect(await screen.findByRole('link', { name: /^۱ در «\s*فروشگاه رضا\s*»$/ })).toBeTruthy()
    expect(screen.getByText('۲ در فروشگاه‌های دیگر')).toBeTruthy()
  })

  it('none of them the open shop’s: no link to a list that has none', async () => {
    showCard({ active_subscriptions: 2, shop_active_subscriptions: 0 })

    expect(await screen.findByText('۲ در فروشگاه نماینده‌ها')).toBeTruthy()
    expect(screen.queryByRole('link')).toBeNull()
  })
})

/** The card of `row` — its connector as `driver` describes it —: what each fact of it says, by its label. */
function facts(row: ServerRow, driver?: DriverDescription) {
  render(<StatusCard server={row} probe={null} driver={driver} />, { wrapper: signedIn().wrapper })
  return async (label: string) => (await screen.findByText(label)).nextElementSibling?.textContent
}

/** A connector that calls its token a key — PasarGuard's word on its form. */
function keyedConnector(): DriverDescription {
  const described = serverDriverRow()
  return {
    ...described,
    fields: described.fields.map((field) =>
      field.name === 'auth_mode' ? { ...field, options: field.options.map((option) => (option.value === 'token' ? { ...option, label: 'کلید API' } : option)) } : field,
    ),
  }
}

describe('a server’s connection', () => {
  const form = serverRow().form ?? {}

  it('is read off its form: how the shop signs in, the subscription links’ prefix, the TLS check, the timeout', async () => {
    const fact = facts(
      serverRow({
        form: { ...form, auth_mode: 'password', username: 'admin', totp_secret: { set: true, hint: '••••••••' }, subscription_url: 'https://sub.example.com/s', verify_tls: false, timeout: 45 },
      }),
    )

    expect(await fact('احراز هویت')).toBe('نام کاربری (admin) + TOTP')
    expect(await fact('لینک اشتراک')).toBe('https://sub.example.com/s')
    expect(await fact('گواهی TLS')).toBe('بررسی نمی‌شود')
    expect(await fact('تایم‌اوت')).toBe('۴۵ ثانیه')
  })

  it('names a token way in by its connector’s own word, once that is read', async () => {
    const fact = facts(serverRow(), keyedConnector())
    expect(await fact('احراز هویت')).toBe('کلید API')
  })

  it('says nothing of a token way in before its connector’s word is read', async () => {
    facts(serverRow())

    expect(await screen.findByText('گواهی TLS')).toBeTruthy()
    expect(screen.queryByText('احراز هویت')).toBeNull()
  })

  it('is said of nothing while this installation has not its connector', async () => {
    facts(serverRow({ driver: 'marzban', form: null }))

    expect(await screen.findByText('اشتراک‌های فعال')).toBeTruthy()
    expect(screen.queryByText('احراز هویت')).toBeNull()
    expect(screen.queryByText('تایم‌اوت')).toBeNull()
  })
})
