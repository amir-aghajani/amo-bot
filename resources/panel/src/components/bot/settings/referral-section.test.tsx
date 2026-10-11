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
 * it; an amount written another way is no change; the server's refusal of a part is said under its field.
 */

/** The option `option` picked in the select labelled `label`, once its options are there. */
async function pick(label: string, option: string | RegExp) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: option }))
}

const type = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

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

  it('says a refusal under its field', async () => {
    server()
      .on('GET', '/api/admin/plans', json({ plans: [planRow({ id: 4, name: 'جایزه' })] }))
      .on('PUT', '/api/admin/bot/settings/referral', refusal(422, 'پلن جایزه را انتخاب کنید.', { referral_reward_plan_id: ['پلن جایزه را انتخاب کنید.'] }))
    render(<ReferralSection settings={botSettingsRow()} />, { wrapper: providers().wrapper })

    type('به ازای هر چند زیرمجموعه خریدار', '3')
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText('پلن جایزه را انتخاب کنید.', { selector: '#referral_reward_plan_id-error' })).toBeTruthy()
  })
})
