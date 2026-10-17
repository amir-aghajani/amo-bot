import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CashbackSettingsCard } from '@/components/cashback/cashback-settings-card'
import { providers } from '@/test/render'
import { botSettingsRow } from '@/test/rows'

/* The cashback switch's card (components/cashback/cashback-settings-card): what the switch does, under it. */

describe('the cashback switch', () => {
  it('says the most an order gets back with the bound in Latin digits, as a form’s bounds are written', () => {
    render(<CashbackSettingsCard settings={botSettingsRow()} />, { wrapper: providers().wrapper })

    expect(screen.getByText(/به اضافه کش‌بک سطح وفاداری مشتری؛ حداکثر 100 درصد\./)).toBeTruthy()
  })
})
