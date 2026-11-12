import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ADMIN_NAV } from '@/apps/admin/nav'
import { Field } from '@/components/field'
import { AppShell } from '@/components/shell/app-shell'
import { Input } from '@/components/ui/input'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import type { PanelFavoritesRequest, Session } from '@/lib/api-types'
import { AuthProvider, RequireAuth } from '@/lib/auth'
import type { SearchOptions, SettingEntry, SettingMatch } from '@/lib/settings-search'
import { ThemeProvider } from '@/lib/theme'
import { OWNER, testQueryClient, until } from '@/test/render'
import { json, refusal, server, type SentRequest } from '@/test/server'

/*
 * The settings search's dialog (components/settings-search), over a small index of its own (the engine, lib/settings-
 * search, has tests of its own): its shortcuts — the Persian layout's keys too —, the matches with what matched marked,
 * the arrows and Enter, «اخیر», Escape back to its trigger, and the favorites — starred at once and saved whole, one
 * write after another, put back when refused, ordered, and ids this panel does not have kept.
 */

const engine = vi.hoisted(() => {
  const entries: SettingEntry[] = [
    { id: 'page:/plans', kind: 'page', title: 'پلن‌ها', path: '/plans', trail: ['فروشگاه'] },
    { id: 'section:/bot-settings/wallet', kind: 'section', title: 'کیف پول', path: '/bot-settings/wallet', trail: ['تنظیمات ربات'] },
    {
      id: 'setting:/bot-settings/wallet#wallet_topup_min',
      kind: 'setting',
      title: 'حداقل مبلغ شارژ (تومان)',
      path: '/bot-settings/wallet',
      trail: ['تنظیمات ربات', 'کیف پول'],
      anchor: 'wallet_topup_min',
    },
    {
      id: 'setting:/bot-settings/wallet#wallet_topup_presets',
      kind: 'setting',
      title: 'مبلغ‌های پیشنهادی (تومان)',
      path: '/bot-settings/wallet',
      trail: ['تنظیمات ربات', 'کیف پول'],
      anchor: 'wallet_topup_presets',
    },
  ]

  /** A plain substring search, in the index's order, its title's match marked. */
  function search(index: readonly SettingEntry[], query: string, options: SearchOptions = {}): SettingMatch[] {
    const wanted = query.trim()
    const found = index.flatMap((entry): SettingMatch[] => {
      const at = entry.title.indexOf(wanted)
      return wanted !== '' && at !== -1 ? [{ entry, score: 1, marks: { title: [[at, at + wanted.length]], trail: [] } }] : []
    })
    return found.slice(0, options.limit)
  }

  return { entries, search }
})

vi.mock('@/lib/settings-search', () => ({ buildIndex: () => engine.entries, searchSettings: engine.search }))

const MIN = 'setting:/bot-settings/wallet#wallet_topup_min'
const PRESETS = 'setting:/bot-settings/wallet#wallet_topup_presets'
const WALLET = 'section:/bot-settings/wallet'
const PLANS = 'page:/plans'

/** Where the router is. */
function Where() {
  return <output aria-label="آدرس">{useLocation().pathname}</output>
}

/** The wallet's settings, as the bot settings page draws them. */
function WalletPage() {
  return (
    <section aria-label="کیف پول" className="grid gap-4">
      <Field id="wallet_topup_min" label="حداقل مبلغ شارژ (تومان)">
        <Input />
      </Field>
      <Field id="wallet_topup_presets" label="مبلغ‌های پیشنهادی (تومان)">
        <Input />
      </Field>
    </section>
  )
}

function showPanel(session: Session = OWNER) {
  server().on('GET', '/api/admin/auth/me', json({ session }))
  render(
    <QueryClientProvider client={testQueryClient()}>
      <ThemeProvider>
        <TooltipProvider>
          <MemoryRouter initialEntries={['/plans']}>
            <AuthProvider>
              <Routes>
                <Route element={<RequireAuth />}>
                  <Route element={<AppShell panel={{ nav: ADMIN_NAV, role: 'مالک فروشگاه' }} />}>
                    <Route
                      path="plans"
                      element={
                        <>
                          <p>صفحه پلن‌ها</p>
                          <input aria-label="جستجوی پلن‌ها" />
                        </>
                      }
                    />
                    <Route path="bot-settings/:section?" element={<WalletPage />} />
                  </Route>
                </Route>
              </Routes>
              <Where />
            </AuthProvider>
          </MemoryRouter>
        </TooltipProvider>
        <Toaster />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

/** The panel, on the plans page. */
async function openPanel(session?: Session) {
  showPanel(session)
  await screen.findByText('صفحه پلن‌ها')
}

const SEARCH = 'جستجوی تنظیمات'

/** Ctrl+K, as a Persian keyboard sends it: the key at K's place, «ن». */
const pressCtrlK = (target: Element = document.body) => fireEvent.keyDown(target, { key: 'ن', code: 'KeyK', ctrlKey: true })

async function openSearch(): Promise<HTMLDialogElement> {
  pressCtrlK()
  return (await screen.findByRole('dialog', { name: SEARCH })) as HTMLDialogElement
}

const field = () => screen.getByRole('combobox', { name: SEARCH })
const options = () => within(screen.getByRole('listbox')).queryAllByRole('option')
const activeOption = () => document.getElementById(field().getAttribute('aria-activedescendant') ?? '')
const type = (text: string) => fireEvent.change(field(), { target: { value: text } })
const key = (name: string, extra: Partial<KeyboardEventInit> = {}) => fireEvent.keyDown(field(), { key: name, ...extra })
/** Ctrl+D on the active row, as a Persian keyboard sends it: «ی». */
const pressCtrlD = () => fireEvent.keyDown(field(), { key: 'ی', code: 'KeyD', ctrlKey: true })
/** A row's star, beside its option — for the pointer, out of the reading (so by its label, not its role): Ctrl+D does the same. */
const starOf = (option: HTMLElement) => within(option.parentElement as HTMLElement).getByLabelText('علاقه‌مندی', { selector: 'button' })
/** A favorite's move up, beside its option — for the pointer, out of the reading: Alt+↑ does the same. */
const moveUpOf = (option: HTMLElement) => within(option.parentElement as HTMLElement).getByLabelText('بالا بردن', { selector: 'button' })
const optionNamed = (name: RegExp) => options().find((option) => name.test(option.textContent ?? '')) as HTMLElement

/** The favorites the server keeps: what each write saved. */
function keepFavorites(initial: string[]): { sent: () => string[][] } {
  let stored = initial
  server()
    .on('GET', '/api/admin/preferences', () => json({ preferences: { favorites: stored } }))
    .on('PUT', '/api/admin/preferences/favorites', (request: SentRequest) => {
      stored = (request.body as PanelFavoritesRequest).favorites
      return json({ preferences: { favorites: stored } })
    })
  return {
    sent: () =>
      server()
        .sent('PUT', '/api/admin/preferences/favorites')
        .map((request) => (request.body as PanelFavoritesRequest).favorites),
  }
}

beforeEach(() => {
  server()
    .on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true }))
    .on('GET', '/api/admin/changes', json({ versions: {} }))
    .on('GET', '/api/admin/queues', json({ queues: { payments_to_review: 0, stuck_orders: 0, open_tickets: 0 } }))
    .on('GET', '/api/admin/preferences', json({ preferences: { favorites: [] } }))
})

describe('the settings search', () => {
  it('opens with Ctrl+K — by the key’s place, so a Persian keyboard’s «ن» too —, on its field, and closes with it again', async () => {
    await openPanel()

    const dialog = await openSearch()

    expect(dialog.open).toBe(true)
    expect(document.activeElement).toBe(field())
    pressCtrlK(field())
    expect(dialog.open).toBe(false)
  })

  it('opens with «/» outside a field — never while typing in one', async () => {
    await openPanel()
    const typing = screen.getByLabelText('جستجوی پلن‌ها')
    typing.focus()

    fireEvent.keyDown(typing, { key: '/', code: 'Slash' })
    expect(screen.queryByRole('dialog', { name: SEARCH })).toBeNull()

    fireEvent.keyDown(document.body, { key: '/', code: 'Slash' })
    expect(await screen.findByRole('dialog', { name: SEARCH })).toBeTruthy()
  })

  it('opens from the topbar, and closes with Escape — the focus back on the trigger', async () => {
    await openPanel()
    const trigger = screen.getByRole('button', { name: SEARCH })
    expect(trigger.getAttribute('aria-keyshortcuts')).toBe('Control+K /')

    trigger.focus()
    fireEvent.click(trigger)
    const dialog = (await screen.findByRole('dialog', { name: SEARCH })) as HTMLDialogElement
    fireEvent(dialog, new Event('cancel', { cancelable: true }))

    expect(dialog.open).toBe(false)
    expect(document.activeElement).toBe(trigger)
  })

  it('is a map of the settings while nothing is typed: a group per page of them, with its sections', async () => {
    await openPanel()
    await openSearch()

    const bot = screen.getByRole('group', { name: 'تنظیمات ربات' })
    expect(within(bot).getByRole('option', { name: 'کیف پول' })).toBeTruthy()
    expect(within(screen.getByRole('group', { name: 'تنظیمات پنل' })).getByRole('option', { name: 'زمان‌بندی کارها' })).toBeTruthy()
    expect(screen.queryByRole('group', { name: 'اخیر' })).toBeNull()
  })

  it('shows what is found, what matched marked and where each sits — and moves along it with the arrows, round its ends', async () => {
    await openPanel()
    await openSearch()

    type('مبلغ')

    expect(options().map((option) => option.querySelector('mark')?.textContent)).toEqual(['مبلغ', 'مبلغ'])
    expect(options()[0]?.textContent).toContain('تنظیمات ربات')
    expect(options()[0]?.textContent).toContain('کیف پول')
    expect(activeOption()).toBe(options()[0])
    key('ArrowDown')
    expect(activeOption()).toBe(options()[1])
    key('ArrowDown')
    expect(activeOption()).toBe(options()[0])
    key('ArrowUp')
    expect(activeOption()).toBe(options()[1])
    key('Home', { ctrlKey: true })
    expect(activeOption()).toBe(options()[0])
    key('End', { ctrlKey: true })
    expect(activeOption()).toBe(options()[1])
    expect(within(screen.getByRole('dialog', { name: SEARCH })).getByRole('status').textContent).toBe('۲ نتیجه')
  })

  it('leaves Home and End alone to the field — its caret —, the list’s ends with Ctrl', async () => {
    await openPanel()
    await openSearch()
    type('مبلغ')
    key('ArrowDown')

    expect(key('Home')).toBe(true)
    expect(key('End')).toBe(true)
    expect(activeOption()).toBe(options()[1])
    expect(key('Home', { ctrlKey: true })).toBe(false)
    expect(activeOption()).toBe(options()[0])
  })

  it('says so when nothing is found', async () => {
    await openPanel()
    await openSearch()

    type('ناموجود')

    expect(options()).toHaveLength(0)
    expect(screen.getByText('نتیجه‌ای پیدا نشد', { selector: 'p' })).toBeTruthy()
  })

  it('opens the chosen setting with Enter — its section, the focus on its field, the field marked — and keeps it in «اخیر»', async () => {
    await openPanel()
    const dialog = await openSearch()

    type('مبلغ')
    key('ArrowDown')
    key('Enter')

    expect(dialog.open).toBe(false)
    await until(() => expect(screen.getByLabelText('آدرس').textContent).toBe('/bot-settings/wallet'))
    const presets = await screen.findByLabelText('مبلغ‌های پیشنهادی (تومان)')
    await until(() => expect(document.activeElement).toBe(presets))
    expect(presets.closest('[data-field]')?.hasAttribute('data-arrived')).toBe(true)

    await openSearch()
    expect(within(screen.getByRole('group', { name: 'اخیر' })).getByRole('option', { name: /مبلغ‌های پیشنهادی/ })).toBeTruthy()
    expect(JSON.parse(localStorage.getItem('amobot-panel-settings-recent-admin') ?? '[]')).toEqual([PRESETS])
  })

  it('opens a page with a click, the search closed', async () => {
    await openPanel()
    const dialog = await openSearch()
    type('کیف')

    fireEvent.click(optionNamed(/کیف پول/))

    expect(dialog.open).toBe(false)
    await until(() => expect(screen.getByLabelText('آدرس').textContent).toBe('/bot-settings/wallet'))
  })

  it('stars the active row with Ctrl+D at once and saves the whole list — the star put back, and said, when the server refuses', async () => {
    server().on('GET', '/api/admin/preferences', json({ preferences: { favorites: [PLANS] } }))
    const save = server().hold('PUT', '/api/admin/preferences/favorites')
    await openPanel()
    await openSearch()
    type('حداقل')
    const option = optionNamed(/حداقل مبلغ شارژ/)
    await until(() => expect((starOf(option) as HTMLButtonElement).disabled).toBe(false))

    pressCtrlD()

    expect(starOf(option).getAttribute('aria-pressed')).toBe('true')
    expect(starOf(option).getAttribute('title')).toBe('حذف از علاقه‌مندی‌ها')
    expect(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }).textContent).toContain('۲')
    await until(() => expect(save.waiting).toBe(1))
    expect(server().sent('PUT', '/api/admin/preferences/favorites')[0]?.body).toEqual({ favorites: [PLANS, MIN] })

    save.answer(refusal(500, 'Server error'))

    expect(await screen.findByText('علاقه‌مندی‌ها ذخیره نشد')).toBeTruthy()
    expect(starOf(option).getAttribute('aria-pressed')).toBe('false')
    expect(starOf(option).getAttribute('title')).toBe('افزودن به علاقه‌مندی‌ها')
  })

  it('saves quick stars one after another, each with the list as it stands, so the last one wins', async () => {
    const kept = keepFavorites([])
    const save = server().hold('PUT', '/api/admin/preferences/favorites')
    await openPanel()
    await openSearch()
    type('مبلغ')
    await until(() => expect((starOf(options()[0] as HTMLElement) as HTMLButtonElement).disabled).toBe(false))

    pressCtrlD()
    key('ArrowDown')
    pressCtrlD()

    await until(() => expect(save.waiting).toBe(1))
    save.answer(json({ preferences: { favorites: [MIN] } }))
    await until(() => expect(save.waiting).toBe(1))
    save.answer(json({ preferences: { favorites: [MIN, PRESETS] } }))

    expect(kept.sent()).toEqual([[MIN], [MIN, PRESETS]])
    await until(() => expect(options().map((option) => starOf(option).getAttribute('aria-pressed'))).toEqual(['true', 'true']))
  })

  it('lists the favorites in their order, moves them and takes their star off — keeping the ones this panel does not have', async () => {
    const kept = keepFavorites(['setting:/gone#old', WALLET, PLANS, MIN])
    await openPanel()
    await openSearch()

    fireEvent.click(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }))

    await until(() => expect(options().map((option) => option.textContent)).toEqual([expect.stringContaining('کیف پول'), expect.stringContaining('پلن‌ها'), expect.stringContaining('حداقل')]))
    expect(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }).textContent).toContain('۳')

    key('ArrowDown', { altKey: true })
    await until(() => expect(kept.sent()).toHaveLength(1))
    expect(kept.sent()[0]).toEqual(['setting:/gone#old', PLANS, WALLET, MIN])
    expect(activeOption()?.textContent).toContain('کیف پول')

    fireEvent.click(moveUpOf(optionNamed(/حداقل/)))
    await until(() => expect(kept.sent()).toHaveLength(2))
    expect(kept.sent()[1]).toEqual(['setting:/gone#old', PLANS, MIN, WALLET])

    fireEvent.click(starOf(optionNamed(/پلن‌ها/)))
    await until(() => expect(kept.sent()).toHaveLength(3))
    expect(kept.sent()[2]).toEqual(['setting:/gone#old', MIN, WALLET])
    await until(() => expect(options()).toHaveLength(2))
  })

  it('keeps its listbox to options in groups — the stars and the moves out of the reading and the Tab order —, a starred row said so', async () => {
    keepFavorites([WALLET, PLANS])
    await openPanel()
    await openSearch()
    fireEvent.click(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }))
    await until(() => expect(options()).toHaveLength(2))

    const listbox = screen.getByRole('listbox')
    expect(within(listbox).queryAllByRole('button')).toHaveLength(0)
    const buttons = within(listbox).getAllByRole('button', { hidden: true })
    // Two stars, and a move each way of the two favorites (the first's up and the last's down there, unseen).
    expect(buttons).toHaveLength(6)
    for (const button of buttons) {
      expect(button.getAttribute('aria-hidden')).toBe('true')
      expect(button.tabIndex).toBe(-1)
    }
    const described = document.getElementById(options()[0]?.getAttribute('aria-describedby') ?? '')
    expect(described?.textContent?.trim()).toBe('در علاقه‌مندی‌ها')
    expect(screen.getByRole('combobox', { name: SEARCH, description: /Alt با جهت‌نماهای بالا و پایین، ردیف را یک جا جابه‌جا می‌کند/ })).toBeTruthy()

    fireEvent.click(screen.getByRole('tab', { name: /^همه/ }))
    type('حداقل')
    expect(optionNamed(/حداقل مبلغ شارژ/).hasAttribute('aria-describedby')).toBe(false)
  })

  it('narrows the favorites by what is typed, in their order', async () => {
    keepFavorites([PRESETS, PLANS, MIN])
    await openPanel()
    await openSearch()
    fireEvent.click(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }))
    await until(() => expect(options()).toHaveLength(3))

    type('مبلغ')

    expect(options().map((option) => option.querySelector('mark')?.textContent)).toEqual(['مبلغ', 'مبلغ'])
    expect(options()[0]?.textContent).toContain('پیشنهادی')
    expect(screen.queryByLabelText('بالا بردن', { selector: 'button' })).toBeNull()
  })

  it('says how to star one while there are no favorites', async () => {
    await openPanel()
    await openSearch()

    fireEvent.click(screen.getByRole('tab', { name: /علاقه‌مندی‌ها/ }))

    expect(await screen.findByText('هنوز علاقه‌مندی ندارید')).toBeTruthy()
  })
})
