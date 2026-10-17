import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ReferralSection } from '@/components/bot/settings/referral-section'
import type { BotSettingsSaved } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { botSettingsRow, planRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The referral program in the bot settings (components/bot/settings/referral-section): one group in four parts — the
 * commissions, the start gift, the plan reward with a plan of the shop's, the alerts —, saved whole as the admin typed
 * it; an amount written another way is no change; the commission's example is of a rate the server takes; what a refund
 * does to a commission is said; the server's refusal of a part is said under its field.
 */

/** The option `option` picked in the select labelled `label`, once its options are there. */
async function pick(label: string, option: string | RegExp) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: option }))
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

/** The line under the commission's percent. */
const rateHint = () => document.getElementById('referral_rate-hint')?.textContent

const saved = (overrides: Parameters<typeof botSettingsRow>[0] = {}): BotSettingsSaved => ({ settings: botSettingsRow(overrides) })

describe('the referral program', () => {
  it('saves its four parts whole, the plan a plan of the shop', async () => {
    server()
      .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 3, name: 'طلایی' }), planRow({ id: 4, name: 'جایزه' })] }))
      .on('GET', '/api/admin/keyboards', refusal(500, 'خطای سرور.'))
      .on('PUT', '/api/admin/bot/settings/referral', json(saved({ referral_enabled: true, referral_reward_every: 5, referral_reward_plan_id: 4 })))
    render(<ReferralSection settings={botSettingsRow({ referral_enabled: true, referral_start_referrer: '20000.00' })} />, { wrapper: providers().wrapper })

    expect((screen.getByLabelText('هدیه معرف (تومان)') as HTMLInputElement).value).toBe('20000')
    type('هدیه معرف (تومان)', '۲۰٬۰۰۰')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()

    type('درصد پورسانت تمدید', '0')
    expect(screen.getByText('تمدید سرویس پورسانت ندارد.')).toBeTruthy()
    type('هدیه زیرمجموعه (تومان)', '10000')
    fireEvent.click(screen.getByRole('radio', { name: 'با عضویت' }))
    type('به ازای هر چند زیرمجموعه خریدار', '5')
    await until(() => expect(server().sent('GET', '/api/admin/plans')).toHaveLength(1))
    await until(() => expect(screen.getByRole('combobox', { name: 'پلن جایزه' }).textContent).toContain('انتخاب پلن'))
    await pick('پلن جایزه', /^جایزه/)
    type('عضویت پشت سر هم', '0')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/referral')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/bot/settings/referral')[0]?.body).toEqual({
      referral_enabled: true,
      referral_rate: '10',
      referral_renewal_rate: '0',
      referral_first_only: false,
      referral_start_referrer: '۲۰٬۰۰۰',
      referral_start_invitee: '10000',
      referral_start_when: 'join',
      referral_reward_every: '5',
      referral_reward_plan_id: '4',
      referral_alert_burst: '0',
      referral_alert_minutes: '60',
      referral_alert_idle: '20',
    })
  })

  it('works the commission out of a rate the server would take, and of nothing else', () => {
    server().on('GET', '/api/admin/plans', json({ plans: [] }))
    render(<ReferralSection settings={botSettingsRow()} />, { wrapper: providers().wrapper })
    fireEvent.click(screen.getByRole('switch', { name: 'زیرمجموعه‌گیری فعال باشد' }))

    type('درصد پورسانت', '۱۵')
    expect(rateHint()).toBe('از پرداخت ۱۰۰٬۰۰۰ تومان، ۱۵٬۰۰۰ تومان به کیف پول معرف اضافه می‌شود.')
    // Not 15: a whole percent is all the server reads.
    type('درصد پورسانت', '1.5')
    expect(rateHint()).toBe('درصدی از مبلغ هر پرداخت')
    type('درصد پورسانت', '150')
    expect(rateHint()).toBe('درصدی از مبلغ هر پرداخت')
  })

  it("says a top-up's refund takes its commission back, refused once the referrer spent it, and a purchase's leaves it", () => {
    server().on('GET', '/api/admin/plans', json({ plans: [] }))
    render(<ReferralSection settings={botSettingsRow()} />, { wrapper: providers().wrapper })

    expect(screen.getByText(/برگشت وجه شارژ کیف پول، که پولش از فروشگاه بیرون می‌رود، پورسانتش را از کیف پول معرف پس می‌گیرد؛ اگر معرف آن را خرج کرده باشد، برگشت وجه انجام نمی‌شود/)).toBeTruthy()
    expect(screen.getByText(/برگشت وجه خرید یا تمدید به کیف پول مشتری است و پولش در فروشگاه می‌ماند، پس پورسانتش هم می‌ماند/)).toBeTruthy()
  })

  it('says a refusal under its field', async () => {
    server()
      .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 4, name: 'جایزه' })] }))
      .on('PUT', '/api/admin/bot/settings/referral', refusal(422, 'پلن جایزه را انتخاب کنید.', { referral_reward_plan_id: ['پلن جایزه را انتخاب کنید.'] }))
    render(<ReferralSection settings={botSettingsRow()} />, { wrapper: providers().wrapper })

    type('به ازای هر چند زیرمجموعه خریدار', '3')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText('پلن جایزه را انتخاب کنید.', { selector: '#referral_reward_plan_id-error' })).toBeTruthy()
  })

  it('switched off, holds its numbers as they are kept — but one changed stays open, to fix once the server refused it', async () => {
    const tooHigh = 'درصد پورسانت باید بین 1 تا 100 باشد.'
    server()
      .on('GET', '/api/admin/plans', json({ plans: [] }))
      .on('GET', '/api/admin/keyboards', refusal(500, 'خطای سرور.'))
      .on('PUT', '/api/admin/bot/settings/referral', refusal(422, tooHigh, { referral_rate: [tooHigh] }))
    render(<ReferralSection settings={botSettingsRow({ referral_enabled: true })} />, { wrapper: providers().wrapper })
    const rate = screen.getByLabelText<HTMLInputElement>('درصد پورسانت')

    type('درصد پورسانت', '150')
    fireEvent.click(screen.getByRole('switch', { name: 'زیرمجموعه‌گیری فعال باشد' }))
    expect(screen.getByLabelText<HTMLInputElement>('درصد پورسانت تمدید').disabled).toBe(true)
    expect(screen.getByLabelText<HTMLInputElement>('هدیه معرف (تومان)').disabled).toBe(true)
    expect(rate.disabled).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(tooHigh, { selector: '#referral_rate-error' })).toBeTruthy()
    expect(rate.disabled).toBe(false)
    expect(rate.getAttribute('aria-invalid')).toBe('true')
  })
})
