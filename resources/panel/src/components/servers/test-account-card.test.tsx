import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TestAccountCard } from '@/components/servers/test-account-card'
import type { ServerTestAccount, ServerTestAccountResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * A server's «اکانت تست» card (components/servers/test-account-card): the server's own test terms read and saved apart
 * from its connection — the traffic and hours as typed, the hint saying what they come to (or the bounds) and where the
 * hours count from, the switch's leading to where each shop turns its own tests on —, each refusal under its field, and
 * the card scrolled into view at `#test-account`.
 */

const TERMS: ServerTestAccount = { test_enabled: false, test_traffic_mb: 500, test_duration_hours: 24, test_on_hold: true }

function showCard() {
  server().on('GET', '/api/admin/servers/1/test-account', json({ test_account: TERMS } satisfies ServerTestAccountResponse))
  render(<TestAccountCard serverId={1} />, { wrapper: providers({ at: '/servers/1#test-account' }).wrapper })
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('a server’s test account', () => {
  it('shows its terms, what they come to and where the hours count from', async () => {
    showCard()

    expect(((await screen.findByLabelText('حجم (مگابایت)')) as HTMLInputElement).value).toBe('500')
    expect(screen.getByText('هر اکانت تست ۵۰۰ مگابایت')).toBeTruthy()
    expect(screen.getByText('هر اکانت تست ۱ روز')).toBeTruthy()
    expect(screen.getByRole('switch', { name: 'اکانت تست روی این سرور' }).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByRole('link', { name: 'اکانت تست › تنظیمات' }).getAttribute('href')).toBe('/tests/settings')
    expect(screen.getByRole('switch', { name: 'شروع از اولین اتصال' }).getAttribute('aria-checked')).toBe('true')
    expect(screen.getByText(/روشن: ساعت‌ها از اولین اتصال مشتری شمرده می‌شوند/)).toBeTruthy()
  })

  it('scrolls itself into view once drawn, the address naming it', async () => {
    const scrolled = vi.spyOn(HTMLElement.prototype, 'scrollIntoView').mockReturnValue()
    showCard()

    await screen.findByLabelText('حجم (مگابایت)')
    expect(scrolled.mock.contexts).toEqual([document.getElementById('test-account')])
  })

  it('saves them as typed', async () => {
    showCard()
    server().on('PUT', '/api/admin/servers/1/test-account', json({ test_account: { test_enabled: true, test_traffic_mb: 1024, test_duration_hours: 6, test_on_hold: false } }))
    await screen.findByLabelText('حجم (مگابایت)')

    fireEvent.click(screen.getByRole('switch', { name: 'اکانت تست روی این سرور' }))
    type('حجم (مگابایت)', '۱۰۲۴')
    type('مدت (ساعت)', '6')
    fireEvent.click(screen.getByRole('switch', { name: 'شروع از اولین اتصال' }))
    expect(screen.getByText('هر اکانت تست ۱ گیگابایت')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() =>
      expect(server().sent('PUT', '/api/admin/servers/1/test-account')[0]?.body).toEqual({ test_enabled: true, test_traffic_mb: '۱۰۲۴', test_duration_hours: '6', test_on_hold: false }),
    )
  })

  it('says each refusal under its field', async () => {
    showCard()
    server().on(
      'PUT',
      '/api/admin/servers/1/test-account',
      refusal(422, 'اطلاعات را بررسی کنید.', { test_traffic_mb: ['حجم اکانت تست باید بین 1 و 102400 باشد.'], test_duration_hours: ['مدت اکانت تست باید بین 1 و 720 باشد.'] }),
    )
    await screen.findByLabelText('حجم (مگابایت)')

    type('حجم (مگابایت)', '0')
    type('مدت (ساعت)', '721')
    expect(screen.getByText('بین 1 تا 102400 مگابایت')).toBeTruthy()
    expect(screen.getByText('بین 1 تا 720 ساعت')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText('حجم اکانت تست باید بین 1 و 102400 باشد.')).toBeTruthy()
    expect(screen.getByText('مدت اکانت تست باید بین 1 و 720 باشد.')).toBeTruthy()
    expect(screen.getByLabelText('حجم (مگابایت)').getAttribute('aria-invalid')).toBe('true')
  })
})
