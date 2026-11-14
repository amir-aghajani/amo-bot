import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { PhoneCard } from '@/components/customer/phone-card'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { botSettingsResponse, botSettingsRow, userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «شماره موبایل» on a customer's page (components/customer/phone-card): whether they verified their number — another
 * country's said to be asked again while the bot takes Iranian ones alone —, and their excuse from the shop's phone
 * rule, switched at once through its own endpoint — the customer's row as the server answers it put in place, what else
 * shows it read again —; a bot admin, never asked anyway, has the switch held.
 */

const EXEMPT = 'معاف از ارسال شماره موبایل'
const ASKED_AGAIN = /شماره‌اش ایرانی نیست و ربات دوباره شماره ایرانی از او می‌خواهد/

describe('the customer’s number', () => {
  /** The card for a customer of `row`, once the bot's settings are read: the phone rule on, Iranian numbers alone or any. */
  async function show(row: Parameters<typeof userRow>[0], iranOnly: boolean) {
    server().on('GET', '/api/admin/bot/settings', json(botSettingsResponse(botSettingsRow({ phone_required: true, phone_iran_only: iranOnly }))))
    const { client, wrapper } = providers()
    render(<PhoneCard customer={userRow(row)} invalidates={[]} onSaved={() => {}} />, { wrapper })
    await until(() => expect(client.getQueryState(queryKeys.botSettings)?.status).toBe('success'))
  }

  it('says another country’s is asked again while the bot takes Iranian numbers alone, and offers the excuse', async () => {
    await show({ phone: '+491701234567' }, true)

    expect(screen.getByText(ASKED_AGAIN)).toBeTruthy()
  })

  it('says it is verified while any number counts', async () => {
    await show({ phone: '+491701234567' }, false)

    expect(screen.getByText('شماره‌اش را تایید کرده است.')).toBeTruthy()
  })

  it('says it is verified of a customer excused from it', async () => {
    await show({ phone: '+491701234567', phone_exempt: true }, true)

    expect(screen.getByText('شماره‌اش را تایید کرده است.')).toBeTruthy()
    expect(screen.queryByText(ASKED_AGAIN)).toBeNull()
  })

  it('reads no bot settings for an Iranian number', () => {
    render(<PhoneCard customer={userRow({ phone: '+989121234567' })} invalidates={[]} onSaved={() => {}} />, { wrapper: providers().wrapper })

    expect(screen.getByText('شماره‌اش را تایید کرده است.')).toBeTruthy()
    expect(server().sent('GET', '/api/admin/bot/settings')).toEqual([])
  })
})

describe('the customer’s excuse from the phone rule', () => {
  it('is switched at once through its own endpoint, the answered row put in place and the users table read again', async () => {
    const saved = vi.fn()
    const { client, wrapper } = providers()
    client.setQueryData(queryKeys.users, {})
    server().on('PUT', '/api/admin/users/10/phone-exempt', json({ user: userRow({ phone_exempt: true }) }))
    render(<PhoneCard customer={userRow({ phone: null })} invalidates={[queryKeys.users]} onSaved={saved} />, { wrapper })

    expect(screen.getByText('هنوز شماره‌ای تایید نکرده است.')).toBeTruthy()
    const exempt = screen.getByRole('switch', { name: EXEMPT })
    expect(exempt.getAttribute('aria-checked')).toBe('false')
    fireEvent.click(exempt)

    await until(() => expect(server().sent('PUT', '/api/admin/users/10/phone-exempt')[0]?.body).toEqual({ phone_exempt: true }))
    await until(() => expect(saved).toHaveBeenCalledWith(expect.objectContaining({ phone_exempt: true })))
    await until(() => expect(client.getQueryState(queryKeys.users)?.isInvalidated).toBe(true))
  })

  it('is taken away the same way', async () => {
    server().on('PUT', '/api/admin/users/10/phone-exempt', json({ user: userRow() }))
    render(<PhoneCard customer={userRow({ phone_exempt: true })} invalidates={[]} onSaved={() => {}} />, { wrapper: providers().wrapper })

    expect(screen.getByText('شماره‌اش را تایید کرده است.')).toBeTruthy()
    fireEvent.click(screen.getByRole('switch', { name: EXEMPT, checked: true }))

    await until(() => expect(server().sent('PUT', '/api/admin/users/10/phone-exempt')[0]?.body).toEqual({ phone_exempt: false }))
  })

  it('is held for a bot admin, who is never asked anyway — and says so', () => {
    render(<PhoneCard customer={userRow({ role: 'admin' })} invalidates={[]} onSaved={() => {}} />, { wrapper: providers().wrapper })

    const exempt = screen.getByRole('switch', { name: EXEMPT })
    expect(exempt.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByText(/مدیرهای ربات هیچ‌وقت برای شماره موبایل نگه داشته نمی‌شوند/)).toBeTruthy()
    fireEvent.click(exempt)

    expect(server().sent('PUT', '/api/admin/users/10/phone-exempt')).toEqual([])
  })
})
