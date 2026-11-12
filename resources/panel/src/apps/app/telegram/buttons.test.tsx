import { useState } from 'react'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { useBackButton } from '@/apps/app/telegram/buttons'
import * as app from '@/apps/app/test/app'
import { MainAction } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Sheet } from '@/apps/app/ui/sheet'
import { until } from '@/test/render'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * Telegram's buttons (telegram/buttons). Its back button: shown while anyone holds it, hidden once nobody does; a sheet
 * over a screen closes before the screen goes back; a screen goes back where the customer came from, or to the address it
 * names. Its main button: words longer than Telegram takes — 64 characters, where its script throws and would take the
 * screen down — cut to fit and ending in «…»; greyed out or busy, it takes no tap; and any refusal of Telegram's leaves
 * the screen standing.
 */

/** A screen holding the back button, and a sheet over it holding it too while open. */
function ScreenWithSheet({ onBack }: { onBack: () => void }) {
  const [open, setOpen] = useState(false)
  useBackButton(onBack)

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        تغییر نام
      </button>
      <Sheet open={open} onClose={() => setOpen(false)} title="نام سرویس">
        <input aria-label="نام" />
      </Sheet>
    </>
  )
}

/** Where the back button led. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

/** A screen below a tab at /services/9/renew, opened from /wallet, its back as given. */
function renewScreen(back: true | string) {
  render(
    <MemoryRouter initialEntries={['/wallet', '/services/9/renew']} initialIndex={1}>
      <Routes>
        <Route
          path="/services/9/renew"
          element={
            <Screen back={back} title="تمدید سرویس">
              <p>تمدید</p>
            </Screen>
          }
        />
        <Route path="*" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('Telegram’s back button', () => {
  it('closes a sheet over the screen first, then goes back from the screen', () => {
    const { backButton } = app.telegram()
    const back = vi.fn()
    render(<ScreenWithSheet onBack={back} />)
    expect(backButton.isVisible).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: 'تغییر نام' }))
    expect(screen.getByRole('dialog', { name: 'نام سرویس' })).toBeTruthy()
    act(() => backButton.press())
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(back).not.toHaveBeenCalled()
    expect(backButton.isVisible).toBe(true)

    act(() => backButton.press())
    expect(back).toHaveBeenCalledOnce()
  })

  it('is hidden once nobody holds it: let go, or the screen gone', () => {
    const { backButton } = app.telegram()
    function Holder({ holds }: { holds: boolean }) {
      useBackButton(holds ? () => {} : null)
      return null
    }
    const { rerender, unmount } = render(<Holder holds />)
    expect(backButton.isVisible).toBe(true)

    rerender(<Holder holds={false} />)
    expect(backButton.isVisible).toBe(false)

    rerender(<Holder holds />)
    expect(backButton.isVisible).toBe(true)
    unmount()
    expect(backButton.isVisible).toBe(false)
  })

  it('goes back where the customer came from', () => {
    const { backButton } = app.telegram()
    renewScreen(true)

    act(() => backButton.press())
    expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/wallet')
  })

  it('goes to the address a screen names', () => {
    const { backButton } = app.telegram()
    renewScreen('/services/9')

    act(() => backButton.press())
    expect(screen.getByRole('status', { name: 'آدرس' }).textContent).toBe('/services/9')
  })
})

describe('Telegram’s main button', () => {
  it('cuts words longer than Telegram takes', async () => {
    const { mainButton } = app.telegram()
    const long = `انتقال سرویس «${'سرویس خانه '.repeat(8)}» به حساب علی`
    render(<MainAction text={long} onClick={() => {}} />)

    await until(() => expect(mainButton.isVisible).toBe(true))
    const words = String(mainButton.params.text)
    expect(words.length).toBeLessThanOrEqual(64)
    expect(words.endsWith('…')).toBe(true)
    expect(long.startsWith(words.slice(0, -1))).toBe(true)
    // Telegram's own button throws on the whole.
    expect(() => mainButton.setParams({ text: long })).toThrow('WebAppBottomButtonParamInvalid')
  })

  it('takes no tap while greyed out or busy', async () => {
    const { mainButton } = app.telegram()
    const pay = vi.fn()
    const { rerender } = render(<MainAction text="پرداخت" enabled={false} onClick={pay} />)
    await until(() => expect(mainButton.params).toMatchObject({ is_visible: true, is_active: false }))
    mainButton.press()

    rerender(<MainAction text="پرداخت" busy onClick={pay} />)
    await until(() => expect(mainButton.isProgressVisible).toBe(true))
    mainButton.press()
    expect(pay).not.toHaveBeenCalled()

    rerender(<MainAction text="پرداخت" onClick={pay} />)
    await until(() => expect(mainButton.params).toMatchObject({ is_active: true }))
    mainButton.press()
    expect(pay).toHaveBeenCalledOnce()
  })

  it('leaves the screen standing when Telegram refuses the button', async () => {
    const { mainButton } = app.telegram()
    const refuse = () => {
      throw new Error('WebAppBottomButtonParamInvalid')
    }
    for (const method of ['setParams', 'showProgress', 'hideProgress', 'onClick', 'offClick'] as const) vi.spyOn(mainButton, method).mockImplementation(refuse)

    const { rerender, unmount } = render(
      <>
        <p>فاکتور</p>
        <MainAction text="پرداخت" busy onClick={() => {}} />
      </>,
    )
    rerender(
      <>
        <p>فاکتور</p>
        <MainAction text="پرداخت" onClick={() => {}} />
      </>,
    )

    await until(() => expect(mainButton.setParams).toHaveBeenCalled())
    expect(screen.getByText('فاکتور')).toBeTruthy()
    expect(() => unmount()).not.toThrow()
  })
})
