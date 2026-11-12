/*
 * Telegram's side of the mini app: the WebApp object telegram-web-app.js makes (bundled through @twa-dev/sdk by
 * main.tsx, so a phone that cannot reach telegram.org still opens the app), typed here for the parts the app uses. Each
 * feature is asked of the client's Bot API version first (isVersionAtLeast): an older client lacks it, and the script
 * throws on a method it does not have, or on words it will not take — the app goes on without it. Outside Telegram (a
 * browser) there is no launch data: `inTelegram()` is false, and a call here does nothing or takes the page's own way (a
 * new tab, the page's confirm box) — which only app.tsx's gate (its way to the bot) and the tests see: a browser is shown
 * no screen. A panel's preview has no launch data either, but its frame answers the script as Telegram does: it counts as
 * inside Telegram.
 */

type Callback = () => void

/** A button at the bottom of Telegram's screen: its main one, and the secondary one beside it (Bot API 7.10). */
export interface BottomButton {
  isVisible: boolean
  isProgressVisible: boolean
  setParams: (params: {
    text?: string
    color?: string
    text_color?: string
    is_active?: boolean
    is_visible?: boolean
    has_shine_effect?: boolean
    position?: 'left' | 'right' | 'top' | 'bottom'
  }) => BottomButton
  /** Its spinner; it also sets it active only when `leaveActive` is true. */
  showProgress: (leaveActive?: boolean) => BottomButton
  /** The spinner gone — and the button made active again, whatever it was set to (Telegram's script does that). */
  hideProgress: () => BottomButton
  onClick: (callback: Callback) => BottomButton
  offClick: (callback: Callback) => BottomButton
}

/** The device's fingerprint or face, as Telegram lets the app use it (Bot API 7.2). */
export interface BiometricManager {
  isInited: boolean
  isBiometricAvailable: boolean
  biometricType: 'finger' | 'face' | 'unknown'
  isAccessRequested: boolean
  isAccessGranted: boolean
  isBiometricTokenSaved: boolean
  init: (callback?: Callback) => BiometricManager
  requestAccess: (params: { reason?: string }, callback?: (granted: boolean) => void) => BiometricManager
  authenticate: (params: { reason?: string }, callback?: (success: boolean, token?: string) => void) => BiometricManager
  updateBiometricToken: (token: string, callback?: (updated: boolean) => void) => BiometricManager
  openSettings: () => BiometricManager
}

/** The colours of the user's Telegram theme (each #rrggbb, any of them may be missing on an older client). */
export interface ThemeParams {
  bg_color?: string
  text_color?: string
  hint_color?: string
  link_color?: string
  button_color?: string
  button_text_color?: string
  secondary_bg_color?: string
  header_bg_color?: string
  bottom_bar_bg_color?: string
  accent_text_color?: string
  section_bg_color?: string
  section_header_text_color?: string
  section_separator_color?: string
  subtitle_text_color?: string
  destructive_text_color?: string
}

/** The launch data's parts the page may read before the shop has checked them (never trusted for anything else). */
interface InitDataUnsafe {
  user?: { id: number; first_name?: string; last_name?: string; username?: string; language_code?: string; photo_url?: string }
  start_param?: string
  auth_date?: number
}

export interface TelegramWebApp {
  initData: string
  initDataUnsafe: InitDataUnsafe
  version: string
  platform: string
  colorScheme: 'light' | 'dark'
  themeParams: ThemeParams
  isExpanded: boolean
  isFullscreen?: boolean
  isVersionAtLeast: (version: string) => boolean
  ready: Callback
  expand: Callback
  close: Callback
  requestFullscreen?: Callback
  disableVerticalSwipes?: Callback
  enableClosingConfirmation?: Callback
  disableClosingConfirmation?: Callback
  setHeaderColor: (color: string) => void
  setBackgroundColor: (color: string) => void
  setBottomBarColor?: (color: string) => void
  onEvent: (event: string, callback: (...args: never[]) => void) => void
  offEvent: (event: string, callback: (...args: never[]) => void) => void
  openLink: (url: string, options?: { try_instant_view?: boolean }) => void
  openTelegramLink: (url: string) => void
  showConfirm: (message: string, callback?: (confirmed: boolean) => void) => void
  showAlert: (message: string, callback?: Callback) => void
  requestContact?: (callback?: (shared: boolean) => void) => void
  BackButton: { isVisible: boolean; show: Callback; hide: Callback; onClick: (callback: Callback) => void; offClick: (callback: Callback) => void }
  MainButton: BottomButton
  SecondaryButton?: BottomButton
  HapticFeedback: {
    impactOccurred: (style: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft') => void
    notificationOccurred: (type: 'error' | 'success' | 'warning') => void
    selectionChanged: Callback
  }
  BiometricManager?: BiometricManager
}

/** What telegram-web-app.js read off the address's fragment: every `tgWebApp…` parameter, kept across a reload. */
interface WebView {
  initParams?: Partial<Record<string, string | null>>
}

declare global {
  interface Window {
    Telegram?: { WebApp?: TelegramWebApp; WebView?: WebView }
  }
}

/**
 * The preview session's token while the page is a panel's look at the app as one of the shop's customers: the panel
 * opens it in a frame that answers as Telegram does, with `tgWebAppPreview` in its address's fragment and no launch data.
 * Null otherwise — and always beside launch data, which is Telegram's own launch. A token is a bearer token's characters
 * (RFC 6750) or none.
 */
export function previewToken(): string | null {
  const app = window.Telegram?.WebApp
  if (app === undefined || app.initData !== '') return null
  const token = window.Telegram?.WebView?.initParams?.tgWebAppPreview

  return typeof token === 'string' && /^[\w.~+/-]+=*$/.test(token) ? token : null
}

/** Whether the page is a panel's preview: it reads what the customer's own session reads, and writes nothing by itself. */
export function inPreview(): boolean {
  return previewToken() !== null
}

/**
 * Telegram's WebApp object while the page runs inside Telegram (it handed launch data) — or inside a panel's preview,
 * whose frame answers as Telegram does —; null in a browser.
 */
export function telegram(): TelegramWebApp | null {
  const app = window.Telegram?.WebApp

  return app !== undefined && (app.initData !== '' || inPreview()) ? app : null
}

/** Whether the page runs inside Telegram, opened from the shop's bot (or in a panel's preview of it). */
export function inTelegram(): boolean {
  return telegram() !== null
}

/** Whether the client has what Bot API `version` brought; false outside Telegram. */
export function supports(version: string): boolean {
  return telegram()?.isVersionAtLeast(version) ?? false
}

/** Whether the client runs on a phone (where full screen and the swipe lock matter). */
export function onPhone(): boolean {
  const platform = telegram()?.platform ?? ''

  return platform === 'ios' || platform === 'android' || platform === 'android_x'
}

/** Run `call` with Telegram's object when the client has Bot API `version`; nothing otherwise. A refusal is swallowed. */
export function when(version: string, call: (app: TelegramWebApp) => void): void {
  const app = telegram()
  if (app === null || !app.isVersionAtLeast(version)) return
  try {
    call(app)
  } catch {
    // A client that says it has a method and still throws: the app goes on without it.
  }
}

/**
 * `text` as Telegram takes it — trimmed, at most `most` characters as its script counts them (UTF-16 units) —: cut at a
 * whole character, ending in «…», when longer.
 */
export function fitText(text: string, most: number): string {
  const trimmed = text.trim()
  if (trimmed.length <= most) return trimmed
  let kept = ''
  for (const character of Array.from(trimmed)) {
    if (kept.length + character.length > most - 1) break
    kept += character
  }

  return `${kept.trimEnd()}…`
}

/** Whether `url` is a web address: all Telegram's openLink takes. */
const webAddress = (url: string) => /^https?:\/\//i.test(url)

/**
 * Open a web address: inside Telegram in its browser (the app stays open), else in a new tab. Anything but http and https
 * is refused (Telegram's script throws on it): answers whether it opened.
 */
export function openLink(url: string): boolean {
  if (!webAddress(url)) return false
  const app = telegram()
  if (app === null) {
    window.open(url, '_blank', 'noopener')
    return true
  }
  try {
    app.openLink(url)
    return true
  } catch {
    return false
  }
}

/** Open a t.me address inside Telegram (a chat, a bot, a share sheet), keeping the app open; a new tab outside it. */
export function openTelegramLink(url: string): void {
  const app = telegram()
  if (app !== null && url.startsWith('https://t.me/')) {
    app.openTelegramLink(url)
    return
  }
  openLink(url)
}

/** Telegram's share sheet for `url` with `text` beside it: the customer picks a chat to send it to. */
export function shareToChat(url: string, text: string): void {
  openTelegramLink(`https://t.me/share/url?${new URLSearchParams({ url, text }).toString()}`)
}

/**
 * Open an address the shop gave (its support contact, an announcement's link): a t.me one inside Telegram, a web one in
 * Telegram's browser, any other — a phone number (tel:), a tg:// link — handed to the device. An address that is no
 * address (no scheme), or one that runs a script (javascript:, data:), is not followed.
 */
export function openAddress(url: string): void {
  const address = url.trim()
  if (address.startsWith('https://t.me/')) openTelegramLink(address)
  else if (webAddress(address)) openLink(address)
  else if (/^[a-z][a-z\d+.-]*:/i.test(address) && !/^(javascript|data|vbscript|blob|file):/i.test(address)) window.location.href = address
}

/** The most a confirm box's words may be (Telegram's script refuses more). */
const CONFIRM_MOST = 256

/**
 * Ask Telegram's own confirm box (the page's outside Telegram, or on a client older than Bot API 6.2). Words too long
 * for it are cut; Telegram refusing to show it — one already open, from a second tap — answers no.
 */
export function confirmBox(message: string): Promise<boolean> {
  const app = telegram()
  if (app === null || !app.isVersionAtLeast('6.2')) return Promise.resolve(window.confirm(message))

  return new Promise((resolve) => {
    try {
      app.showConfirm(fitText(message, CONFIRM_MOST), (confirmed) => resolve(confirmed))
    } catch {
      resolve(false)
    }
  })
}
