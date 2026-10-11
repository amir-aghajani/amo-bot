import { fireEvent, render, screen, within } from '@testing-library/react'
import { Route, Routes } from 'react-router'
import { describe, expect, it } from 'vitest'
import type { ReferralAlertsResponse, ReferralSummaryResponse, ReferrersResponse } from '@/lib/api-types'
import { ReferralsPage } from '@/pages/referrals'
import { providers, until } from '@/test/render'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * The referral program's page (pages/referrals): numbers that could not be read say so, with a way to ask again, and
 * stay «—» — never zeros, nothing pulsing for ever — while the lists stand; a referrer's count is the way to the ones
 * they brought, what it opens part of its name; a referrer an alert stands on is marked, the mark the way to their
 * alerts. The alerts — newest first, why in words — are cleared after a question, a refusal said in it.
 */

const META = { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'referrals', dir: 'desc' } as const

const SUMMARY: ReferralSummaryResponse = {
  summary: { enabled: false, rate: 10, renewal_rate: 0, first_only: false, referrers: 1, referred: 2, commissions: 1, paid: '10000.00', alerts: 1 },
}

const REZA = { id: 4, name: 'رضا', username: 'reza', telegram_id: 55, email: null }

const ALERTS: ReferralAlertsResponse = {
  alerts: [
    { id: 8, referrer: REZA, status: 'active', reason: 'idle', count: 20, created_at: '2026-10-01T10:00:00Z' },
    { id: 7, referrer: REZA, status: 'active', reason: 'burst', count: 10, created_at: '2026-09-30T10:00:00Z' },
  ],
  meta: { page: 1, per_page: 25, total: 2, last_page: 1 },
}

function open(at = '/referrals') {
  render(
    <Routes>
      <Route path="/referrals/*" element={<ReferralsPage />} />
    </Routes>,
    { wrapper: providers({ at }).wrapper },
  )
}

describe('the program’s numbers', () => {
  it('say their read failed and stay «—», the lists standing', async () => {
    server()
      .on('GET', '/api/admin/referrals', refusal(500, 'خطای سرور.'))
      .on(
        'GET',
        '/api/admin/referrals/referrers',
        json({
          referrers: [{ id: 4, user: REZA, status: 'active', referrals: 2, earned: '10000.00', last_referral_at: null, alerts: 0 }],
          meta: META,
        } satisfies ReferrersResponse),
      )
    open()

    expect(await screen.findByText('آمار زیرمجموعه‌گیری بارگذاری نشد.')).toBeTruthy()
    const numbers = screen.getByRole('region', { name: 'آمار زیرمجموعه‌گیری' })
    expect(within(numbers).getAllByText('—')).toHaveLength(4)
    expect(within(numbers).queryByText('۰')).toBeNull()
    expect(numbers.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0)

    // The list stands, and a referrer's count says what it opens.
    await until(() => expect(screen.getByRole('link', { name: /^۲ نفر ?، زیرمجموعه‌های این معرف$/ }).getAttribute('href')).toBe('/referrals/invitees?referrer=4'))
  })
})

describe('the alerts', () => {
  it('mark the referrer they stand on, the mark the way to theirs', async () => {
    server()
      .on('GET', '/api/admin/referrals', json(SUMMARY))
      .on(
        'GET',
        '/api/admin/referrals/referrers',
        json({ referrers: [{ id: 4, user: REZA, status: 'active', referrals: 2, earned: '0.00', last_referral_at: null, alerts: 2 }], meta: META } satisfies ReferrersResponse),
      )
    open()

    const mark = await screen.findByRole('link', { name: /^هشدار/ })
    expect(mark.getAttribute('href')).toBe('/referrals/alerts?search=%234')
    expect(screen.getByText('۱۰٪ از هر پرداخت؛ تمدید بدون پورسانت')).toBeTruthy()
  })

  it('are listed newest first with why, and one is cleared after a question', async () => {
    server().on('GET', '/api/admin/referrals', json(SUMMARY)).on('GET', '/api/admin/referrals/alerts', json(ALERTS)).on('POST', '/api/admin/referrals/alerts/7/clear', noContent)
    open('/referrals/alerts')

    const rows = await screen.findAllByRole('row')
    expect(rows.slice(1).map((row) => within(row).getAllByRole('cell')[1]?.textContent)).toEqual(['۲۰ زیرمجموعه بدون هیچ خرید', '۱۰ عضویت پشت سر هم با یک لینک'])

    fireEvent.click(within(rows[2]!).getByRole('button', { name: /^رفع هشدار/ }))
    const dialog = await screen.findByRole('dialog', { name: 'رفع هشدار' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'رفع هشدار' }))

    await until(() => expect(server().sent('POST', '/api/admin/referrals/alerts/7/clear')).toHaveLength(1))
    await until(() => expect(screen.queryByRole('dialog', { name: 'رفع هشدار' })).toBeNull())
    await until(() => expect(screen.queryByText('۱۰ عضویت پشت سر هم با یک لینک')).toBeNull())
  })

  it('say a refusal in the question, the list read again', async () => {
    server()
      .on('GET', '/api/admin/referrals', json(SUMMARY))
      .on('GET', '/api/admin/referrals/alerts', json(ALERTS))
      .on('POST', '/api/admin/referrals/alerts/8/clear', refusal(403, 'هشدار زیرمجموعه‌گیری خودتان را نمی‌توانید رفع کنید؛ این کار با مدیر دیگری است.'))
    open('/referrals/alerts')

    const rows = await screen.findAllByRole('row')
    fireEvent.click(within(rows[1]!).getByRole('button', { name: /^رفع هشدار/ }))
    const dialog = await screen.findByRole('dialog', { name: 'رفع هشدار' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'رفع هشدار' }))

    expect(await within(dialog).findByText(/خودتان را نمی‌توانید رفع کنید/)).toBeTruthy()
    await until(() => expect(server().sent('GET', '/api/admin/referrals/alerts').length).toBeGreaterThan(1))
  })
})
