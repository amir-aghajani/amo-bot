import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WheelSettingsCard } from '@/components/wheel/wheel-settings-card'
import { signedIn } from '@/test/render'
import { botSettingsRow } from '@/test/rows'

/*
 * The lucky wheel's rules (components/wheel/wheel-settings-card): its numbers are compared as the server reads them, so
 * one typed back in Persian digits or with its separators is no change to save or to ask about; and while the wheel is
 * switched off they wait, held as they are kept, as every reward program's do (useHeldWhile) — one changed stays open,
 * since the save carries it.
 */

const save = () => screen.getByRole('button', { name: 'ذخیره' })
const wheel = () => screen.getByRole('switch', { name: 'گردونه شانس فعال باشد' })

describe('the wheel switched off', () => {
  it('holds its numbers as they are kept, and leaves one changed open, as the save still carries it', async () => {
    render(<WheelSettingsCard settings={botSettingsRow({ wheel_enabled: true, wheel_cost: 10_000, wheel_cooldown_hours: 24 })} />, { wrapper: signedIn().wrapper })
    const cost = await screen.findByLabelText('هزینه هر چرخش (تومان)')
    const hours = screen.getByLabelText('فاصله دو چرخش (ساعت)')

    fireEvent.change(cost, { target: { value: '12000' } })
    fireEvent.click(wheel())

    expect(hours.hasAttribute('disabled')).toBe(true)
    expect(cost.hasAttribute('disabled')).toBe(false)
    expect(screen.getByRole('switch', { name: 'فقط مشتری‌هایی که خرید کرده‌اند' }).hasAttribute('disabled')).toBe(false)
  })

  it('opens them once it is switched on', async () => {
    render(<WheelSettingsCard settings={botSettingsRow({ wheel_enabled: false })} />, { wrapper: signedIn().wrapper })
    const cost = await screen.findByLabelText('هزینه هر چرخش (تومان)')
    const hours = screen.getByLabelText('فاصله دو چرخش (ساعت)')
    expect([hours.hasAttribute('disabled'), cost.hasAttribute('disabled')]).toEqual([true, true])

    fireEvent.click(wheel())

    expect([hours.hasAttribute('disabled'), cost.hasAttribute('disabled')]).toEqual([false, false])
  })
})

describe('the wheel’s rules', () => {
  it('typed back as they are kept, in any digits and separators, are no change', async () => {
    render(<WheelSettingsCard settings={botSettingsRow({ wheel_cost: 10_000, wheel_cooldown_hours: 24 })} />, { wrapper: signedIn().wrapper })
    const cost = await screen.findByLabelText('هزینه هر چرخش (تومان)')

    fireEvent.change(cost, { target: { value: '۱۰٬۰۰۰' } })
    fireEvent.change(screen.getByLabelText('فاصله دو چرخش (ساعت)'), { target: { value: '۲۴' } })
    expect(save().getAttribute('aria-disabled')).toBe('true')

    fireEvent.change(cost, { target: { value: '12,000' } })
    expect(save().getAttribute('aria-disabled')).toBeNull()
  })
})
