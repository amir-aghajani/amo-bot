import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotSettingsResponse, BotSettingsSaved, CashbackRuleResponse, CashbackRuleRow, CashbackRulesResponse, CustomerGroupsResponse, PaymentMethodsResponse } from '@/lib/api-types'
import { CashbackPage } from '@/pages/cashback'
import { providers, until } from '@/test/render'
import { botSettingsRow, groupRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «کش‌بک» (pages/cashback, components/cashback): its switch, a group of the bot settings saved alone; the rules, each
 * with its kinds, its group and its way to pay — «همه» for none —, switched in the list; a rule made with the kinds
 * ticked, a group and a way to pay picked.
 */

const RULE: CashbackRuleRow = { id: 3, percent: 10, order_types: ['purchase', 'renewal'], customer_group: { id: 1, name: 'VIP' }, payment_method: null, enabled: true }

const QR_BACKGROUND = { custom: false, mime: 'image/jpeg', width: 1024, height: 1024, size: 1, updated_at: 1, max_bytes: 5_000_000 }

function open(rules: CashbackRuleRow[] = [RULE]) {
  server().on('GET', '/api/admin/cashback/rules', json({ rules } satisfies CashbackRulesResponse))
  server().on('GET', '/api/admin/bot/settings', json({ settings: botSettingsRow(), qr_background: QR_BACKGROUND } satisfies BotSettingsResponse))
  render(<CashbackPage />, { wrapper: providers().wrapper })
}

describe('the cashback page', () => {
  it('lists the rules: their kinds, their group, any way to pay', async () => {
    open()

    const row = (await screen.findByRole('button', { name: '۱۰٪' })).closest('tr')
    expect(row?.textContent).toContain('خرید، تمدید')
    expect(row?.textContent).toContain('VIP')
    expect(row?.textContent).toContain('همه روش‌ها')
  })

  it('switches cashback on, the group alone', async () => {
    open()
    server().on('PUT', '/api/admin/bot/settings/cashback', json({ settings: botSettingsRow({ cashback_enabled: true }) } satisfies BotSettingsSaved))

    fireEvent.click(await screen.findByRole('switch', { name: 'کش‌بک فعال باشد' }))
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', '/api/admin/bot/settings/cashback')[0]?.body).toEqual({ cashback_enabled: true }))
  })

  it('switches a rule off in the list', async () => {
    open()
    server().on('PATCH', '/api/admin/cashback/rules/3', json({ rule: { ...RULE, enabled: false } } satisfies CashbackRuleResponse))

    fireEvent.click(await screen.findByRole('switch', { name: 'فعال بودن کش‌بک ۱۰٪ خرید و تمدید' }))

    await until(() => expect(server().sent('PATCH', '/api/admin/cashback/rules/3')[0]?.body).toEqual({ enabled: false }))
  })

  it('makes a rule with the kinds ticked — purchases and renewals to start with', async () => {
    open([])
    server().on('GET', '/api/admin/customer-groups', json({ groups: [groupRow({ id: 1, name: 'VIP' })] } satisfies CustomerGroupsResponse))
    server().on('GET', '/api/admin/payment-methods', json({ methods: [] } satisfies PaymentMethodsResponse))
    server().on('POST', '/api/admin/cashback/rules', json({ rule: { ...RULE, id: 4, percent: 5, order_types: ['purchase', 'extra_traffic'], customer_group: null } } satisfies CashbackRuleResponse))

    fireEvent.click((await screen.findAllByRole('button', { name: 'افزودن قانون' }))[0]!)
    const dialog = within(await screen.findByRole('dialog', { name: 'افزودن قانون کش‌بک' }))
    expect(dialog.getByRole('checkbox', { name: 'خرید' }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('checkbox', { name: 'تمدید' }).getAttribute('aria-checked')).toBe('true')
    fireEvent.change(dialog.getByLabelText('درصد کش‌بک'), { target: { value: '5' } })
    fireEvent.click(dialog.getByRole('checkbox', { name: 'حجم اضافه' }))
    fireEvent.click(dialog.getByRole('checkbox', { name: 'تمدید' }))
    fireEvent.click(dialog.getByRole('button', { name: 'افزودن قانون' }))

    await until(() => expect(server().sent('POST', '/api/admin/cashback/rules')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/cashback/rules')[0]?.body).toEqual({ percent: '5', order_types: ['purchase', 'extra_traffic'], customer_group_id: null, payment_method_id: null })
    expect(await screen.findByRole('button', { name: '۵٪' })).toBeTruthy()
  })
})
