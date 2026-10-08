import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WalletSection } from '@/components/bot/settings/wallet-section'
import { providers } from '@/test/render'
import { botSettingsRow } from '@/test/rows'

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
