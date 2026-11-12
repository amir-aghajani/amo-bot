import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import AccountScreen from '@/apps/app/screens/account/account'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse, StoreResponse } from '@/lib/api-types'
import { json, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «حساب من» (screens/account/account): the customer's card, the ways the account signs in — Telegram, an email to add,
 * Google from the website alone —, the way to security, and the phone number Telegram sends the bot while the shop's bot
 * asks for one; the account read again until it shows; the shop's support and bot reached as the shop gives them.
 */

/** The shop as `GET /` introduces it, its reviews not shown in the app. */
function withoutReviews(): StoreResponse {
  const shop = app.shop()

  return { ...shop, shop: { ...shop.shop, programs: { ...shop.shop.programs, reviews: false } } }
}

function open(account: StoreAccountResponse = app.account(), shop: StoreResponse = withoutReviews()) {
  render(app.screen(<AccountScreen />, { at: '/account', account, shop }))
}

/** The section titled `title`. */
function section(title: string): HTMLElement {
  return screen.getByRole('heading', { name: title }).closest('section')!
}

describe('the account screen', () => {
  it("shows the customer's card and the ways in: Telegram, an email to add, Google only from the website", async () => {
    open()

    expect(screen.getByText('علی')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'کپی شناسه تلگرام 42' })).toBeTruthy()
    const ways = section('روش‌های ورود')
    expect(within(ways).getByText('تلگرام')).toBeTruthy()
    expect(within(ways).getByText('@ali')).toBeTruthy()
    expect(
      within(ways)
        .getByRole('link', { name: /افزودن ایمیل/ })
        .getAttribute('href'),
    ).toBe('/account/email')
    expect(within(ways).getByText('Google')).toBeTruthy()
    expect(within(ways).getByText('وصل نیست')).toBeTruthy()
    expect(within(ways).getByText('از وب‌سایت فروشگاه اضافه می‌شود')).toBeTruthy()
    expect(screen.getByRole('link', { name: /امنیت/ }).getAttribute('href')).toBe('/account/security')
    expect(screen.getByRole('link', { name: /باشگاه مشتریان/ }).getAttribute('href')).toBe('/club')
    // The shop's word on its reviews is `GET /`'s: nothing asked to find out.
    expect(screen.queryByRole('link', { name: /نظرات/ })).toBeNull()
    expect(server().sent('GET', `${API}/reviews`)).toHaveLength(0)
    // The shop's bot asks no number: none is asked here either.
    expect(screen.queryByRole('button', { name: /ثبت شماره تلفن/ })).toBeNull()
  })

  it('shows an email and Google that sign in already, and the reviews while the shop shows them', async () => {
    const linked = app.account()
    render(app.screen(<AccountScreen />, { at: '/account', account: { ...linked, customer: { ...linked.customer, email: 'ali@example.com', google: true } } }))

    const ways = section('روش‌های ورود')
    expect(within(ways).getByText('ali@example.com')).toBeTruthy()
    expect(within(ways).queryByRole('link', { name: /افزودن ایمیل/ })).toBeNull()
    expect(within(ways).getAllByText('وصل است')).toHaveLength(3)
    expect((await screen.findByRole('link', { name: /نظرات مشتریان/ })).getAttribute('href')).toBe('/reviews')
    expect(server().sent('GET', `${API}/reviews`)).toHaveLength(0)
  })

  it("sends the customer's phone to the bot while the shop's bot asks for it, and reads the account again until it shows", async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const requestContact = vi.fn((callback?: (shared: boolean) => void) => callback?.(true))
    app.telegram({ requestContact })
    const without = app.account()
    server().on('GET', `${API}/me`, json(app.account({ customer: { ...without.customer, phone: '+989121234567' } })))
    open(without, app.shop({ shop: { ...app.shop().shop, phone_required: true } }))

    fireEvent.click(screen.getByRole('button', { name: /ثبت شماره تلفن/ }))
    expect(requestContact).toHaveBeenCalledTimes(1)
    expect(screen.getByText('شماره شما برای ربات فرستاده شد؛ چند لحظه بعد اینجا ثبت می‌شود.')).toBeTruthy()
    expect(server().sent('GET', `${API}/me`)).toHaveLength(0)

    await act(() => vi.advanceTimersByTimeAsync(3_000))
    expect(await screen.findByText('+989121234567')).toBeTruthy()
    expect(server().sent('GET', `${API}/me`)).toHaveLength(1)
    expect(screen.queryByRole('button', { name: /ثبت شماره تلفن/ })).toBeNull()
  })

  it('reaches the shop as it is given: a phone number by the device, the bot inside Telegram', () => {
    const { app: telegram } = app.telegram()
    const handed = vi.spyOn(window.location, 'href', 'set').mockImplementation(() => {})
    open(app.account(), app.shop({ support: { url: 'tel:+982112345678' } }))

    fireEvent.click(screen.getByRole('button', { name: /پیام به پشتیبانی/ }))
    expect(handed).toHaveBeenCalledWith('tel:+982112345678')
    expect(telegram.openLink).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: /ربات فروشگاه/ }))
    expect(telegram.openTelegramLink).toHaveBeenCalledWith('https://t.me/sample_bot')
  })

  it('says so when the customer would not share it', () => {
    app.telegram({ requestContact: (callback) => callback?.(false) })
    open(app.account(), app.shop({ shop: { ...app.shop().shop, phone_required: true } }))

    fireEvent.click(screen.getByRole('button', { name: /ثبت شماره تلفن/ }))
    expect(screen.getByText('شماره فرستاده نشد؛ هر وقت خواستید دوباره بزنید.')).toBeTruthy()
  })
})
