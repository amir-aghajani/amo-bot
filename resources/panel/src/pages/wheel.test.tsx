import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { BotSettingsResponse, WheelPrizeRow, WheelSpinsResponse } from '@/lib/api-types'
import { WheelPage } from '@/pages/wheel'
import { providers, until } from '@/test/render'
import { botSettingsRow, wheelPrizeRow, wheelSpinRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The lucky wheel's page (pages/wheel): its slices in the order the wheel shows them — what each gives, its weight and
 * the chance it makes among the enabled ones, its switch —, a slice added from its form; the spins customers made, each
 * given or why not; its rules saved as the bot settings' `wheel` group; and, switched off, the page says so.
 */

const QR = { custom: false, mime: 'image/jpeg', width: 1024, height: 1024, size: 182_000, updated_at: 1_759_000_000, max_bytes: 5 * 1024 * 1024 }

const PRIZES: WheelPrizeRow[] = [
  wheelPrizeRow({ id: 5, label: '۱۰ هزار تومان', weight: 3 }),
  wheelPrizeRow({ id: 6, label: '۵ گیگ', kind: 'traffic', amount: '5.00', weight: 1 }),
  wheelPrizeRow({ id: 7, label: 'پوچ', kind: 'nothing', amount: null, weight: 4, enabled: false }),
]

function open(at = '/wheel', enabled = true) {
  server()
    .on('GET', '/api/admin/bot/settings', json({ settings: botSettingsRow({ wheel_enabled: enabled }), qr_background: QR } satisfies BotSettingsResponse))
    .on('GET', '/api/admin/keyboards', json({ keyboards: [], actions: [], styles: [], limits: { rows: 10, per_row: 8, label: 64 } }))
    .on('GET', '/api/admin/wheel/prizes', json({ prizes: PRIZES }))
    .on(
      'GET',
      '/api/admin/wheel/spins',
      json({
        spins: [wheelSpinRow(), wheelSpinRow({ id: 42, label: '۵ گیگ', kind: 'traffic', given: false, outcome: 'سرور در دسترس نبود.' })],
        meta: { page: 1, per_page: 25, total: 2, last_page: 1 },
      } satisfies WheelSpinsResponse),
    )
  render(<WheelPage />, { wrapper: providers({ at }).wrapper })
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })

describe('the slices', () => {
  it('list what each gives, its weight and its chance among the enabled ones', async () => {
    open()

    const [, money, traffic, nothing] = within(await screen.findByRole('table', { name: 'جایزه‌ها' })).getAllByRole('row')
    if (!money || !traffic || !nothing) throw new Error('Three slices are listed.')
    expect(within(money).getByText('۱۰٬۰۰۰ تومان شارژ کیف پول')).toBeTruthy()
    expect(within(money).getByText('۷۵٪')).toBeTruthy()
    expect(within(traffic).getByText('۲۵٪')).toBeTruthy()
    expect(within(nothing).getByText('—')).toBeTruthy()
    expect(within(nothing).getByRole('switch').getAttribute('aria-checked')).toBe('false')
  })

  it('switch one on and off in place', async () => {
    open()
    server().on('PATCH', '/api/admin/wheel/prizes/7', json({ prize: { ...PRIZES[2], enabled: true } as WheelPrizeRow }))
    await screen.findByRole('table', { name: 'جایزه‌ها' })

    fireEvent.click(screen.getByRole('switch', { name: 'فعال بودن پوچ' }))

    await until(() => expect(server().sent('PATCH', '/api/admin/wheel/prizes/7')[0]?.body).toEqual({ enabled: true }))
  })

  it('add one from its form — only what its kind reads sent', async () => {
    open()
    server().on('POST', '/api/admin/wheel/prizes', json({ prize: wheelPrizeRow({ id: 8, label: '۷ روز', kind: 'days', amount: '7.00', weight: 2 }) }, 201))
    await screen.findByRole('table', { name: 'جایزه‌ها' })

    fireEvent.click(screen.getByRole('button', { name: 'افزودن جایزه' }))
    type('متن روی گردونه', '۷ روز')
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'جایزه' }), { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: 'زمان' }))
    type('زمان جایزه (روز)', '۷')
    type('وزن', '2')
    fireEvent.submit(screen.getByLabelText('وزن').closest('form') as HTMLFormElement)

    await until(() => expect(server().sent('POST', '/api/admin/wheel/prizes')[0]?.body).toEqual({ label: '۷ روز', kind: 'days', weight: '2', enabled: true, amount: '۷' }))
  })
})

describe('the spins', () => {
  it('list each customer’s, given or why not', async () => {
    open('/wheel/spins')

    const [, given, failed] = within(await screen.findByRole('table', { name: 'چرخش‌ها' })).getAllByRole('row')
    if (!given || !failed) throw new Error('Two spins are listed.')
    expect(within(given).getByText('داده شد')).toBeTruthy()
    expect(within(failed).getByText('داده نشد')).toBeTruthy()
    expect(within(failed).getByText('سرور در دسترس نبود.')).toBeTruthy()
  })
})

describe('the rules', () => {
  it('are saved as the bot settings’ `wheel` group', async () => {
    open('/wheel/settings')
    server().on('PUT', '/api/admin/bot/settings/wheel', json({ settings: botSettingsRow({ wheel_enabled: true, wheel_cost: 5000 }) }))

    await screen.findByLabelText('هزینه هر چرخش (تومان)')
    type('هزینه هر چرخش (تومان)', '5,000')
    expect(screen.getByText('۵٬۰۰۰ تومان از کیف پول مشتری کم می‌شود.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() =>
      expect(server().sent('PUT', '/api/admin/bot/settings/wheel')[0]?.body).toEqual({ wheel_enabled: true, wheel_cooldown_hours: '24', wheel_buyers_only: false, wheel_cost: '5,000' }),
    )
  })

  it('say so while the wheel is off', async () => {
    open('/wheel', false)

    expect(await screen.findByText(/گردونه شانس خاموش است/)).toBeTruthy()
  })
})
