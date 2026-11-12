import { useEffect, useLayoutEffect, useRef, useState, type RefObject } from 'react'
import { toast } from 'sonner'
import { CONTROLS, STATUS_BAR, type ThemeParams } from '@/components/mini-app/preview/telegram'
import { readStored, STORAGE_KEYS, writeStored } from '@/lib/storage'

/*
 * Telegram's half of telegram-web-app.js's frame protocol, for the mini app in the preview's frame: the app posts
 * `{eventType, eventData}` as JSON to its parent window, and the parent answers the same way to the frame's window. What
 * Telegram draws for the app — its main and secondary buttons, its back button, its colours, full screen, popups, the
 * fingerprint prompt — is this hook's state, for components/mini-app/preview/telegram-phone to draw; what the app asks
 * of the device is answered here. A message is taken only from the frame's own window, on this site; one that is not
 * the protocol's JSON, or an event Telegram has and the preview does not, is ignored.
 */

/** A button at the bottom of Telegram's screen (the main one, the secondary one beside it), as the app set it up. */
export interface BottomButton {
  text: string
  /** Its colours as the app sent them (telegram-web-app.js always sends them); null for the theme's. */
  color: string | null
  textColor: string | null
  active: boolean
  /** Its spinner, in place of its words. */
  progress: boolean
  shine: boolean
  /** The secondary button's place beside the main one. */
  position: 'left' | 'right' | 'top' | 'bottom'
}

/** A question drawn over the app — a popup the app opened, the fingerprint's prompts, the close it asked to confirm. */
export interface Prompt {
  kind: 'popup' | 'fingerprint-access' | 'fingerprint' | 'closing'
  title: string
  message: string
  buttons: { id: string; text: string; destructive: boolean }[]
}

interface BridgeState {
  main: BottomButton | null
  secondary: BottomButton | null
  /** The back button in place of the close (Telegram's header, or its floating pill while full screen). */
  back: boolean
  /** «تنظیمات» in Telegram's ⋮ menu. */
  settings: boolean
  fullscreen: boolean
  /** Closing the app asks first (the app's closing confirmation). */
  confirmClose: boolean
  /** The colours the app set for Telegram's header, bottom bar and its own background; null for the theme's. */
  headerColor: string | null
  bottomBarColor: string | null
  backgroundColor: string | null
  prompt: Prompt | null
  /** A vibration the app asked for, shown a moment: its words, and a number told one from the next. */
  haptic: { label: string; id: number } | null
}

const INITIAL: BridgeState = {
  main: null,
  secondary: null,
  back: false,
  settings: false,
  fullscreen: false,
  confirmClose: false,
  headerColor: null,
  bottomBarColor: null,
  backgroundColor: null,
  prompt: null,
  haptic: null,
}

export interface Bridge extends BridgeState {
  pressMain: () => void
  pressSecondary: () => void
  pressBack: () => void
  pressSettings: () => void
  /** Telegram's close (its ✕): asked first while the app wants that, else the app closes. */
  close: () => void
  /** The prompt on screen answered with the id of the button pressed. */
  answer: (id: string) => void
}

interface BridgeOptions {
  frame: RefObject<HTMLIFrameElement | null>
  /** The app's address: its frame is on screen while there is one. */
  src: string | null
  theme: ThemeParams
  /** The app closed — by Telegram's close, or by itself: the preview opens it again. */
  onClose: () => void
}

/** How long a vibration's words stay on screen. */
const HAPTIC_MS = 900

/** A vibration as the preview words it, by its impact style, notification type or kind. */
const HAPTICS: Record<string, string> = {
  light: 'لرزش سبک',
  medium: 'لرزش متوسط',
  heavy: 'لرزش سنگین',
  rigid: 'لرزش خشک',
  soft: 'لرزش نرم',
  success: 'لرزش موفقیت',
  warning: 'لرزش هشدار',
  error: 'لرزش خطا',
  selection_change: 'تیک انتخاب',
}

/** A popup button's words when its type has Telegram's own. */
const POPUP_WORDS: Record<string, string> = { ok: 'تایید', close: 'بستن', cancel: 'لغو' }

type Data = Record<string, unknown>

/** A message of the protocol: JSON of `{eventType, eventData}`, its data an object (telegram-web-app.js sends '' for none). */
export function parseMessage(data: unknown): { type: string; data: Data } | null {
  if (typeof data !== 'string') return null
  let message: unknown
  try {
    message = JSON.parse(data)
  } catch {
    return null
  }
  if (typeof message !== 'object' || message === null) return null
  const { eventType, eventData } = message as { eventType?: unknown; eventData?: unknown }
  if (typeof eventType !== 'string') return null

  return { type: eventType, data: typeof eventData === 'object' && eventData !== null ? (eventData as Data) : {} }
}

const text = (value: unknown): string => (typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '')
const colour = (value: unknown): string | null => (typeof value === 'string' && /^#[\da-f]{6}$/i.test(value) ? value : null)

function bottomButton(data: Data): BottomButton | null {
  if (data.is_visible !== true) return null
  const position = data.position

  return {
    text: text(data.text),
    color: colour(data.color),
    textColor: colour(data.text_color),
    active: data.is_active !== false,
    progress: data.is_progress_visible === true,
    shine: data.has_shine_effect === true,
    position: position === 'right' || position === 'top' || position === 'bottom' ? position : 'left',
  }
}

/** The device's fingerprint as the preview keeps it in this browser — as a phone keeps it for the bot. */
interface Fingerprint {
  requested: boolean
  granted: boolean
  token: string
}

function storedFingerprint(): Fingerprint {
  try {
    const stored: unknown = JSON.parse(readStored(STORAGE_KEYS.previewFingerprint) ?? '{}')
    const value = (typeof stored === 'object' && stored !== null ? stored : {}) as Partial<Record<keyof Fingerprint, unknown>>
    return { requested: value.requested === true, granted: value.granted === true, token: typeof value.token === 'string' ? value.token : '' }
  } catch {
    return { requested: false, granted: false, token: '' }
  }
}

function keepFingerprint(fingerprint: Fingerprint): void {
  writeStored(STORAGE_KEYS.previewFingerprint, JSON.stringify(fingerprint))
}

function fingerprintInfo(fingerprint: Fingerprint) {
  return {
    available: true,
    type: 'finger',
    access_requested: fingerprint.requested,
    access_granted: fingerprint.granted,
    token_saved: fingerprint.token !== '',
    device_id: 'amobot-preview',
  }
}

/** The safe areas Telegram tells the app: under the status bar and its floating controls while full screen, none otherwise. */
function insets(fullscreen: boolean) {
  return {
    safe: { top: fullscreen ? STATUS_BAR : 0, bottom: 0, left: 0, right: 0 },
    content: { top: fullscreen ? CONTROLS : 0, bottom: 0, left: 0, right: 0 },
  }
}

/** A web address the app may open in a new tab: http or https only. */
function webAddress(value: unknown): string | null {
  try {
    const url = new URL(text(value))
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null
  } catch {
    return null
  }
}

/** Telegram's side of the protocol for the app in `frame` (see the top of the file). */
export function useWebAppBridge({ frame, src, theme, onClose }: BridgeOptions): Bridge {
  const [state, setState] = useState<BridgeState>(INITIAL)
  // The state as the last message left it: a message reads it (already full screen?) before React draws it.
  const current = useRef(state)
  const update = (patch: Partial<BridgeState>) => {
    current.current = { ...current.current, ...patch }
    setState(current.current)
  }

  const send = (eventType: string, eventData?: unknown) => {
    frame.current?.contentWindow?.postMessage(JSON.stringify({ eventType, eventData }), window.location.origin)
  }

  const sendViewport = () => {
    const element = frame.current
    // A frame out of sight (its section hidden) has no size to tell.
    if (!element || element.clientHeight === 0) return
    send('viewport_changed', { height: element.clientHeight, width: element.clientWidth, is_expanded: true, is_state_stable: true })
  }

  const sendInsets = (fullscreen: boolean) => {
    const { safe, content } = insets(fullscreen)
    send('safe_area_changed', safe)
    send('content_safe_area_changed', content)
  }

  const haptic = useRef(0)
  const hapticTimer = useRef<number | undefined>(undefined)
  useEffect(() => () => window.clearTimeout(hapticTimer.current), [])

  const vibrate = (data: Data) => {
    const id = ++haptic.current
    update({ haptic: { label: HAPTICS[text(data.impact_style || data.notification_type || data.type)] ?? 'لرزش', id } })
    window.clearTimeout(hapticTimer.current)
    hapticTimer.current = window.setTimeout(() => {
      if (current.current.haptic?.id === id) update({ haptic: null })
    }, HAPTIC_MS)
  }

  const setFullscreen = (fullscreen: boolean) => {
    update({ fullscreen })
    send('fullscreen_changed', { is_fullscreen: fullscreen })
    sendInsets(fullscreen)
  }

  /** What Telegram does with one event of the app's. */
  const receive = (type: string, data: Data) => {
    switch (type) {
      case 'web_app_setup_main_button':
        return update({ main: bottomButton(data) })
      case 'web_app_setup_secondary_button':
        return update({ secondary: bottomButton(data) })
      case 'web_app_setup_back_button':
        return update({ back: data.is_visible === true })
      case 'web_app_setup_settings_button':
        return update({ settings: data.is_visible === true })
      case 'web_app_setup_closing_behavior':
        return update({ confirmClose: data.need_confirmation === true })
      case 'web_app_set_header_color': {
        const key = text(data.color_key)
        return update({ headerColor: colour(data.color) ?? (key === 'bg_color' || key === 'secondary_bg_color' ? theme[key] : null) })
      }
      case 'web_app_set_bottom_bar_color':
        return update({ bottomBarColor: colour(data.color) })
      case 'web_app_set_background_color':
        return update({ backgroundColor: colour(data.color) })
      case 'web_app_trigger_haptic_feedback':
        return vibrate(data)
      // The theme picked, as soon as the script runs: one it kept in the tab's session storage never stands in for it.
      case 'iframe_ready':
      case 'web_app_request_theme':
        return send('theme_changed', { theme_params: theme })
      case 'web_app_request_viewport':
        return sendViewport()
      case 'web_app_request_safe_area':
        return send('safe_area_changed', insets(current.current.fullscreen).safe)
      case 'web_app_request_content_safe_area':
        return send('content_safe_area_changed', insets(current.current.fullscreen).content)
      case 'web_app_request_fullscreen':
        if (current.current.fullscreen) return send('fullscreen_failed', { error: 'ALREADY_FULLSCREEN' })
        return setFullscreen(true)
      case 'web_app_exit_fullscreen':
        if (current.current.fullscreen) setFullscreen(false)
        return
      case 'web_app_open_popup': {
        const buttons = Array.isArray(data.buttons) && data.buttons.length > 0 ? (data.buttons as Data[]) : [{ type: 'close' }]
        return update({
          prompt: {
            kind: 'popup',
            title: text(data.title),
            message: text(data.message),
            buttons: buttons.map((button) => ({ id: text(button.id), text: text(button.text) || POPUP_WORDS[text(button.type)] || 'تایید', destructive: button.type === 'destructive' })),
          },
        })
      }
      case 'web_app_open_link': {
        const url = webAddress(data.url)
        if (url === null) return
        toast.info('در مرورگر باز می‌شود', { description: url })
        window.open(url, '_blank', 'noopener,noreferrer')
        return
      }
      case 'web_app_open_tg_link':
        toast.info('در تلگرام باز می‌شود', { description: `https://t.me${text(data.path_full)}` })
        return
      case 'web_app_close':
        toast.info('مینی‌اپ بسته شد و دوباره باز می‌شود')
        return onClose()
      case 'web_app_request_phone':
        toast.info('در پیش‌نمایش شماره تلفنی فرستاده نمی‌شود')
        return send('phone_requested', { status: 'cancelled' })
      case 'web_app_biometry_get_info':
        return send('biometry_info_received', fingerprintInfo(storedFingerprint()))
      case 'web_app_biometry_request_access': {
        const fingerprint = storedFingerprint()
        // Asked once on a phone: asked again, the answer then stands.
        if (fingerprint.requested) return send('biometry_info_received', fingerprintInfo(fingerprint))
        return update({
          prompt: {
            kind: 'fingerprint-access',
            title: 'اجازه اثر انگشت',
            message: [text(data.reason), 'این مینی‌اپ می‌خواهد از اثر انگشت شما استفاده کند.'].filter(Boolean).join('\n\n'),
            buttons: [
              { id: 'allow', text: 'اجازه', destructive: false },
              { id: 'deny', text: 'اجازه نمی‌دهم', destructive: false },
            ],
          },
        })
      }
      case 'web_app_biometry_request_auth':
        if (!storedFingerprint().granted) return send('biometry_auth_requested', { status: 'failed' })
        return update({
          prompt: {
            kind: 'fingerprint',
            title: 'اثر انگشت',
            message: `${text(data.reason) || 'برای تایید، اثر انگشت خود را لمس کنید.'}\n(شبیه‌سازی: دکمه زیر را بزنید)`,
            buttons: [
              { id: 'touch', text: 'لمس اثر انگشت', destructive: false },
              { id: 'cancel', text: 'لغو', destructive: false },
            ],
          },
        })
      case 'web_app_biometry_update_token': {
        const fingerprint = storedFingerprint()
        if (!fingerprint.granted) return send('biometry_token_updated', { status: 'failed' })
        const token = text(data.token)
        keepFingerprint({ ...fingerprint, token })
        return send('biometry_token_updated', { status: token === '' ? 'removed' : 'updated' })
      }
      case 'web_app_biometry_open_settings':
        toast.info('تنظیمات اثر انگشت تلگرام باز می‌شود')
        return
    }
  }

  // The listeners read the latest of these (the theme, the close they were given) without coming and going with them.
  const latest = useRef({ receive, sendViewport })
  useLayoutEffect(() => {
    latest.current = { receive, sendViewport }
  })

  useEffect(() => {
    const listen = (event: MessageEvent) => {
      const source = frame.current?.contentWindow
      if (!source || event.source !== source || event.origin !== window.location.origin) return
      const message = parseMessage(event.data)
      if (message) latest.current.receive(message.type, message.data)
    }
    window.addEventListener('message', listen)
    return () => window.removeEventListener('message', listen)
  }, [frame])

  // The app's height changes with Telegram's parts around it (full screen, the bottom bar): it is told, as Telegram does.
  useEffect(() => {
    const element = frame.current
    if (src === null || !element || typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(() => latest.current.sendViewport())
    observer.observe(element)
    return () => observer.disconnect()
  }, [frame, src])

  const press = (button: BottomButton | null, event: string) => {
    if (button && button.active && !button.progress) send(event)
  }

  const answer = (id: string) => {
    const prompt = current.current.prompt
    if (!prompt) return
    update({ prompt: null })
    switch (prompt.kind) {
      case 'popup':
        return send('popup_closed', { button_id: id })
      case 'fingerprint-access': {
        const fingerprint = { ...storedFingerprint(), requested: true, granted: id === 'allow' }
        keepFingerprint(fingerprint)
        return send('biometry_info_received', fingerprintInfo(fingerprint))
      }
      case 'fingerprint':
        // The kept token comes back with a match — '' while none is kept, as Telegram answers.
        return send('biometry_auth_requested', id === 'touch' ? { status: 'authorized', token: storedFingerprint().token } : { status: 'failed' })
      case 'closing':
        if (id === 'close') onClose()
    }
  }

  return {
    ...state,
    pressMain: () => press(current.current.main, 'main_button_pressed'),
    pressSecondary: () => press(current.current.secondary, 'secondary_button_pressed'),
    pressBack: () => send('back_button_pressed'),
    pressSettings: () => send('settings_button_pressed'),
    close: () => {
      if (!current.current.confirmClose) return onClose()
      update({
        prompt: {
          kind: 'closing',
          title: '',
          message: 'تغییرهایی که داده‌اید ممکن است ذخیره نشوند.',
          buttons: [
            { id: 'close', text: 'بستن', destructive: true },
            { id: 'cancel', text: 'لغو', destructive: false },
          ],
        },
      })
    },
    answer,
  }
}
