import { render, screen, within } from '@testing-library/react'
import { Route, Routes } from 'react-router'
import { describe, expect, it } from 'vitest'
import type { ReferrersResponse } from '@/lib/api-types'
import { ReferralsPage } from '@/pages/referrals'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The referral program's page (pages/referrals): numbers that could not be read say so, with a way to ask again, and
 * stay «—» — never zeros, nothing pulsing for ever — while the lists stand; a referrer's count is the way to the ones
 * they brought, what it opens part of its name.
 */

const META = { page: 1, per_page: 25, total: 1, last_page: 1, sort: 'referrals', dir: 'desc' } as const

function open() {
  render(
    <Routes>
      <Route path="/referrals/*" element={<ReferralsPage />} />
    </Routes>,
    { wrapper: providers({ at: '/referrals' }).wrapper },
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
          referrers: [{ id: 4, user: { id: 4, name: 'رضا', username: 'reza', telegram_id: 55, email: null }, status: 'active', referrals: 2, earned: '10000.00', last_referral_at: null }],
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
