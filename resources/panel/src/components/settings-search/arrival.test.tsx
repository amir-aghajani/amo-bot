import { useEffect, useState } from 'react'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { Disclosure } from '@/components/disclosure'
import { Field } from '@/components/field'
import { arrivalState, ARRIVED, useSettingArrival } from '@/components/settings-search/arrival'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import type { SettingEntry } from '@/lib/settings-search'
import { until } from '@/test/render'

/*
 * Arriving at a setting the search opened (components/settings-search/arrival): the fold it sits in opened, its control
 * focused, its field marked for a moment — found by its id, or by its label's words where it has none, and waited for
 * while its page draws it —, and the router's state that named it dropped, so the history entry opens the section alone;
 * one that is not drawn after all (shown only with another choice) said so, its section's heading focused.
 */

const IN_FOLD: SettingEntry = {
  id: 'setting:/bot-settings/renewal#renewal_window',
  kind: 'setting',
  title: 'پنجره تمدید (روز)',
  path: '/bot-settings/renewal',
  trail: ['تنظیمات ربات', 'تمدید سرویس'],
  anchor: 'renewal_window',
  fold: 'تنظیمات پیشرفته',
}

const SWITCH: SettingEntry = {
  id: 'setting:/bot-settings/renewal#auto',
  kind: 'setting',
  title: 'تمدید خودکار',
  path: '/bot-settings/renewal',
  trail: ['تنظیمات ربات', 'تمدید سرویس'],
  anchorText: 'تمدید خودکار',
}

/** A setting the renewal section draws only with another choice of its own: not on screen now. */
const NOT_SHOWN: SettingEntry = {
  id: 'setting:/bot-settings/renewal#agents',
  kind: 'setting',
  title: 'تمدید برای نماینده‌ها',
  path: '/bot-settings/renewal',
  trail: ['تنظیمات ربات', 'تمدید سرویس'],
  anchorText: 'تمدید برای نماینده‌ها',
}

/** The renewal section: a field in a fold, a switch with no id — drawn once its read is in (`late`). */
function RenewalPage({ late = false }: { late?: boolean }) {
  const [drawn, setDrawn] = useState(!late)
  useEffect(() => {
    if (drawn) return
    const timer = window.setTimeout(() => setDrawn(true), 50)
    return () => window.clearTimeout(timer)
  }, [drawn])

  return drawn ? (
    <section aria-label="تمدید سرویس" className="grid gap-4">
      <h2>تمدید سرویس</h2>
      <SwitchRow label="تمدید خودکار" checked={false} onCheckedChange={() => undefined} />
      <Disclosure label="تنظیمات پیشرفته">
        <Field id="renewal_window" label="پنجره تمدید (روز)">
          <Input />
        </Field>
      </Disclosure>
    </section>
  ) : (
    <p>در حال بارگذاری</p>
  )
}

/** Where the router is, and what its state holds. */
function Where() {
  const location = useLocation()
  return <output aria-label="وضعیت">{JSON.stringify(location.state)}</output>
}

function Shell({ entry }: { entry: SettingEntry }) {
  useSettingArrival()
  const navigate = useNavigate()

  return (
    <>
      <button type="button" onClick={() => void navigate(entry.path, { state: arrivalState(entry) })}>
        باز کردن {entry.title}
      </button>
      <main id="main">
        <Routes>
          <Route path="/" element={<p>داشبورد</p>} />
          <Route path="/bot-settings/renewal" element={<RenewalPage late={entry === SWITCH} />} />
        </Routes>
      </main>
      <Where />
    </>
  )
}

function arriveAt(entry: SettingEntry) {
  render(
    <MemoryRouter initialEntries={['/']}>
      <Shell entry={entry} />
    </MemoryRouter>,
  )
  fireEvent.click(screen.getByRole('button', { name: `باز کردن ${entry.title}` }))
}

describe('arriving at a setting', () => {
  it('opens the fold it sits in, focuses its control and marks its field — the router’s state dropped', async () => {
    arriveAt(IN_FOLD)

    const control = await screen.findByLabelText('پنجره تمدید (روز)')
    await until(() => expect(document.activeElement).toBe(control))
    expect(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }).getAttribute('aria-expanded')).toBe('true')
    expect(control.closest('[hidden]')).toBeNull()
    expect(control.closest('[data-field]')?.hasAttribute(ARRIVED)).toBe(true)
    expect(screen.getByLabelText('وضعیت').textContent).toBe('null')
  })

  it('finds a setting with no id by its label’s words, waiting while its page draws it', async () => {
    arriveAt(SWITCH)
    expect(screen.getByText('در حال بارگذاری')).toBeTruthy()

    const toggle = await screen.findByRole('switch', { name: /تمدید خودکار/ })
    await until(() => expect(document.activeElement).toBe(toggle))
    expect(toggle.closest('[data-field]')?.hasAttribute(ARRIVED)).toBe(true)
  })

  it('says so when the setting is not drawn after waiting — shown only with another choice —, its section’s heading focused', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    vi.spyOn(toast, 'info')
    arriveAt(NOT_SHOWN)

    await act(() => vi.advanceTimersByTimeAsync(4_999))
    expect(toast.info).not.toHaveBeenCalled()
    await act(() => vi.advanceTimersByTimeAsync(1))

    expect(toast.info).toHaveBeenCalledWith('این تنظیم الان نمایش داده نمی‌شود', { description: 'شاید فقط با انتخاب دیگری در همین بخش نمایش داده شود.' })
    expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'تمدید سرویس' }))
  })

  it('leaves the focus where the admin took it meanwhile, saying so all the same', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    vi.spyOn(toast, 'info')
    arriveAt(NOT_SHOWN)
    await act(() => vi.advanceTimersByTimeAsync(1))

    const toggle = screen.getByRole('switch', { name: /تمدید خودکار/ })
    toggle.focus()
    await act(() => vi.advanceTimersByTimeAsync(5_000))

    expect(toast.info).toHaveBeenCalledOnce()
    expect(document.activeElement).toBe(toggle)
  })

  it('opens a page or a section plainly: nothing to arrive at', () => {
    expect(arrivalState({ id: 'section:/bot-settings/renewal', kind: 'section', title: 'تمدید سرویس', path: '/bot-settings/renewal', trail: ['تنظیمات ربات'] })).toBeUndefined()
    expect(arrivalState(IN_FOLD)).toEqual({ settingTarget: { anchor: 'renewal_window', anchorText: undefined, fold: 'تنظیمات پیشرفته' } })
  })
})
