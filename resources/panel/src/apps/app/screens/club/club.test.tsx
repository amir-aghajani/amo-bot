import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ClubScreen from '@/apps/app/screens/club/club'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import { json, refusal, server, type Answer } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «باشگاه مشتریان» (screens/club/club): each part only while the shop runs it — the loyalty tier with its perks and the
 * way to the next, the cashback note, and the ways in to the wheel, the nightly draw and the referral program; the
 * account that could not be read said so, to read again.
 */

const SILVER = app.account({
  loyalty: { tier: { id: 1, name: 'نقره‌ای', discount_percent: 5, cashback_percent: 2 }, spent: '300000.00', next: { name: 'طلایی', min_spent: '500000.00', missing: '200000.00' } },
})

function shopWith(programs: Partial<ReturnType<typeof app.shop>['shop']['programs']>, referral = true) {
  const shop = app.shop()
  shop.shop.programs = { ...shop.shop.programs, ...programs }
  shop.referral = { ...shop.referral, enabled: referral }
  return shop
}

describe('the customer club', () => {
  it('shows the tier with its perks and the way to the next, the cashback, and every program running', () => {
    render(app.screen(<ClubScreen />, { at: '/club', account: SILVER }))

    const tier = screen.getByRole('region', { name: 'سطح وفاداری' })
    expect(tier.textContent).toContain('نقره‌ای')
    expect(tier.textContent).toContain('۵٪ تخفیف روی خریدها')
    expect(tier.textContent).toContain('۲٪ کش‌بک اضافه')
    expect(tier.textContent).toContain('۲۰۰٬۰۰۰ تومان تا سطح طلایی')
    expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('60')
    expect(screen.getByText(/سطح شما ۲٪ کش‌بک اضافه هم دارد/)).toBeTruthy()
    expect(screen.getByRole('link', { name: /گردونه شانس/ }).getAttribute('href')).toBe('/club/wheel')
    expect(screen.getByRole('link', { name: /قرعه‌کشی شبانه/ }).getAttribute('href')).toBe('/club/lottery')
    expect(screen.getByRole('link', { name: /دعوت دوستان/ }).textContent).toContain('۱۰٪ از پرداخت دوستانتان')
  })

  it('leaves out what the shop does not run', () => {
    render(app.screen(<ClubScreen />, { at: '/club', shop: shopWith({ loyalty: false, cashback: false, wheel: false }, false) }))

    expect(screen.queryByRole('region', { name: 'سطح وفاداری' })).toBeNull()
    expect(screen.queryByText('کش‌بک')).toBeNull()
    expect(screen.queryByRole('link', { name: /گردونه شانس/ })).toBeNull()
    expect(screen.queryByRole('link', { name: /دعوت دوستان/ })).toBeNull()
    expect(screen.getByRole('link', { name: /قرعه‌کشی شبانه/ })).toBeTruthy()
  })

  it('says so when the shop runs none of them', () => {
    render(app.screen(<ClubScreen />, { at: '/club', shop: shopWith({ loyalty: false, cashback: false, wheel: false, lottery: false }, false) }))

    expect(screen.getByText('باشگاه مشتریان فعلا برنامه‌ای ندارد')).toBeTruthy()
  })

  it('counts a customer below the first tier towards it', () => {
    const account = app.account({ loyalty: { tier: null, spent: '20000.00', next: { name: 'برنزی', min_spent: '100000.00', missing: '80000.00' } } })
    render(app.screen(<ClubScreen />, { at: '/club', account }))

    const tier = screen.getByRole('region', { name: 'سطح وفاداری' })
    expect(tier.textContent).toContain('هنوز بدون سطح')
    expect(tier.textContent).toContain('۸۰٬۰۰۰ تومان تا سطح برنزی')
  })

  it('says the account could not be read, and reads it again', async () => {
    const answers: Answer[] = [refusal(500, 'خطای داخلی فروشگاه.'), json(SILVER)]
    server().on('GET', `${API}/me`, () => answers.shift() ?? json(SILVER))
    render(app.screen(<ClubScreen />, { at: '/club', account: null }))

    expect((await screen.findByRole('alert')).textContent).toContain('خطای داخلی فروشگاه.')
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByRole('region', { name: 'سطح وفاداری' })).toBeTruthy()
  })
})
