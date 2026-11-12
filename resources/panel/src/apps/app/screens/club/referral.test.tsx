import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ReferralScreen from '@/apps/app/screens/club/referral'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreReferralResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «دعوت دوستان» (screens/club/referral): the invite link and code, the program's terms and what it brought, and the link
 * sent to a chat through Telegram's share sheet.
 */

function referralRow(overrides: Partial<StoreReferralResponse> = {}): StoreReferralResponse {
  return {
    enabled: true,
    rate: 10,
    renewal_rate: 5,
    first_only: false,
    start_gift: { referrer: '20000.00', invitee: '10000.00', trigger: 'purchase' },
    reward: { every: 5, plan: { id: 4, name: 'یک‌ماهه' } },
    code: 'k7m2xq9p',
    bot_link: 'https://t.me/sample_bot?start=ref_k7m2xq9p',
    invited: 3,
    earned: '36000.00',
    rewards: 0,
    ...overrides,
  }
}

describe('the referral program', () => {
  it('shows the invite link, the terms and what it brought, and shares the link through Telegram', async () => {
    const { app: telegram, mainButton } = app.telegram()
    server().on('GET', `${API}/referral`, json(referralRow()))
    render(app.screen(<ReferralScreen />, { at: '/club/referral' }))

    expect(await screen.findByText('https://t.me/sample_bot?start=ref_k7m2xq9p')).toBeTruthy()
    expect(screen.getByText('k7m2xq9p')).toBeTruthy()
    expect(screen.getByText('۳۶٬۰۰۰')).toBeTruthy()
    expect(screen.getByText('سهم شما از پرداخت‌ها').closest('div')?.parentElement?.textContent).toContain('۱۰٪')
    expect(screen.getByText('سهم از تمدیدها').closest('div')?.parentElement?.textContent).toContain('۵٪')
    expect(screen.getByText(/با اولین خرید دوستتان: ۲۰٬۰۰۰ تومان برای شما و ۱۰٬۰۰۰ تومان برای او/)).toBeTruthy()
    expect(screen.getByText(/هر ۵ دوست خریدار: یک سرویس «یک‌ماهه» رایگان/)).toBeTruthy()

    await until(() => expect(mainButton.params).toMatchObject({ text: 'اشتراک‌گذاری در تلگرام', is_visible: true }))
    mainButton.press()

    const shared = new URL(vi.mocked(telegram.openTelegramLink).mock.calls[0]?.[0] ?? '')
    expect(`${shared.origin}${shared.pathname}`).toBe('https://t.me/share/url')
    expect(shared.searchParams.get('url')).toBe('https://t.me/sample_bot?start=ref_k7m2xq9p')
    expect(shared.searchParams.get('text')).toBe('با این لینک در فروشگاه نمونه عضو شو و ۱۰٬۰۰۰ تومان هدیه بگیر.')
  })

  it('offers the code alone while the bot has no link, and leaves out the terms of a program switched off', async () => {
    const { mainButton } = app.telegram()
    server().on('GET', `${API}/referral`, json(referralRow({ enabled: false, bot_link: null })))
    render(app.screen(<ReferralScreen />, { at: '/club/referral' }))

    expect(await screen.findByText('k7m2xq9p')).toBeTruthy()
    expect(screen.queryByText('لینک دعوت')).toBeNull()
    expect(screen.queryByText('سهم شما از پرداخت‌ها')).toBeNull()
    expect(screen.getByText(/برنامه دعوت فعلا فعال نیست/)).toBeTruthy()
    expect(mainButton.isVisible).toBe(false)
  })
})
