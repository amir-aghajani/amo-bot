import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { sliceAt } from '@/apps/app/screens/club/parts/spin'
import WheelScreen from '@/apps/app/screens/club/wheel'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse, StoreWheelResponse, StoreWheelSlice, StoreWheelSpinResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «گردونه شانس» (screens/club/wheel): the shop's slices on the wheel, a spin that comes to rest on the slice the shop
 * drew — at once with reduced motion, after its glide otherwise, the phone ticking on the way — and what came of it in
 * the shop's words; the countdown before the next turn, and no spin a wallet short of its cost pays; the shop's refusal of
 * the wheel itself.
 */

const SLICES: StoreWheelSlice[] = [
  { label: '۵۰ هزار تومان', kind: 'wallet' },
  { label: 'پوچ', kind: 'nothing' },
  { label: '۱۰ گیگ حجم', kind: 'traffic' },
  { label: 'سرویس یک‌ماهه', kind: 'plan' },
]

function wheelRow(overrides: Partial<StoreWheelResponse> = {}): StoreWheelResponse {
  return { slices: SLICES, cost: '5000.00', cooldown_hours: 24, next_spin_at: null, ...overrides }
}

function spun(overrides: Partial<StoreWheelSpinResponse> = {}): StoreWheelSpinResponse {
  return {
    slices: SLICES,
    landed: 2,
    given: true,
    words: '۱۰ گیگابایت حجم به سرویس ali_1 اضافه شد.',
    failure: null,
    refunded: false,
    next_spin_at: new Date(Date.now() + 24 * 3_600_000).toISOString(),
    balance: '145000.00',
    ...overrides,
  }
}

/** The device asks for reduced motion: the wheel stops on its slice at once. */
function reduceMotion() {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query) =>
      ({
        matches: query.includes('reduce'),
        media: query,
        onchange: null,
        addListener: () => undefined,
        removeListener: () => undefined,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        dispatchEvent: () => false,
      }) as MediaQueryList,
  )
}

/** The slice the wheel's turn leaves under the pointer. */
function sliceUnderPointer(): number {
  const rotation = /rotate\((-?[\d.]+)\)/.exec(document.querySelector('svg g[transform]')?.getAttribute('transform') ?? '')?.[1]
  return sliceAt(Number(rotation ?? NaN), SLICES.length)
}

function open(wheel: StoreWheelResponse = wheelRow()) {
  const telegram = app.telegram()
  server().on('GET', `${API}/wheel`, json(wheel))
  render(app.screen(<WheelScreen />, { at: '/club/wheel' }))
  return telegram
}

describe('the lucky wheel', () => {
  it("rests on the slice the shop drew and says what it gave, the wallet's balance after it", async () => {
    reduceMotion()
    const { mainButton, haptics } = open()
    server().on('POST', `${API}/wheel/spin`, json(spun()))

    expect(await screen.findByText('هزینه هر چرخش: ۵٬۰۰۰ تومان')).toBeTruthy()
    await until(() => expect(mainButton.params).toMatchObject({ text: 'چرخاندن', is_active: true }))
    mainButton.press()

    const result = within(await screen.findByRole('dialog'))
    expect(result.getByText('تبریک! برنده شدید')).toBeTruthy()
    expect(result.getByText('۱۰ گیگابایت حجم به سرویس ali_1 اضافه شد.')).toBeTruthy()
    expect(result.getByText('موجودی کیف پول: ۱۴۵٬۰۰۰ تومان')).toBeTruthy()
    expect(sliceUnderPointer()).toBe(2)
    expect(document.querySelector('[aria-current="true"]')?.textContent).toContain('۱۰ گیگ حجم')
    expect(haptics.notificationOccurred).toHaveBeenCalledWith('success')
    // Put away under the result's sheet, then back — waiting for the next turn.
    expect(mainButton.params.is_visible).toBe(false)
    fireEvent.click(result.getByRole('button', { name: 'باشه' }))
    await until(() => expect(mainButton.params).toMatchObject({ text: 'چرخاندن', is_visible: true, is_active: false }))
  })

  it('glides to rest on that slice, ticking as slices pass', async () => {
    // The frames alone run on a fake clock: the glide is seconds long.
    vi.useFakeTimers({ toFake: ['requestAnimationFrame', 'cancelAnimationFrame'] })
    const { mainButton, haptics } = open()
    server().on('POST', `${API}/wheel/spin`, json(spun({ landed: 3, words: 'سرویس «یک‌ماهه» برای شما ساخته شد.' })))

    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()
    await until(() => expect(server().sent('POST', `${API}/wheel/spin`)).toHaveLength(1))
    await act(() => vi.advanceTimersByTimeAsync(10_000))

    expect(await screen.findByText('سرویس «یک‌ماهه» برای شما ساخته شد.')).toBeTruthy()
    expect(sliceUnderPointer()).toBe(3)
    expect(haptics.selectionChanged.mock.calls.length).toBeGreaterThan(5)
  })

  it('left mid-spin, keeps what the spin changed — the next turn, the balance — and ends the spin', async () => {
    vi.useFakeTimers({ toFake: ['requestAnimationFrame', 'cancelAnimationFrame'] })
    const { mainButton, haptics } = open()
    const answer = spun()
    const spin = server().hold('POST', `${API}/wheel/spin`)

    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()
    await until(() => expect(spin.waiting).toBe(1))
    await act(() => vi.advanceTimersByTimeAsync(300))
    spin.answer(json(answer))
    await until(() => expect(queryClient.getQueryData<StoreWheelResponse>(keys.wheel)?.next_spin_at).toBe(answer.next_spin_at))
    // Still turning: the customer goes back.
    expect(queryClient.isMutating()).toBe(1)
    cleanup()

    await until(() => expect(queryClient.isMutating()).toBe(0))
    expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.customer.balance).toBe('145000.00')
    expect(vi.getTimerCount()).toBe(0)
    expect(haptics.notificationOccurred).not.toHaveBeenCalled()
  })

  it('says a blank slice won nothing', async () => {
    reduceMotion()
    const { mainButton, haptics } = open()
    server().on('POST', `${API}/wheel/spin`, json(spun({ landed: 1, given: true, words: 'این بار پوچ بود؛ دفعه بعد شانس بیشتری دارید.' })))

    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()

    expect(within(await screen.findByRole('dialog')).getByText('این بار پوچ بود')).toBeTruthy()
    expect(sliceUnderPointer()).toBe(1)
    expect(haptics.notificationOccurred).toHaveBeenCalledWith('warning')
  })

  it('says why a prize could not be given, and that the cost went back', async () => {
    reduceMotion()
    const { mainButton } = open()
    server().on(
      'POST',
      `${API}/wheel/spin`,
      json(spun({ landed: 2, given: false, words: 'سرور سرویس شما در دسترس نبود؛ هزینه چرخش برگشت.', failure: 'سرور در دسترس نبود.', refunded: true, next_spin_at: null, balance: '150000.00' })),
    )

    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()

    const result = within(await screen.findByRole('dialog'))
    expect(result.getByText('جایزه داده نشد')).toBeTruthy()
    expect(result.getByText('هزینه چرخش به کیف پول شما برگشت.')).toBeTruthy()
  })

  it('counts down to the next turn, and takes no spin before it', async () => {
    const { mainButton } = open(wheelRow({ next_spin_at: new Date(Date.now() + 2 * 3_600_000).toISOString() }))

    expect(await screen.findByText('نوبت بعدی شما')).toBeTruthy()
    expect(screen.getByText(/^۰۱:۵۹:/)).toBeTruthy()
    await until(() => expect(mainButton.params).toMatchObject({ is_active: false }))
  })

  it('takes no spin the wallet cannot pay, and says so', async () => {
    const { mainButton } = app.telegram()
    const poor = app.account()
    server().on('GET', `${API}/wheel`, json(wheelRow()))
    render(app.screen(<WheelScreen />, { at: '/club/wheel', account: { ...poor, customer: { ...poor.customer, balance: '4000.00' } } }))

    expect(await screen.findByText('موجودی کیف پول برای چرخش کافی نیست.')).toBeTruthy()
    await until(() => expect(mainButton.params).toMatchObject({ text: 'چرخاندن', is_visible: true, is_active: false }))
    mainButton.press()
    expect(server().sent('POST', `${API}/wheel/spin`)).toHaveLength(0)
  })

  it("shows the shop's words when the wheel is not theirs to spin", async () => {
    app.telegram()
    server().on('GET', `${API}/wheel`, refusal(422, 'گردونه شانس فقط برای مشتری‌هایی است که خرید کرده‌اند.', { status: ['گردونه شانس فقط برای مشتری‌هایی است که خرید کرده‌اند.'] }))
    render(app.screen(<WheelScreen />, { at: '/club/wheel' }))

    expect(await screen.findByText('گردونه شانس فقط برای مشتری‌هایی است که خرید کرده‌اند.')).toBeTruthy()
  })
})
