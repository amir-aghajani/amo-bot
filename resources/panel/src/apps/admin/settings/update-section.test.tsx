import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { UpdateSection } from '@/apps/admin/settings/update-section'
import type { UpdateResponse, UpdateRun, UpdateStatus } from '@/lib/api-types'
import { ThemeProvider } from '@/lib/theme'
import { providers, signedIn, until } from '@/test/render'
import { json, refusal, server, type Answer } from '@/test/server'

/*
 * «تنظیمات پنل › به‌روزرسانی» (apps/admin/settings/update-section): the version the shop runs and the newest release with
 * its notes — as plain text —, an update to it begun behind a second look and driven a step a request until it is
 * installed and the page reloaded on the new build; a step refused said where the run is, with the way on; an update
 * installed taken back; and what keeps the shop from updating itself said, with the way out.
 */

const SCREEN = '/api/admin/system/update'

const RELEASE = { version: '0.2.0', published_at: '2026-10-01T09:30:00+00:00', notes: 'Faster checkouts.\n- A fix <b>here</b>', url: 'https://github.com/amir-aghajani/amo-bot/releases/tag/v0.2.0' }

/** A shop on 0.1.0 with 0.2.0 out, nothing under way. */
const AVAILABLE: UpdateStatus = { current: '0.1.0', latest: RELEASE, checked_at: '2026-10-08T06:00:00+00:00', available: true, blocker: null, run: null }

/** A run to 0.2.0 at `step`. */
function run(step: UpdateRun['step'], more: Partial<UpdateRun> = {}): UpdateRun {
  return { version: '0.2.0', from: '0.1.0', step, progress: 0, error: null, cancel: step !== 'install' && step !== 'done' && step !== 'rolled_back', rollback: false, finished_at: null, ...more }
}

/** The screen as an answer of the server's, with `update` over AVAILABLE. */
function answer(update: Partial<UpdateStatus>): Answer {
  return json({ update: { ...AVAILABLE, ...update } } satisfies UpdateResponse)
}

/** What the page says, its words across the elements they are set in. */
const shown = () => document.body.textContent ?? ''

function open(update: Partial<UpdateStatus> = {}) {
  server().on('GET', SCREEN, answer(update))
  render(<UpdateSection />, { wrapper: providers().wrapper })
}

describe('the update screen', () => {
  it('says the version, the newest release and its notes — as text, never as markup', async () => {
    open()

    await screen.findByRole('button', { name: 'بررسی دوباره' })
    expect(shown()).toContain('نسخه 0.1.0 روی این فروشگاه است.')
    expect(shown()).toContain('نسخه 0.2.0 منتشر شده است')
    const notes = screen.getByLabelText('یادداشت‌های نسخه')
    expect(notes.textContent).toBe(RELEASE.notes)
    expect(notes.querySelector('b')).toBeNull()
    expect(screen.getByRole('link', { name: 'این نسخه در GitHub' }).getAttribute('href')).toBe(RELEASE.url)
  })

  it('reads GitHub again on «بررسی دوباره»', async () => {
    open({ latest: null, available: false })
    await screen.findByText('نسخه‌ای منتشر نشده است.')
    server().on('POST', `${SCREEN}/check`, answer({}))

    fireEvent.click(screen.getByRole('button', { name: 'بررسی دوباره' }))

    await until(() => expect(shown()).toContain('نسخه 0.2.0 منتشر شده است'))
    expect(server().sent('POST', `${SCREEN}/check`)).toHaveLength(1)
  })

  it('says what keeps the shop from updating itself, with the way out, and offers no update', async () => {
    const blocker = 'افزونه zip روی PHP این هاست فعال نیست و بدون آن بسته نسخه تازه باز نمی‌شود.'
    open({ blocker })

    expect(await screen.findByText(new RegExp(blocker))).toBeTruthy()
    expect(screen.getByRole('link', { name: 'راهنمای به‌روزرسانی دستی' }).getAttribute('href')).toContain('Upgrading')
    expect(screen.queryByRole('button', { name: /به‌روزرسانی به نسخه/ })).toBeNull()
  })

  it('is not read while another section of the settings is on screen', async () => {
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: '/settings/telegram' }).wrapper },
    )

    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(server().sent('GET', SCREEN)).toHaveLength(0)
  })
})

describe('an update', () => {
  it('is begun behind a second look, driven a step a request, and the page reloaded on the new build', async () => {
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
    open()
    server().on('POST', `${SCREEN}/start`, answer({ available: false, run: run('download') }))
    const steps = [run('extract', { progress: 40 }), run('preflight'), run('install'), run('done', { progress: 100, rollback: true, finished_at: '2026-10-08T07:00:00+00:00' })]
    server().on('POST', `${SCREEN}/step`, () => answer({ available: false, run: steps.shift() ?? null }))

    fireEvent.click(await screen.findByRole('button', { name: 'به‌روزرسانی به نسخه 0.2.0' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/نسخه پشتیبان بگیرید/)).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'به‌روزرسانی' }))

    await until(() => expect(reload).toHaveBeenCalledTimes(1))
    expect(server().sent('POST', `${SCREEN}/start`)[0]?.body).toEqual({ version: '0.2.0' })
    expect(server().sent('POST', `${SCREEN}/step`)).toHaveLength(4)
    expect(shown()).toContain('از نسخه 0.1.0 به نسخه 0.2.0 به‌روز شد')
  })

  it('stopped by a refusal says why where the run is — tried again, or given up before its install', async () => {
    const words = 'GitHub جواب نداد؛ کمی بعد دوباره امتحان کنید.'
    open({ available: false, run: run('download', { error: words }) })
    server().on('POST', `${SCREEN}/step`, refusal(502, words))

    expect(await screen.findByText(words)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    await until(() => expect(server().sent('POST', `${SCREEN}/step`)).toHaveLength(1))
    await until(() => expect(server().sent('GET', SCREEN)).toHaveLength(2))
    expect(screen.getByText(words)).toBeTruthy()

    server().on('POST', `${SCREEN}/cancel`, answer({ run: null }))
    fireEvent.click(screen.getByRole('button', { name: 'لغو به‌روزرسانی' }))

    await until(() => expect(screen.getByRole('button', { name: 'به‌روزرسانی به نسخه 0.2.0' })).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'لغو به‌روزرسانی' })).toBeNull()
  })

  it('whose install began offers no giving up, only going on', async () => {
    open({ available: false, run: run('install', { cancel: false }) })

    expect(await screen.findByRole('button', { name: 'ادامه به‌روزرسانی' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'لغو به‌روزرسانی' })).toBeNull()
  })

  it('installed is taken back behind a second look, and the page reloaded on the version it put back', async () => {
    const reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
    open({ available: false, run: run('done', { progress: 100, rollback: true, finished_at: '2026-10-08T07:00:00+00:00' }) })
    server().on('POST', `${SCREEN}/rollback`, answer({ run: run('rolled_back', { progress: 100, finished_at: '2026-10-08T07:05:00+00:00' }) }))

    fireEvent.click(await screen.findByRole('button', { name: 'بازگرداندن نسخه قبلی' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'بازگرداندن' }))

    await until(() => expect(reload).toHaveBeenCalledTimes(1))
    expect(server().sent('POST', `${SCREEN}/rollback`)).toHaveLength(1)
  })

  it('installed that changed the database offers no taking back', async () => {
    open({ available: false, run: run('done', { progress: 100, rollback: false, finished_at: '2026-10-08T07:00:00+00:00' }) })

    await until(() => expect(shown()).toContain('به نسخه 0.2.0 به‌روز شد'))
    expect(screen.queryByRole('button', { name: 'بازگرداندن نسخه قبلی' })).toBeNull()
  })
})
