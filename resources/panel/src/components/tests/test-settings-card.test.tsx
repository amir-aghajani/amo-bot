import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TestSettingsCard } from '@/components/tests/test-settings-card'
import { providers } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { refusal, server } from '@/test/server'

/*
 * The test accounts' rules (components/tests/test-settings-card): the quota's window says what it comes to as typed — a
 * window of days is never called «for good», whatever the count beside it says meanwhile; the clean-up's numbers wait
 * while it is off, but one changed stays open to fix.
 */

/** The line under a field: its hint, or its error. */
const lineUnder = (label: string) => document.getElementById(screen.getByLabelText(label).getAttribute('aria-describedby') ?? '')?.textContent

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

const hours = () => screen.getByLabelText('فاصله حذف (ساعت)') as HTMLInputElement
const unused = () => screen.getByLabelText('حذف اکانت وصل‌نشده (روز)') as HTMLInputElement

describe('the quota’s window', () => {
  it('says what a window of days comes to, and that 0 is for good', () => {
    render(<TestSettingsCard settings={botSettingsRow({ test_per_user: 2, test_period_days: 0 })} />, { wrapper: providers().wrapper })
    expect(lineUnder('دوره سهمیه (روز)')).toBe('0 یعنی برای همیشه: هر مشتری روی هم همین تعداد می‌گیرد.')

    type('دوره سهمیه (روز)', '۳۰')
    expect(lineUnder('دوره سهمیه (روز)')).toBe('هر مشتری در هر ۳۰ روز ۲ اکانت تست می‌گیرد.')
  })

  it('is still a window while the count beside it is no number yet', () => {
    render(<TestSettingsCard settings={botSettingsRow({ test_per_user: 2, test_period_days: 30 })} />, { wrapper: providers().wrapper })

    type('تعداد اکانت تست هر مشتری', '')

    expect(lineUnder('دوره سهمیه (روز)')).toBe('هر مشتری در هر ۳۰ روز همین تعداد اکانت تست می‌گیرد.')
  })
})

describe('the clean-up’s numbers', () => {
  it('wait while it is off — one changed stays open, the save carrying it, a refusal to fix there', async () => {
    const refused = 'فاصله حذف باید عددی بین 0 تا 8760 ساعت باشد.'
    server().on('PUT', '/api/admin/bot/settings/test_accounts', refusal(422, refused, { test_cleanup_hours: [refused] }))
    render(<TestSettingsCard settings={botSettingsRow({ test_cleanup: true, test_cleanup_hours: 24 })} />, { wrapper: providers().wrapper })

    type('فاصله حذف (ساعت)', '9000')
    fireEvent.click(screen.getByRole('switch', { name: 'اکانت‌های تمام‌شده حذف شوند' }))
    expect([hours().disabled, unused().disabled]).toEqual([false, true])
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(refused)).toBeTruthy()
    expect(hours().disabled).toBe(false)
  })
})
