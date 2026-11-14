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

  it('links no club to a customer the perks do not reach while the club holds only perks', () => {
    const shop = withoutReviews()
    const noReferrals = { ...shop, referral: { ...shop.referral, enabled: false } }

    const { unmount } = render(app.screen(<AccountScreen />, { at: '/account', account: app.account(), shop: noReferrals }))
    expect(screen.getByRole('link', { name: /باشگاه مشتریان/ })).toBeTruthy()
    unmount()

    open(app.account({ perks: false }), noReferrals)
    expect(screen.queryByRole('link', { name: /باشگاه مشتریان/ })).toBeNull()
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

  it('asks the number before everything, or for the moments the shop chose — never of a customer support excused', () => {
    app.telegram({ requestContact: vi.fn() })
    const everywhere = app.shop({ shop: { ...app.shop().shop, phone_required: true } })
    const moments = (phone_moments: StoreResponse['shop']['phone_moments']) => app.shop({ shop: { ...app.shop().shop, phone_moments } })
    /** The ask's words on the screen for `shop` (null: not asked) — the customer excused, or not. */
    const asked = (shop: StoreResponse, phone_exempt = false) => {
      const account = app.account()
      const { unmount } = render(app.screen(<AccountScreen />, { at: '/account', account: { ...account, customer: { ...account.customer, phone_exempt } }, shop }))
      const words = screen.queryByRole('button', { name: /ثبت شماره تلفن/ })?.textContent ?? null
      unmount()

      return words
    }

    expect(asked(everywhere)).toContain('ربات فروشگاه شماره تلفن شما را لازم دارد')
    expect(asked(moments(['purchase', 'renewal']))).toContain('برای خرید و تمدید لازم است')
    expect(asked(moments(['test']))).toContain('برای اکانت تست لازم است')
    expect(asked(moments(['purchase', 'renewal', 'test', 'card']))).toContain('برای خرید، تمدید، اکانت تست و پرداخت کارت به کارت لازم است')
    expect(asked(everywhere, true)).toBeNull()
    expect(asked(moments(['purchase']), true)).toBeNull()
  })

  it('asks an Iranian number again of one whose number is another country’s, while the shop takes Iranian ones alone', () => {
    app.telegram({ requestContact: vi.fn() })
    const account = app.account()
    const shop = (phone_iran_only: boolean) => app.shop({ shop: { ...app.shop().shop, phone_required: true, phone_iran_only } })
    const show = (phone: string, iranOnly: boolean) =>
      render(app.screen(<AccountScreen />, { at: '/account', account: { ...account, customer: { ...account.customer, phone } }, shop: shop(iranOnly) }))

    const foreign = show('+491701234567', true)
    // What the number is needed for stays on the row; the notice says the +98 rule.
    expect(screen.getByRole('button', { name: /ثبت شماره ایرانی/ }).textContent).toContain('ربات فروشگاه شماره تلفن شما را لازم دارد')
    expect(screen.getByText(/این فروشگاه فقط شماره‌های ایران/).textContent).toContain('+491701234567')
    foreign.unmount()

    const iranian = show('+989121234567', true)
    expect(screen.queryByRole('button', { name: /ثبت شماره/ })).toBeNull()
    expect(screen.getByText('+989121234567')).toBeTruthy()
    iranian.unmount()

    show('+491701234567', false)
    expect(screen.queryByRole('button', { name: /ثبت شماره/ })).toBeNull()
  })

  it('names the moments and the +98 rule to a customer with no number, while the shop takes Iranian ones alone', () => {
    app.telegram({ requestContact: vi.fn() })
    open(app.account(), app.shop({ shop: { ...app.shop().shop, phone_moments: ['purchase', 'card'], phone_iran_only: true } }))

    const words = screen.getByRole('button', { name: /ثبت شماره تلفن/ }).textContent
    expect(words).toContain('برای خرید و پرداخت کارت به کارت لازم است')
    expect(words).toContain('فقط شماره ایران (+98)')
  })

  it('promises nothing to one whose number is another country’s: Telegram sends the bot that same number', () => {
    app.telegram({ requestContact: (callback) => callback?.(true) })
    const account = app.account()
    open({ ...account, customer: { ...account.customer, phone: '+491701234567' } }, app.shop({ shop: { ...app.shop().shop, phone_required: true, phone_iran_only: true } }))

    fireEvent.click(screen.getByRole('button', { name: /ثبت شماره ایرانی/ }))
    expect(screen.getByText('اگر شماره تلگرامتان ایرانی است چند لحظه بعد اینجا ثبت می‌شود؛ وگرنه با پشتیبانی تماس بگیرید.')).toBeTruthy()
    expect(screen.queryByText(/شماره شما برای ربات فرستاده شد/)).toBeNull()
  })

  it('says so when the customer would not share it', () => {
    app.telegram({ requestContact: (callback) => callback?.(false) })
    open(app.account(), app.shop({ shop: { ...app.shop().shop, phone_required: true } }))

    fireEvent.click(screen.getByRole('button', { name: /ثبت شماره تلفن/ }))
    expect(screen.getByText('شماره فرستاده نشد؛ هر وقت خواستید دوباره بزنید.')).toBeTruthy()
  })
})
