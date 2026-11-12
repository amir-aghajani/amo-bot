import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TELEGRAM_THEMES } from '@/components/mini-app/preview/telegram'
import type { MiniAppPreviewResponse, MiniAppSettings, MiniAppSettingsResponse, UserRow, UsersResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { MiniAppSettingsPage } from '@/pages/mini-app-settings'
import { signedIn, until } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * «مینی‌اپ تلگرام» (pages/mini-app-settings): each card PATCHes only its own fields and the answer replaces the one read;
 * the switch held while APP_URL is not https — the owner sent to set it, an agent told the owner does —, the bot's menu
 * button Telegram did not take said above the cards (the save stood), the app's address and the steps in @BotFather, its
 * colour as the server keeps it, and its logo: uploaded at once, removed once asked; and its preview — a customer picked,
 * a read-only session opened for them and the app framed with it, opened again as the theme, the phone or the settings
 * change, and a cover once the session ends.
 */

const URL = '/api/admin/mini-app'
const LOGO = '/api/admin/mini-app/logo'
const SAVED_DONE = 'تنظیمات مینی‌اپ ذخیره شد'
const APP_SETTINGS = '/settings/app'
const ADDRESS = 'https://shop.example/app/0123456789abcdef01234567/'

/** The shop's mini app as its settings page reads it: on, at an https address, nothing of its look set. */
function miniAppRow(overrides: Partial<MiniAppSettings> = {}): MiniAppSettings {
  return {
    enabled: true,
    url: ADDRESS,
    secure: true,
    bot: { username: 'amo_shop_bot', url: 'https://t.me/amo_shop_bot' },
    title: null,
    shop_name: 'آب‌سردکن',
    accent_color: null,
    logo: false,
    announcement: null,
    announcement_url: null,
    fullscreen: true,
    reviews_enabled: false,
    menu_button: true,
    menu_button_text: null,
    menu_button_default: 'فروشگاه',
    ...overrides,
  }
}

const SAVED = miniAppRow()

/** The page at `at`, the app read as `miniApp`, in the panel `appSettings` says (the owner's links its address setting); resolved once its cards are on screen. */
async function show(at: string, miniApp: MiniAppSettings = SAVED, appSettings?: string) {
  server().on('GET', URL, json({ mini_app: miniApp, menu_button_error: null } satisfies MiniAppSettingsResponse))
  render(<MiniAppSettingsPage appSettings={appSettings} />, { wrapper: signedIn({ at }).wrapper })
  await screen.findAllByRole('button', { name: 'ذخیره' })
}

/** The PATCH answering with `miniApp`, as the server keeps it — and why the menu button was not set, when it was not. */
function saves(miniApp: MiniAppSettings, menuButtonError: string | null = null) {
  server().on('PATCH', URL, json({ mini_app: miniApp, menu_button_error: menuButtonError } satisfies MiniAppSettingsResponse))
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const valueOf = (label: string | RegExp) => (screen.getByLabelText(label) as HTMLInputElement).value
const sent = () =>
  server()
    .sent('PATCH', URL)
    .map((request) => request.body)

/** The section on screen, by the name the sidebar lists it under — the other is mounted too, hidden. */
const section = (name: string) => within(screen.getByRole('region', { name }))

/** The card titled `title` — one of a section's several —, its fields and its save within it. */
function card(title: string) {
  const form = screen.getByRole('heading', { name: title }).closest('form')
  if (form === null) throw new Error(`No card «${title}» on screen.`)
  return within(form)
}

const saveCard = (title: string) => fireEvent.click(card(title).getByRole('button', { name: 'ذخیره' }))

/* The optional fields: their labels carry «(اختیاری)» after the name. */
const MENU_TEXT = /^متن دکمه منو/
const TITLE = /^نام مینی‌اپ/
const COLOUR = /^رنگ اصلی/
const ANNOUNCEMENT = /^متن اطلاعیه/
const ANNOUNCEMENT_URL = /^لینک اطلاعیه/

const SWITCH = { name: 'مینی‌اپ روشن باشد' }
const MENU_BUTTON = { name: 'دکمه منوی ربات' }

/* The server's words (Store\Services\MiniApps): switching it on without https, and the menu button Telegram did not take. */
const HTTPS_NEEDED = 'تلگرام مینی‌اپ را فقط از آدرس https باز می‌کند؛ اول آدرس برنامه (APP_URL) را در تنظیمات پنل › برنامه با https بنویسید.'
const UNREACHABLE = 'دکمه منوی ربات تنظیم نشد: تلگرام در دسترس نبود؛ کمی بعد دوباره ذخیره کنید.'

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the mini app’s settings', () => {
  it('open on their first section', async () => {
    await show('/mini-app-settings')

    expect(screen.getByRole('switch', SWITCH)).toBeTruthy()
  })
})

describe('the connection card', () => {
  it('sends its own fields alone: the switch and the bot’s menu button, its words as typed', async () => {
    saves(miniAppRow({ menu_button_text: 'خرید سرویس' }))
    await show('/mini-app-settings/connection')

    expect((screen.getByLabelText(MENU_TEXT) as HTMLInputElement).placeholder).toBe('فروشگاه')
    type(MENU_TEXT, 'خرید سرویس ')
    saveCard('مینی‌اپ فروشگاه')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ enabled: true, menu_button: true, menu_button_text: 'خرید سرویس ' }])
    expect(valueOf(MENU_TEXT)).toBe('خرید سرویس')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(server().sent('GET', URL)).toHaveLength(1)
  })

  it('switches the menu button off, the app staying on', async () => {
    saves(miniAppRow({ menu_button: false }))
    await show('/mini-app-settings/connection')

    fireEvent.click(screen.getByRole('switch', MENU_BUTTON))
    saveCard('مینی‌اپ فروشگاه')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ enabled: true, menu_button: false, menu_button_text: '' }])
    expect(screen.getByRole('switch', MENU_BUTTON).getAttribute('aria-checked')).toBe('false')
  })

  it('says above the cards when Telegram did not take the menu button — the save stood', async () => {
    saves(miniAppRow({ enabled: false }), UNREACHABLE)
    await show('/mini-app-settings/connection')
    expect(screen.queryByText(new RegExp(UNREACHABLE))).toBeNull()

    fireEvent.click(screen.getByRole('switch', SWITCH))
    saveCard('مینی‌اپ فروشگاه')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(screen.getByRole('status').textContent).toBe(`تنظیمات ذخیره شد، ولی ${UNREACHABLE}`)
    expect(screen.getByRole('switch', SWITCH).getAttribute('aria-checked')).toBe('false')
  })

  it('keeps a refusal under the field it is about', async () => {
    server().on('PATCH', URL, refusal(422, HTTPS_NEEDED, { enabled: [HTTPS_NEEDED] }))
    await show('/mini-app-settings/connection', miniAppRow({ enabled: false }))

    fireEvent.click(screen.getByRole('switch', SWITCH))
    saveCard('مینی‌اپ فروشگاه')

    expect(await screen.findByText(HTTPS_NEEDED)).toBeTruthy()
    expect(screen.getByRole('switch', SWITCH).getAttribute('aria-invalid')).toBe('true')
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('an address that is not https', () => {
  it('holds the switch off — the owner sent to set the address in the panel’s settings', async () => {
    await show('/mini-app-settings/connection', miniAppRow({ enabled: false, secure: false, url: 'http://shop.example/app/0123456789abcdef01234567/' }), APP_SETTINGS)
    const toggle = screen.getByRole('switch', SWITCH)

    fireEvent.click(toggle)

    expect(toggle.getAttribute('aria-disabled')).toBe('true')
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(card('مینی‌اپ فروشگاه').getByText(/تا https نشود، مینی‌اپ روشن نمی‌شود\./)).toBeTruthy()
    expect(card('مینی‌اپ فروشگاه').getByRole('link', { name: 'تنظیمات پنل › برنامه' }).getAttribute('href')).toBe(APP_SETTINGS)
    // Telegram takes no other address for the bot's main app: no steps to give it one.
    expect(screen.queryByText('مینی‌اپ اصلی ربات در BotFather')).toBeNull()
  })

  it('tells an agent that the owner sets the address', async () => {
    await show('/mini-app-settings/connection', miniAppRow({ enabled: false, secure: false }))

    expect(card('مینی‌اپ فروشگاه').getByText(/آدرس برنامه را مالک فروشگاه تنظیم می‌کند\./)).toBeTruthy()
    expect(card('مینی‌اپ فروشگاه').queryByRole('link')).toBeNull()
  })

  it('kept on, may still be switched off — saying Telegram does not open it meanwhile', async () => {
    saves(miniAppRow({ enabled: false, secure: false }))
    await show('/mini-app-settings/connection', miniAppRow({ enabled: true, secure: false }), APP_SETTINGS)
    expect(card('مینی‌اپ فروشگاه').getByText(/تلگرام مینی‌اپ را برای مشتری باز نمی‌کند/)).toBeTruthy()

    fireEvent.click(screen.getByRole('switch', SWITCH))
    saveCard('مینی‌اپ فروشگاه')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ enabled: false, menu_button: true, menu_button_text: '' }])
  })
})

describe('the app’s address', () => {
  it('is copied whole, beside the shop’s bot', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    await show('/mini-app-settings/connection')

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'کپی' })))

    expect(write).toHaveBeenCalledWith(ADDRESS)
    expect(toast.success).toHaveBeenCalledWith('آدرس مینی‌اپ کپی شد')
    expect(section('اتصال').getByRole('link', { name: '@amo_shop_bot' }).getAttribute('href')).toBe('https://t.me/amo_shop_bot')
  })

  it('is what @BotFather takes for the bot’s main mini app, the steps there said', async () => {
    await show('/mini-app-settings/connection')

    const steps = screen.getByText('مینی‌اپ اصلی ربات در BotFather').closest('div')
    expect(steps?.textContent).toContain('Bot Settings › Configure Mini App')
    expect(steps?.textContent).toContain(`Main App URL همین آدرس را بفرستید: ${ADDRESS}`)
  })

  it('sets the bot’s menu button again with nothing changed — and says when Telegram did not take it', async () => {
    saves(SAVED)
    await show('/mini-app-settings/connection')

    fireEvent.click(screen.getByRole('button', { name: 'تنظیم دوباره دکمه منو' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith('دکمه منوی ربات دوباره تنظیم شد'))
    expect(sent()).toEqual([{}])

    saves(SAVED, UNREACHABLE)
    fireEvent.click(screen.getByRole('button', { name: 'تنظیم دوباره دکمه منو' }))
    await until(() => expect(screen.getByRole('status').textContent).toBe(`تنظیمات ذخیره شد، ولی ${UNREACHABLE}`))
  })

  it('offers no menu button to set again while the app or its button is off', async () => {
    await show('/mini-app-settings/connection', miniAppRow({ menu_button: false }))

    expect(screen.queryByRole('button', { name: 'تنظیم دوباره دکمه منو' })).toBeNull()
  })
})

describe('the look card', () => {
  it('sends its own fields alone — a colour typed in capitals as the server keeps it', async () => {
    saves(miniAppRow({ title: 'فروشگاه آب‌سردکن', accent_color: '#ff6600', fullscreen: false }))
    await show('/mini-app-settings/appearance')

    expect((screen.getByLabelText(TITLE) as HTMLInputElement).placeholder).toBe('آب‌سردکن')
    type(TITLE, 'فروشگاه آب‌سردکن')
    type(COLOUR, '#FF6600')
    fireEvent.click(screen.getByRole('switch', { name: 'تمام‌صفحه روی گوشی' }))
    saveCard('ظاهر مینی‌اپ')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ title: 'فروشگاه آب‌سردکن', accent_color: '#ff6600', fullscreen: false }])
    expect(valueOf(COLOUR)).toBe('#ff6600')
  })

  it('has nothing unsaved for its colour typed again in capitals, and takes one from the picker', async () => {
    await show('/mini-app-settings/appearance', miniAppRow({ accent_color: '#2481cc' }))

    type(COLOUR, '#2481CC')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()

    fireEvent.input(screen.getByLabelText('انتخاب رنگ اصلی'), { target: { value: '#00aa55' } })
    expect(valueOf(COLOUR)).toBe('#00aa55')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
  })

  it('goes back to Telegram’s own colour, sent blank', async () => {
    saves(SAVED)
    await show('/mini-app-settings/appearance', miniAppRow({ accent_color: '#ff6600' }))

    fireEvent.click(screen.getByRole('button', { name: 'رنگ تلگرام' }))
    saveCard('ظاهر مینی‌اپ')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ title: '', accent_color: '', fullscreen: true }])
    expect(screen.queryByRole('button', { name: 'رنگ تلگرام' })).toBeNull()
  })

  it('keeps a refusal under the colour', async () => {
    const words = 'رنگ را به شکل #RRGGBB بنویسید؛ مثل #2481cc.'
    server().on('PATCH', URL, refusal(422, words, { accent_color: [words] }))
    await show('/mini-app-settings/appearance')

    type(COLOUR, 'blue')
    saveCard('ظاهر مینی‌اپ')

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText(COLOUR).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the announcement card', () => {
  it('sends its words and its link alone', async () => {
    saves(miniAppRow({ announcement: 'تخفیف نوروزی تا آخر هفته', announcement_url: 'https://t.me/amo_channel' }))
    await show('/mini-app-settings/appearance')

    type(ANNOUNCEMENT, 'تخفیف نوروزی تا آخر هفته')
    type(ANNOUNCEMENT_URL, 'https://t.me/amo_channel')
    saveCard('اطلاعیه')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ announcement: 'تخفیف نوروزی تا آخر هفته', announcement_url: 'https://t.me/amo_channel' }])
  })

  it('keeps a refusal under the link', async () => {
    const words = 'لینک اطلاعیه باید یک آدرس https باشد؛ مثل https://t.me/channel.'
    server().on('PATCH', URL, refusal(422, words, { announcement_url: [words] }))
    await show('/mini-app-settings/appearance')

    type(ANNOUNCEMENT_URL, 'http://t.me/amo_channel')
    saveCard('اطلاعیه')

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText(ANNOUNCEMENT_URL).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the reviews’ card', () => {
  it('switches the app’s reviews on, sending its switch alone, and leads to the reviews it shows', async () => {
    saves(miniAppRow({ reviews_enabled: true }))
    await show('/mini-app-settings/appearance')

    expect(card('نظرات مشتریان در مینی‌اپ').getByRole('link', { name: 'نظرات' }).getAttribute('href')).toBe('/reviews')
    fireEvent.click(screen.getByRole('switch', { name: 'نظرات در مینی‌اپ' }))
    saveCard('نظرات مشتریان در مینی‌اپ')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ reviews_enabled: true }])
  })
})

describe('the cards', () => {
  it('save apart: another card’s save leaves a draft as it is', async () => {
    saves(miniAppRow({ reviews_enabled: true }))
    await show('/mini-app-settings/appearance')

    type(TITLE, 'فروشگاه آب‌سردکن')
    fireEvent.click(screen.getByRole('switch', { name: 'نظرات در مینی‌اپ' }))
    saveCard('نظرات مشتریان در مینی‌اپ')

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ reviews_enabled: true }])
    expect(valueOf(TITLE)).toBe('فروشگاه آب‌سردکن')
  })
})

/** The logo picked in the card's file picker. */
const pick = (file: File) => fireEvent.change(screen.getByLabelText('انتخاب تصویر لوگو'), { target: { files: [file] } })

describe('the logo', () => {
  it('without one, is the app’s initial on its colour', async () => {
    await show('/mini-app-settings/appearance', miniAppRow({ title: 'سرویس سریع' }))

    expect(screen.getByText('بدون لوگو')).toBeTruthy()
    expect(screen.getByText('س')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'حذف لوگو' })).toBeNull()
    expect(screen.getByText(/حداکثر ۲ مگابایت/).textContent).toMatch(/^JPG، PNG یا WebP، /)
  })

  it('is uploaded at once and shown from its address — a new one when it changes, in the shop the tab shows', async () => {
    const logo = new File(['png-bytes'], 'logo.png', { type: 'image/png' })
    server().on('POST', LOGO, json({ mini_app: miniAppRow({ logo: true }), menu_button_error: null } satisfies MiniAppSettingsResponse))
    await show('/mini-app-settings/appearance')

    pick(logo)

    await until(() => expect(toast.success).toHaveBeenCalledWith('لوگوی مینی‌اپ ذخیره شد'))
    const request = server().sent('POST', LOGO)[0]
    expect(request?.body instanceof FormData ? request.body.get('file') : null).toEqual(logo)
    expect(screen.getByRole('img', { name: 'لوگوی فعلی مینی‌اپ' }).getAttribute('src')).toBe('/api/admin/mini-app/logo?v=1&shop=1')
    expect(screen.queryByText('بدون لوگو')).toBeNull()
  })

  it('says a refused upload on the card', async () => {
    const words = 'لوگو باید تصویر JPG، PNG یا WebP باشد.'
    server().on('POST', LOGO, refusal(422, words, { file: [words] }))
    await show('/mini-app-settings/appearance')

    pick(new File(['text'], 'logo.png', { type: 'image/png' }))

    expect(await screen.findByText(words)).toBeTruthy()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it('is removed once asked — cancelled, nothing is sent —, the initial back in its place', async () => {
    server().on('DELETE', LOGO, json({ mini_app: miniAppRow(), menu_button_error: null } satisfies MiniAppSettingsResponse))
    await show('/mini-app-settings/appearance', miniAppRow({ logo: true }))
    expect(screen.getByRole('img', { name: 'لوگوی فعلی مینی‌اپ' }).getAttribute('src')).toBe('/api/admin/mini-app/logo?v=0&shop=1')

    fireEvent.click(screen.getByRole('button', { name: 'حذف لوگو' }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'حذف لوگوی مینی‌اپ' })).getByRole('button', { name: 'انصراف' }))
    await until(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(server().sent('DELETE', LOGO)).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: 'حذف لوگو' }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'حذف لوگوی مینی‌اپ' })).getByRole('button', { name: 'حذف لوگو' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('لوگوی مینی‌اپ حذف شد'))
    expect(server().sent('DELETE', LOGO)).toHaveLength(1)
    await until(() => expect(screen.getByText('بدون لوگو')).toBeTruthy())
    expect(screen.queryByRole('img', { name: 'لوگوی فعلی مینی‌اپ' })).toBeNull()
  })

  it('changed, keeps the word about the menu button with the save that said it', async () => {
    saves(miniAppRow({ reviews_enabled: true }), UNREACHABLE)
    server().on('POST', LOGO, json({ mini_app: miniAppRow({ reviews_enabled: true, logo: true }), menu_button_error: null } satisfies MiniAppSettingsResponse))
    await show('/mini-app-settings/appearance')

    fireEvent.click(screen.getByRole('switch', { name: 'نظرات در مینی‌اپ' }))
    saveCard('نظرات مشتریان در مینی‌اپ')
    await until(() => expect(screen.getByRole('status').textContent).toContain(UNREACHABLE))
    pick(new File(['png-bytes'], 'logo.png', { type: 'image/png' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('لوگوی مینی‌اپ ذخیره شد'))
    expect(screen.getByRole('status').textContent).toContain(UNREACHABLE)
  })
})

const PREVIEW = '/api/admin/mini-app/preview'
const USERS = '/api/admin/users'
const APP_PATH = '/app/0123456789abcdef01234567/'
const SARA = userRow({ id: 12, name: 'سارا', username: 'sara' })
const REZA = userRow({ id: 13, name: 'رضا', username: null, telegram_id: 5550001 })

/** The n-th preview's token, as the server makes one: 64 hex characters. */
const tokenOf = (n: number) => n.toString(16).padStart(64, '0')

/** The server opening previews, a token each — the n-th lasting `lasting[n - 1]` milliseconds, else the 30 minutes. */
function previews(...lasting: number[]) {
  let opened = 0
  server().on('POST', PREVIEW, () => {
    opened += 1
    const expires = new Date(Date.now() + (lasting[opened - 1] ?? 30 * 60_000)).toISOString()
    return json({ preview: { token: tokenOf(opened), expires_at: expires, path: APP_PATH } } satisfies MiniAppPreviewResponse)
  })
}

/** The preview section, the shop's customers `users` (those the search names, once one is typed); resolved, once they are listed, to the panel's cache. */
async function showPreview(miniApp: MiniAppSettings = SAVED, users: UserRow[] = [SARA, REZA]) {
  server()
    .on('GET', URL, json({ mini_app: miniApp, menu_button_error: null } satisfies MiniAppSettingsResponse))
    .on('GET', USERS, (request) => {
      const search = request.query.get('search')
      const found = search === null ? users : users.filter((user) => user.name?.includes(search))
      return json({ users: found, meta: { page: 1, per_page: 25, total: found.length, last_page: 1, sort: 'joined', dir: 'desc' } } satisfies UsersResponse)
    })
  const { client, wrapper } = signedIn({ at: '/mini-app-settings/preview' })
  render(<MiniAppSettingsPage />, { wrapper })
  await screen.findByRole('list', { name: 'مشتری‌ها' })
  return client
}

const pickCustomer = (name: string) => fireEvent.click(within(screen.getByRole('list', { name: 'مشتری‌ها' })).getByRole('button', { name: new RegExp(name) }))
const phoneOnScreen = () => within(screen.getByRole('figure', { name: 'مینی‌اپ در گوشی' }))
const frame = () => screen.getByTitle('مینی‌اپ')
const opened = () => until(() => expect(screen.getByTitle('مینی‌اپ')).toBeTruthy())

/** What the frame opens: the app's address, and the launch parameters in its fragment. */
function launch() {
  const [path, fragment = ''] = (frame().getAttribute('src') ?? '').split('#')
  return { path, parameters: new URLSearchParams(fragment) }
}

describe('the preview', () => {
  it('is the third section, a customer to pick before anything opens', async () => {
    await showPreview()

    expect(screen.getByRole('region', { name: 'پیش‌نمایش' })).toBeTruthy()
    expect(phoneOnScreen().getByText('یک مشتری انتخاب کنید')).toBeTruthy()
    expect(screen.queryByTitle('مینی‌اپ')).toBeNull()
    expect(server().sent('GET', USERS)[0]?.query.get('status')).toBe('active')
    expect(server().sent('POST', PREVIEW)).toHaveLength(0)
  })

  it('opens the app as the customer picked: a session for them, its token and Telegram’s theme in the frame’s address', async () => {
    previews()
    await showPreview()

    pickCustomer('سارا')

    await opened()
    expect(
      server()
        .sent('POST', PREVIEW)
        .map((request) => request.body),
    ).toEqual([{ user_id: 12 }])
    const { path, parameters } = launch()
    expect(path).toBe(APP_PATH)
    expect(parameters.get('tgWebAppPreview')).toBe(tokenOf(1))
    expect(parameters.get('tgWebAppVersion')).toBe('8.0')
    expect(parameters.get('tgWebAppPlatform')).toBe('android')
    expect(JSON.parse(parameters.get('tgWebAppThemeParams') ?? '')).toEqual(TELEGRAM_THEMES.light)
    expect(parameters.has('tgWebAppData')).toBe(false)
    expect(screen.getByText(/فقط نشان می‌دهد/).textContent).toMatch(/این یکی تا ساعت/)
    // Telegram's header names the app by the shop while it has no title of its own.
    expect(phoneOnScreen().getByText('آب‌سردکن')).toBeTruthy()
  })

  it('finds a customer by what is typed', async () => {
    previews()
    await showPreview()

    fireEvent.change(screen.getByLabelText('مشتری'), { target: { value: 'رضا' } })
    await until(() => expect(within(screen.getByRole('list', { name: 'مشتری‌ها' })).queryByRole('button', { name: /سارا/ })).toBeNull())
    expect(server().sent('GET', USERS).at(-1)?.query.get('search')).toBe('رضا')
    pickCustomer('رضا')

    await until(() =>
      expect(
        server()
          .sent('POST', PREVIEW)
          .map((request) => request.body),
      ).toEqual([{ user_id: 13 }]),
    )
  })

  it('opens the app again in the theme or on the phone picked — the same session', async () => {
    previews()
    await showPreview()
    pickCustomer('سارا')
    await opened()
    const first = frame()

    fireEvent.click(screen.getByRole('radio', { name: 'تیره' }))
    const dark = frame()
    expect(dark).not.toBe(first)
    expect(JSON.parse(launch().parameters.get('tgWebAppThemeParams') ?? '')).toEqual(TELEGRAM_THEMES.dark)

    fireEvent.click(screen.getByRole('radio', { name: 'بزرگ' }))
    expect(frame()).not.toBe(dark)
    expect(launch().parameters.get('tgWebAppPreview')).toBe(tokenOf(1))
    expect(server().sent('POST', PREVIEW)).toHaveLength(1)
  })

  it('opens the app again once a card saves the settings, with its new title', async () => {
    previews()
    saves(miniAppRow({ title: 'سرویس سریع' }))
    await showPreview()
    pickCustomer('سارا')
    await opened()
    const before = frame()

    type(TITLE, 'سرویس سریع')
    const look = screen.getByRole('heading', { name: 'ظاهر مینی‌اپ', hidden: true }).closest('form')
    if (look === null) throw new Error('No look card.')
    fireEvent.click(within(look).getByRole('button', { name: 'ذخیره', hidden: true }))

    await until(() => expect(frame()).not.toBe(before))
    expect(phoneOnScreen().getByText('سرویس سریع')).toBeTruthy()
    expect(launch().parameters.get('tgWebAppPreview')).toBe(tokenOf(1))
  })

  it('keeps the app as it is while the settings are only read again — a reconnect —, nothing saved', async () => {
    previews()
    const client = await showPreview()
    pickCustomer('سارا')
    await opened()
    const before = frame()

    await act(() => client.refetchQueries({ queryKey: queryKeys.miniApp }))

    expect(server().sent('GET', URL)).toHaveLength(2)
    expect(client.getQueryState(queryKeys.miniApp)?.dataUpdateCount).toBe(2)
    expect(frame()).toBe(before)
  })

  it('opens a new session with «باز کردن دوباره»', async () => {
    previews()
    await showPreview()
    pickCustomer('سارا')
    await opened()

    fireEvent.click(section('پیش‌نمایش').getByRole('button', { name: 'باز کردن دوباره' }))

    await until(() => expect(launch().parameters.get('tgWebAppPreview')).toBe(tokenOf(2)))
    expect(
      server()
        .sent('POST', PREVIEW)
        .map((request) => request.body),
    ).toEqual([{ user_id: 12 }, { user_id: 12 }])
  })

  it('covers the phone once the session ends, and opens a new one from there', async () => {
    previews(60)
    await showPreview()
    pickCustomer('سارا')

    await until(() => expect(phoneOnScreen().getByText('زمان پیش‌نمایش تمام شد')).toBeTruthy())
    fireEvent.click(phoneOnScreen().getByRole('button', { name: 'باز کردن دوباره' }))

    await until(() => expect(launch().parameters.get('tgWebAppPreview')).toBe(tokenOf(2)))
    expect(phoneOnScreen().queryByText('زمان پیش‌نمایش تمام شد')).toBeNull()
  })

  it('says under the customer why the server would not open it as them', async () => {
    const words = 'این مشتری مسدود است.'
    server().on('POST', PREVIEW, refusal(422, words, { user_id: [words] }))
    await showPreview()

    pickCustomer('سارا')

    await until(() => expect(phoneOnScreen().getByText('پیش‌نمایش باز نشد')).toBeTruthy())
    // Under the customer picked, and on the phone.
    expect(section('پیش‌نمایش').getAllByText(words)).toHaveLength(2)
    expect(screen.queryByTitle('مینی‌اپ')).toBeNull()
  })

  it('says it works while the app is off', async () => {
    await showPreview(miniAppRow({ enabled: false }))

    expect(screen.getByText(/مینی‌اپ خاموش است، ولی پیش‌نمایش با این حال کار می‌کند/)).toBeTruthy()
  })

  it('lets go of the customer for another, the app closed meanwhile', async () => {
    previews()
    await showPreview()
    pickCustomer('سارا')
    await opened()

    fireEvent.click(section('پیش‌نمایش').getByRole('button', { name: 'تغییر' }))
    await screen.findByRole('list', { name: 'مشتری‌ها' })
    expect(screen.queryByTitle('مینی‌اپ')).toBeNull()
    pickCustomer('رضا')

    await until(() => expect(launch().parameters.get('tgWebAppPreview')).toBe(tokenOf(2)))
    expect(
      server()
        .sent('POST', PREVIEW)
        .map((request) => request.body),
    ).toEqual([{ user_id: 12 }, { user_id: 13 }])
  })
})
