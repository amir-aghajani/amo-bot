import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CONTROLS, STATUS_BAR, TELEGRAM_THEMES } from '@/components/mini-app/preview/telegram'
import { TelegramPhone } from '@/components/mini-app/preview/telegram-phone'
import { parseMessage } from '@/components/mini-app/preview/use-web-app-bridge'

/*
 * The phone the mini app's preview draws (components/mini-app/preview/telegram-phone) and Telegram's half of the frame
 * protocol behind it (use-web-app-bridge): what the app sets up is drawn — the main button, the back button, full screen
 * with Telegram's floating controls, popups, the fingerprint's prompts, a vibration —, a press is posted back to the
 * frame, and only the frame's own window, on this site, is listened to.
 */

/** What the panel posted to the frame, in order. */
let posted: { eventType: string; eventData?: unknown }[]

/** The frame's window, as the panel sees it: what it posts there is kept in `posted`. */
let appWindow: { postMessage: (message: string, origin: string) => void }

beforeEach(() => {
  posted = []
  appWindow = { postMessage: vi.fn((message: string) => void posted.push(JSON.parse(message))) }
  vi.spyOn(HTMLIFrameElement.prototype, 'contentWindow', 'get').mockReturnValue(appWindow as unknown as WindowProxy)
  vi.spyOn(toast, 'info')
})

/** An event of the app's, as telegram-web-app.js posts it to its parent — from `source`, at `origin`. */
function fromApp(eventType: string, eventData?: unknown, { source = appWindow as unknown, origin = window.location.origin } = {}) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { data: JSON.stringify({ eventType, eventData: eventData ?? '' }), source: source as Window, origin }))
  })
}

/** The events posted to the frame named `eventType`, their data. */
const sent = (eventType: string) => posted.filter((message) => message.eventType === eventType).map((message) => message.eventData)

function phone(onClose = vi.fn()) {
  render(<TelegramPhone device="small" theme="light" title="فروشگاه آب‌سردکن" src="/app/0123456789abcdef01234567/#tgWebAppPreview=abc" onClose={onClose} />)
  return onClose
}

const telegramBar = () => screen.queryByRole('toolbar', { name: 'نوار تلگرام' })
const floating = () => screen.queryByRole('toolbar', { name: 'دکمه‌های شناور تلگرام' })

describe('the phone', () => {
  it('opens the app in its frame, under Telegram’s header with the app’s title', () => {
    phone()

    expect(screen.getByTitle('مینی‌اپ').getAttribute('src')).toBe('/app/0123456789abcdef01234567/#tgWebAppPreview=abc')
    expect(within(telegramBar() as HTMLElement).getByText('فروشگاه آب‌سردکن')).toBeTruthy()
    expect(within(telegramBar() as HTMLElement).getByRole('button', { name: 'بستن' })).toBeTruthy()
  })

  it('answers the theme Telegram would hand the app, as soon as the script runs and whenever it asks', () => {
    phone()

    fromApp('iframe_ready', { reload_supported: true })
    fromApp('web_app_request_theme')

    expect(sent('theme_changed')).toEqual([{ theme_params: TELEGRAM_THEMES.light }, { theme_params: TELEGRAM_THEMES.light }])
  })
})

describe('the main button', () => {
  it('is drawn as the app sets it up, and a press is posted to the frame', () => {
    phone()

    fromApp('web_app_setup_main_button', { is_visible: true, is_active: true, is_progress_visible: false, text: 'خرید سرویس', color: '#ff6600', text_color: '#ffffff', has_shine_effect: true })
    const button = screen.getByRole('button', { name: 'خرید سرویس' })
    expect(button.style.background).toMatch(/#ff6600|rgb\(255, 102, 0\)/)
    fireEvent.click(button)

    expect(sent('main_button_pressed')).toHaveLength(1)
  })

  it('takes no press while inactive or spinning, and goes once hidden', () => {
    phone()

    fromApp('web_app_setup_main_button', { is_visible: true, is_active: false, text: 'پرداخت' })
    fireEvent.click(screen.getByRole('button', { name: 'پرداخت' }))
    expect(screen.getByRole('button', { name: 'پرداخت' }).getAttribute('aria-disabled')).toBe('true')

    fromApp('web_app_setup_main_button', { is_visible: true, is_active: true, is_progress_visible: true, text: 'پرداخت' })
    fireEvent.click(screen.getByRole('button', { name: 'پرداخت' }))
    expect(screen.getByRole('button', { name: 'پرداخت' }).getAttribute('aria-busy')).toBe('true')
    expect(sent('main_button_pressed')).toHaveLength(0)

    fromApp('web_app_setup_main_button', { is_visible: false })
    expect(screen.queryByRole('button', { name: 'پرداخت' })).toBeNull()
  })

  it('has the secondary button beside it when the app shows one', () => {
    phone()

    fromApp('web_app_setup_main_button', { is_visible: true, is_active: true, text: 'تایید' })
    fromApp('web_app_setup_secondary_button', { is_visible: true, is_active: true, text: 'انصراف', position: 'left' })
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))

    expect(sent('secondary_button_pressed')).toHaveLength(1)
  })
})

describe('the back button', () => {
  it('stands in for the close while the app shows it, and a press is posted', () => {
    const onClose = phone()

    fromApp('web_app_setup_back_button', { is_visible: true })
    fireEvent.click(within(telegramBar() as HTMLElement).getByRole('button', { name: 'بازگشت' }))
    expect(sent('back_button_pressed')).toHaveLength(1)

    fromApp('web_app_setup_back_button', { is_visible: false })
    fireEvent.click(within(telegramBar() as HTMLElement).getByRole('button', { name: 'بستن' }))
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('closes after a question while the app asks for one first', () => {
    const onClose = phone()

    fromApp('web_app_setup_closing_behavior', { need_confirmation: true })
    fireEvent.click(within(telegramBar() as HTMLElement).getByRole('button', { name: 'بستن' }))
    const question = screen.getByRole('alertdialog')
    expect(onClose).not.toHaveBeenCalled()
    fireEvent.click(within(question).getByRole('button', { name: 'بستن' }))

    expect(onClose).toHaveBeenCalledOnce()
  })

  it('gives the focus back to the close once the question is answered «لغو»', () => {
    const onClose = phone()

    fromApp('web_app_setup_closing_behavior', { need_confirmation: true })
    const close = within(telegramBar() as HTMLElement).getByRole('button', { name: 'بستن' })
    close.focus()
    fireEvent.click(close)
    const question = screen.getByRole('alertdialog')
    expect(document.activeElement).toBe(within(question).getByRole('button', { name: 'بستن' }))
    fireEvent.click(within(question).getByRole('button', { name: 'لغو' }))

    expect(onClose).not.toHaveBeenCalled()
    expect(screen.queryByRole('alertdialog')).toBeNull()
    expect(document.activeElement).toBe(close)
  })
})

describe('a popup', () => {
  it('is drawn over the app, and the button pressed is posted back', () => {
    phone()

    fromApp('web_app_open_popup', {
      title: 'حذف سرویس',
      message: 'مطمئن هستید؟',
      buttons: [
        { id: 'delete', type: 'destructive', text: 'حذف' },
        { id: 'no', type: 'cancel' },
      ],
    })
    const popup = screen.getByRole('alertdialog', { name: 'حذف سرویس' })
    expect(within(popup).getByText('مطمئن هستید؟')).toBeTruthy()
    expect(within(popup).getByRole('button', { name: 'لغو' })).toBeTruthy()
    fireEvent.click(within(popup).getByRole('button', { name: 'حذف' }))

    expect(sent('popup_closed')).toEqual([{ button_id: 'delete' }])
    expect(screen.queryByRole('alertdialog')).toBeNull()
  })

  it('covers the phone alone — not the page — and gives the focus back to the frame once answered', () => {
    phone()
    const app = screen.getByTitle('مینی‌اپ')
    app.focus()

    fromApp('web_app_open_popup', { message: 'ذخیره شد', buttons: [{ id: 'ok', type: 'ok' }] })
    const popup = screen.getByRole('alertdialog', { name: 'پیام مینی‌اپ' })
    expect(popup.hasAttribute('aria-modal')).toBe(false)
    expect(document.activeElement).toBe(within(popup).getByRole('button', { name: 'تایید' }))
    fireEvent.click(within(popup).getByRole('button', { name: 'تایید' }))

    expect(screen.queryByRole('alertdialog')).toBeNull()
    expect(document.activeElement).toBe(app)
  })
})

describe('full screen', () => {
  it('is granted as Telegram 8.0 grants it: the header gives way to floating controls, the app told its safe areas', () => {
    phone()

    fromApp('web_app_request_fullscreen')

    expect(sent('fullscreen_changed')).toEqual([{ is_fullscreen: true }])
    expect(sent('safe_area_changed')).toEqual([{ top: STATUS_BAR, bottom: 0, left: 0, right: 0 }])
    expect(sent('content_safe_area_changed')).toEqual([{ top: CONTROLS, bottom: 0, left: 0, right: 0 }])
    expect(telegramBar()).toBeNull()
    expect(within(floating() as HTMLElement).getByRole('button', { name: 'بستن' })).toBeTruthy()
    expect(within(floating() as HTMLElement).getByRole('button', { name: 'منوی مینی‌اپ' })).toBeTruthy()
  })

  it('says so when asked again, answers the insets it stands at, and gives the header back on its way out', () => {
    phone()
    fromApp('web_app_request_fullscreen')
    posted = []

    fromApp('web_app_request_fullscreen')
    fromApp('web_app_request_safe_area')
    fromApp('web_app_request_content_safe_area')
    expect(sent('fullscreen_failed')).toEqual([{ error: 'ALREADY_FULLSCREEN' }])
    expect(sent('safe_area_changed')).toEqual([{ top: STATUS_BAR, bottom: 0, left: 0, right: 0 }])
    expect(sent('content_safe_area_changed')).toEqual([{ top: CONTROLS, bottom: 0, left: 0, right: 0 }])

    fromApp('web_app_exit_fullscreen')
    expect(sent('fullscreen_changed')).toEqual([{ is_fullscreen: false }])
    expect(sent('safe_area_changed').at(-1)).toEqual({ top: 0, bottom: 0, left: 0, right: 0 })
    expect(floating()).toBeNull()
    expect(telegramBar()).toBeTruthy()
  })

  it('shows the app’s back in the floating pill while it has one', () => {
    phone()

    fromApp('web_app_request_fullscreen')
    fromApp('web_app_setup_back_button', { is_visible: true })
    fireEvent.click(within(floating() as HTMLElement).getByRole('button', { name: 'بازگشت' }))

    expect(sent('back_button_pressed')).toHaveLength(1)
  })
})

describe('what the phone listens to', () => {
  it('is the frame’s own window on this site alone, and the protocol’s JSON alone', () => {
    phone()

    fromApp('web_app_setup_main_button', { is_visible: true, text: 'از جای دیگر' }, { source: {} })
    fromApp('web_app_setup_main_button', { is_visible: true, text: 'از سایت دیگر' }, { origin: 'https://evil.example' })
    act(() => {
      window.dispatchEvent(new MessageEvent('message', { data: '{not json', source: appWindow as unknown as Window, origin: window.location.origin }))
      window.dispatchEvent(new MessageEvent('message', { data: { eventType: 'web_app_setup_main_button' }, source: appWindow as unknown as Window, origin: window.location.origin }))
    })
    fromApp('web_app_something_new', { anything: true })

    expect(screen.queryByRole('button', { name: /جای دیگر|سایت دیگر/ })).toBeNull()
    expect(posted).toEqual([])
  })

  it('reads a message only as JSON of an event type, its data an object', () => {
    expect(parseMessage('{"eventType":"web_app_ready","eventData":""}')).toEqual({ type: 'web_app_ready', data: {} })
    expect(parseMessage('{"eventType":"web_app_open_link","eventData":{"url":"https://t.me"}}')).toEqual({ type: 'web_app_open_link', data: { url: 'https://t.me' } })
    expect(parseMessage('{"eventData":{}}')).toBeNull()
    expect(parseMessage('null')).toBeNull()
    expect(parseMessage({ eventType: 'web_app_ready' })).toBeNull()
  })
})

describe('the rest of Telegram', () => {
  it('shows a vibration a moment', async () => {
    phone()

    fromApp('web_app_trigger_haptic_feedback', { type: 'impact', impact_style: 'light' })

    expect(screen.getByRole('status').textContent).toBe('لرزش سبک')
  })

  it('opens a link in a new tab, and says where a Telegram link goes', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    phone()

    fromApp('web_app_open_link', { url: 'https://example.com/guide' })
    fromApp('web_app_open_link', { url: 'javascript:alert(1)' })
    fromApp('web_app_open_tg_link', { path_full: '/amo_shop_bot?start=ref_7' })

    expect(open).toHaveBeenCalledExactlyOnceWith('https://example.com/guide', '_blank', 'noopener,noreferrer')
    expect(toast.info).toHaveBeenCalledWith('در مرورگر باز می‌شود', { description: 'https://example.com/guide' })
    expect(toast.info).toHaveBeenCalledWith('در تلگرام باز می‌شود', { description: 'https://t.me/amo_shop_bot?start=ref_7' })
  })

  it('opens the app again once it closes itself', () => {
    const onClose = phone()

    fromApp('web_app_close')

    expect(onClose).toHaveBeenCalledOnce()
  })

  it('keeps a fingerprint in this browser: asked once, then the token it keeps handed back on a touch', () => {
    phone()

    fromApp('web_app_biometry_get_info')
    expect(sent('biometry_info_received').at(-1)).toMatchObject({ available: true, type: 'finger', access_requested: false, access_granted: false })

    fromApp('web_app_biometry_request_access', { reason: 'برای ورود سریع' })
    fireEvent.click(within(screen.getByRole('alertdialog', { name: 'اجازه اثر انگشت' })).getByRole('button', { name: 'اجازه' }))
    expect(sent('biometry_info_received').at(-1)).toMatchObject({ access_requested: true, access_granted: true, token_saved: false })

    fromApp('web_app_biometry_update_token', { token: 'secret-7' })
    expect(sent('biometry_token_updated')).toEqual([{ status: 'updated' }])

    fromApp('web_app_biometry_request_auth', { reason: 'تایید خرید' })
    fireEvent.click(within(screen.getByRole('alertdialog', { name: 'اثر انگشت' })).getByRole('button', { name: 'لمس اثر انگشت' }))
    expect(sent('biometry_auth_requested')).toEqual([{ status: 'authorized', token: 'secret-7' }])

    // Asked again, the answer stands, with no prompt.
    fromApp('web_app_biometry_request_access')
    expect(screen.queryByRole('alertdialog')).toBeNull()
    expect(sent('biometry_info_received').at(-1)).toMatchObject({ access_granted: true, token_saved: true })
  })

  it('refuses the fingerprint while it is not allowed', () => {
    phone()

    fromApp('web_app_biometry_request_auth')
    fromApp('web_app_biometry_update_token', { token: 'secret' })

    expect(sent('biometry_auth_requested')).toEqual([{ status: 'failed' }])
    expect(sent('biometry_token_updated')).toEqual([{ status: 'failed' }])
  })

  it('opens ⋮ with the app loaded again in it, and the app’s settings while it shows them', () => {
    const onClose = phone()

    fromApp('web_app_setup_settings_button', { is_visible: true })
    fireEvent.click(within(telegramBar() as HTMLElement).getByRole('button', { name: 'منوی مینی‌اپ' }))
    fireEvent.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'تنظیمات' }))
    expect(sent('settings_button_pressed')).toHaveLength(1)

    fireEvent.click(within(telegramBar() as HTMLElement).getByRole('button', { name: 'منوی مینی‌اپ' }))
    fireEvent.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'بارگذاری دوباره' }))
    expect(onClose).toHaveBeenCalledOnce()
    expect(screen.queryByRole('menu')).toBeNull()
  })

  it('moves along ⋮ with ↑/↓ round its ends, and gives the focus back to ⋮ on Escape or a pick', () => {
    phone()
    fromApp('web_app_setup_settings_button', { is_visible: true })
    const dots = within(telegramBar() as HTMLElement).getByRole('button', { name: 'منوی مینی‌اپ' })

    fireEvent.click(dots)
    const menu = screen.getByRole('menu')
    const [settings, reload] = within(menu).getAllByRole('menuitem')
    expect(document.activeElement).toBe(settings)
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(reload)
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'ArrowDown' })
    expect(document.activeElement).toBe(settings)
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'ArrowUp' })
    expect(document.activeElement).toBe(reload)
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'Home' })
    expect(document.activeElement).toBe(settings)

    fireEvent.keyDown(document.activeElement as HTMLElement, { key: 'Escape' })
    expect(screen.queryByRole('menu')).toBeNull()
    expect(dots.getAttribute('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(dots)

    fireEvent.click(dots)
    fireEvent.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: 'تنظیمات' }))
    expect(screen.queryByRole('menu')).toBeNull()
    expect(document.activeElement).toBe(dots)
  })
})
