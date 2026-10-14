import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WalletSection } from '@/components/bot/settings/wallet-section'
import type { BotSettingsSaved } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The wallet's top-up amounts in the bot settings (components/bot/settings/wallet-section): the amounts offered are kept
 * as the server keeps them — each once, smallest first —, so the same list typed in another order or spacing is no
 * change, and another list is.
 */

const presets = (typed: string) => fireEvent.change(screen.getByLabelText('مبلغ‌های پیشنهادی (تومان)'), { target: { value: typed } })

describe('the top-up amounts', () => {
  it('typed in another order, spacing or digits, are no change; another list is', () => {
    render(<WalletSection settings={botSettingsRow({ topup_presets: [50000, 100000] })} />, { wrapper: providers().wrapper })

    presets('۱۰۰۰۰۰،50000  50,000')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')

    presets('50000, 200000')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
  })
})

describe('the random amounts and the card top-up’s random sum', () => {
  it('are fields of their own, saved with the rest of the group, each saying what it does', async () => {
    const PATH = '/api/admin/bot/settings/wallet'
    server().on('PUT', PATH, json({ settings: botSettingsRow({ topup_random_count: 3, topup_unique_max: 500 }) } satisfies BotSettingsSaved))
    render(<WalletSection settings={botSettingsRow()} />, { wrapper: providers().wrapper })

    expect(screen.getByRole('group', { name: 'دکمه‌های مبلغ تصادفی' })).toBeTruthy()
    expect(screen.getByText(/هیچ‌کدام تکراری یا برابر یک مبلغ پیشنهادی نیست/)).toBeTruthy()
    expect(screen.getByText(/۰ یعنی خاموش. روشن که باشد، به هر شارژ کیف پول با کارت به کارت مبلغی تصادفی/)).toBeTruthy()

    fireEvent.change(screen.getByLabelText('تعداد دکمه‌ها'), { target: { value: '3' } })
    fireEvent.change(screen.getByLabelText('گرد کردن (تومان)'), { target: { value: '5000' } })
    fireEvent.change(screen.getByLabelText('مبلغ تصادفی افزوده به شارژ کارت به کارت (تومان)'), { target: { value: '500' } })
    expect(screen.getByText('= ۵٬۰۰۰ تومان')).toBeTruthy()
    expect(screen.getByText(/مبلغی تصادفی بین ۱ تا ۵۰۰ تومان اضافه می‌شود/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(server().sent('PUT', PATH)).toHaveLength(1))
    expect(server().sent('PUT', PATH)[0]?.body).toEqual({
      topup_min: '10000',
      topup_presets: '50000, 100000',
      topup_random_count: '3',
      topup_random_min: '50000',
      topup_random_max: '500000',
      topup_random_round: '5000',
      topup_unique_max: '500',
    })
  })
})
