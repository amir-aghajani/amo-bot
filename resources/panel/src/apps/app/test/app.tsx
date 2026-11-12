import type { ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { act } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi, type Mock } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import { setBearer } from '@/apps/app/lib/store'
import type { BottomButton, TelegramWebApp } from '@/apps/app/telegram/webapp'
import type { StoreAccountResponse, StoreResponse } from '@/lib/api-types'

/*
 * What a mini app screen's test stands on. A test file mocks the app's address first —
 * `vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))` — then renders a screen with `screen()`, the
 * customer signed in (a token on every request) and the shop's and their account's reads already answered (`shop()`,
 * `account()`, or the test's own); every other request is the fake server's (src/test/server), held to the API
 * description. `telegram()` puts a fake Telegram on the window for a test of its buttons, its touch or its fingerprint;
 * `preview()` a panel's preview of the app.
 */

/** A bottom button that records what it was set to, and is tapped with `press()`. */
export interface FakeButton extends BottomButton {
  params: Record<string, unknown>
  press: () => void
}

/**
 * Its words and spinner as Telegram's script keeps them: words it cannot draw (none, or over 64 characters as it counts
 * them) throw, showing the spinner sets the button inactive unless asked, hiding it sets it active.
 */
function fakeButton(): FakeButton {
  const clicks = new Set<() => void>()
  const button: FakeButton = {
    isVisible: false,
    isProgressVisible: false,
    params: {},
    setParams(params) {
      const words = params.text?.trim()
      if (words !== undefined && (words === '' || words.length > 64)) throw new Error('WebAppBottomButtonParamInvalid')
      Object.assign(button.params, params)
      if (params.is_visible !== undefined) button.isVisible = params.is_visible
      return button
    },
    showProgress(leaveActive) {
      button.isProgressVisible = true
      button.params.is_active = leaveActive === true
      return button
    },
    hideProgress() {
      button.isProgressVisible = false
      button.params.is_active = true
      return button
    },
    onClick(callback) {
      clicks.add(callback)
      return button
    },
    offClick(callback) {
      clicks.delete(callback)
      return button
    },
    press: () => clicks.forEach((callback) => callback()),
  }
  return button
}

/** A fake Telegram on the window — a client of Bot API 8.0, opened from the shop's bot by customer 42 — and its parts. */
export function telegram(overrides: Partial<TelegramWebApp> = {}) {
  const back = new Set<() => void>()
  const backButton = {
    isVisible: false,
    show: () => {
      backButton.isVisible = true
    },
    hide: () => {
      backButton.isVisible = false
    },
    onClick: (callback: () => void) => void back.add(callback),
    offClick: (callback: () => void) => void back.delete(callback),
    press: () => back.forEach((callback) => callback()),
  }
  const haptics = { impactOccurred: vi.fn(), notificationOccurred: vi.fn(), selectionChanged: vi.fn() }
  const app: TelegramWebApp = {
    initData: 'query_id=AAE&user=%7B%22id%22%3A42%7D&auth_date=1790000000&hash=0000000000000000000000000000000000000000000000000000000000000000',
    initDataUnsafe: { user: { id: 42, first_name: 'علی' }, auth_date: 1790000000 },
    version: '8.0',
    platform: 'android',
    colorScheme: 'light',
    themeParams: {},
    isExpanded: true,
    isVersionAtLeast: () => true,
    ready: vi.fn(),
    expand: vi.fn(),
    close: vi.fn(),
    setHeaderColor: vi.fn(),
    setBackgroundColor: vi.fn(),
    onEvent: vi.fn(),
    offEvent: vi.fn(),
    openLink: vi.fn(),
    openTelegramLink: vi.fn(),
    showConfirm: (_message, callback) => callback?.(true),
    showAlert: (_message, callback) => callback?.(),
    BackButton: backButton,
    MainButton: fakeButton(),
    HapticFeedback: haptics,
    ...overrides,
  }
  vi.stubGlobal('Telegram', { WebApp: app })

  return { app, backButton, mainButton: app.MainButton as FakeButton, haptics }
}

/** A preview session's token, as a panel puts it in the app's address. */
export const PREVIEW_TOKEN = 'b'.repeat(64)

/**
 * A panel's preview on the window: the fake Telegram without launch data, as telegram-web-app.js makes it in the
 * panel's frame, and `tgWebAppPreview` among the parameters it read off the address.
 */
export function preview(token = PREVIEW_TOKEN, overrides: Partial<TelegramWebApp> = {}) {
  const parts = telegram({ initData: '', initDataUnsafe: {}, ...overrides })
  vi.stubGlobal('Telegram', { WebApp: parts.app, WebView: { initParams: { tgWebAppPreview: token, tgWebAppVersion: '8.0', tgWebAppPlatform: 'android' } } })

  return parts
}

/** The fake Telegram says the mini app was minimised or brought back (Bot API 8.0): to every listener still listening. */
export function tell(client: TelegramWebApp, event: 'activated' | 'deactivated'): void {
  const given = (client.onEvent as Mock).mock.calls.filter(([name]) => name === event).map(([, callback]) => callback as () => void)
  const taken = new Set((client.offEvent as Mock).mock.calls.filter(([name]) => name === event).map(([, callback]) => callback))
  act(() => given.filter((callback) => !taken.has(callback)).forEach((callback) => callback()))
}

/** The shop as `GET /` introduces it through the mini app: every program on, no colour or logo of its own. */
export function shop(overrides: Partial<StoreResponse> = {}): StoreResponse {
  return {
    shop: {
      name: 'فروشگاه نمونه',
      bot: { username: 'sample_bot', url: 'https://t.me/sample_bot' },
      taking_orders: true,
      phone_required: false,
      service_claims: true,
      service_transfers: true,
      custom_services: true,
      name_modes: ['sequential'],
      programs: { test_accounts: true, wheel: true, lottery: true, gift_codes: true, loyalty: true, cashback: true, wallet_transfers: true, reviews: true },
    },
    support: { url: 'https://t.me/sample_support' },
    sign_in: { telegram: null, google: null, email: false, phone: false, mini_app: true },
    captcha: null,
    referral: { enabled: true, rate: 10, first_only: false },
    app: { title: 'فروشگاه نمونه', accent_color: null, logo_url: null, announcement: null, fullscreen: true, time_zone: 'Asia/Tehran' },
    ...overrides,
  }
}

/** Customer 7 (Telegram 42, «علی»), 150٬000 Toman in the wallet, no loyalty tier, signed in by the mini app a moment ago. */
export function account(overrides: Partial<StoreAccountResponse> = {}): StoreAccountResponse {
  return {
    customer: {
      id: 7,
      first_name: 'علی',
      last_name: null,
      telegram: { id: 42, username: 'ali' },
      email: null,
      google: false,
      has_password: false,
      two_factor: false,
      phone: null,
      balance: '150000.00',
      created_at: '2026-09-01T10:00:00+00:00',
    },
    unread_notifications: 0,
    staff: null,
    loyalty: { tier: null, spent: '0.00', next: null },
    session: { method: 'mini_app', biometric: false, recent_until: null },
    ...overrides,
  }
}

interface ScreenOptions {
  /** The screen's address, and the route pattern it is drawn under (`/services/:id`). */
  at?: string
  route?: string
  /** `GET /`'s and `GET /me`'s answers, already read; null leaves the read to the test. */
  shop?: StoreResponse | null
  account?: StoreAccountResponse | null
}

/** A screen of the mini app as the customer signed in sees it: its providers, a router at `at`, the reads every screen shares. */
export function screen(element: ReactNode, { at = '/', route = at, shop: shopAnswer = shop(), account: accountAnswer = account() }: ScreenOptions = {}) {
  queryClient.clear()
  queryClient.setDefaultOptions({ ...queryClient.getDefaultOptions(), queries: { ...queryClient.getDefaultOptions().queries, retry: false } })
  if (shopAnswer !== null) queryClient.setQueryData(keys.shop, shopAnswer)
  if (accountAnswer !== null) queryClient.setQueryData(keys.me, accountAnswer)
  setBearer('a'.repeat(64))

  return (
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[at]}>
        <Routes>
          <Route path={route} element={element} />
          <Route path="*" element={null} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
}
