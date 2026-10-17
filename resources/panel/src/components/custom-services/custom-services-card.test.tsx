import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CustomServicesCard } from '@/components/custom-services/custom-services-card'
import type { ServersResponse } from '@/lib/api-types'
import { signedIn } from '@/test/render'
import { botSettingsRow, serverRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * «سرویس دلخواه»'s settings card (components/custom-services/custom-services-card): under the amounts typed as buttons,
 * the buttons the bot shows — and those it leaves out, outside what a customer may build, the least never below the
 * first tier —, as the server offers them; and, switched off, its numbers held as they are kept (useHeldWhile).
 */

/** The card of the PHP tests' builder: a GB from 5 at 3٬000, 1–500 GB; a day 2٬000, 1–90 days — switched on unless `enabled` says otherwise. */
function show(enabled = true) {
  server().on('GET', '/api/admin/servers', json({ servers: [serverRow()] } satisfies ServersResponse))
  const settings = botSettingsRow({
    custom_enabled: enabled,
    custom_traffic_tiers: [{ from: 5, price: '3000.00' }],
    custom_traffic_min: 1,
    custom_traffic_max: 500,
    custom_days_tiers: [{ from: 1, price: '2000.00' }],
    custom_servers: [1],
  })
  render(<CustomServicesCard settings={settings} />, { wrapper: signedIn().wrapper })
}

describe('the GB offered as buttons', () => {
  it('say which the bot shows, and which it leaves out and why', async () => {
    show()

    fireEvent.change(await screen.findByLabelText('دکمه‌های پیشنهادی (گیگابایت)'), { target: { value: '2, 50, 10, 1000' } })

    expect(
      screen.getByText(
        'دکمه‌ها: ۱۰ گیگابایت، ۵۰ گیگابایت؛ ۲ گیگابایت، ۱٬۰۰۰ گیگابایت نشان داده نمی‌شود، چون مشتری بین 5 تا 500 گیگابایت می‌سازد — مشتری عدد دیگری بین کمترین و بیشترین را هم می‌نویسد.',
      ),
    ).toBeTruthy()
  })

  it('while none are typed, say how many may be and within what, in Latin digits as the owner types them', async () => {
    show()

    fireEvent.change(await screen.findByLabelText('دکمه‌های پیشنهادی (گیگابایت)'), { target: { value: '' } })

    expect(screen.getByText('عددها را با کاما جدا کنید (حداکثر 8 دکمه، بین 1 تا 10000)؛ خالی یعنی مشتری عدد را خودش می‌نویسد.')).toBeTruthy()
  })
})

/** The text field labelled `label`. */
const field = (label: string) => screen.getByLabelText<HTMLInputElement>(label)

describe('the builder switched off', () => {
  it('holds its numbers as they are kept — the tiers whole —, and leaves one changed open, as the save still carries it', async () => {
    show()
    fireEvent.change(await screen.findByLabelText('بیشترین (روز)'), { target: { value: '60' } })

    fireEvent.click(screen.getByRole('switch', { name: 'سرویس دلخواه فعال باشد' }))

    expect(field('پله ۱: از (گیگابایت)').disabled).toBe(true)
    expect(screen.getAllByRole<HTMLButtonElement>('button', { name: 'حذف پله ۱' }).map((remove) => remove.disabled)).toEqual([true, true])
    expect(field('بیشترین (گیگابایت)').disabled).toBe(true)
    expect(field('دکمه‌های پیشنهادی (گیگابایت)').disabled).toBe(true)
    expect(field('تعداد کاربر هم‌زمان').disabled).toBe(true)
    expect(field('بیشترین (روز)').disabled).toBe(false)
    expect(field('حجم (گیگابایت)').disabled).toBe(false)
  })

  it('opens them once it is switched on', async () => {
    show(false)
    expect((await screen.findByLabelText<HTMLInputElement>('بیشترین (گیگابایت)')).disabled).toBe(true)

    fireEvent.click(screen.getByRole('switch', { name: 'سرویس دلخواه فعال باشد' }))

    expect(field('بیشترین (گیگابایت)').disabled).toBe(false)
    expect(field('پله ۱: از (گیگابایت)').disabled).toBe(false)
  })
})
