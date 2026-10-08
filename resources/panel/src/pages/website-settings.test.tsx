import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Website, WebsiteResponse } from '@/lib/api-types'
import { WebsiteSettingsPage } from '@/pages/website-settings'
import { signedIn, until } from '@/test/render'
import { websiteCaptcha, websiteRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The shop's website, «تنظیمات وب‌سایت» (pages/website-settings): six sections over one read — the connection, then
 * Telegram, Google and email sign-in, the reviews, then the shop's admins. Each card PATCHes its own fields alone — a
 * kept secret never sent back unless one is typed —, and what the server answers takes the read's place, nothing read
 * again; a refusal stays under its field. Email sign-up waits for the shop's email, which the owner's panel links to and
 * an agent's says the owner sets up. The captcha is none or a driver, its form drawn from the server's description, a
 * kept secret asked for again once its key moved, a refusal under its field by the server's `captcha.<field>`. The
 * reviews' card switches them, and says who writes them by the captcha asked. The admins' card sends its switches and
 * its grants whole, in the grants' own order, and says who the admins are — the users list narrowed to the role — and
 * how far the guards on them go. The API's address is copied whole, and a new key is asked for first.
 */

const URL = '/api/admin/website'
const KEY = '/api/admin/website/key'
const SAVED = websiteRow()
const SAVED_DONE = 'تنظیمات وب‌سایت ذخیره شد'
const MAIL_SETTINGS = '/settings/mail'

/** The page at `at`, the website read as `website`, in the panel `mailSettings` says (the owner's links its email settings); resolved once its cards are on screen. */
async function show(at: string, website: Website = SAVED, mailSettings?: string) {
  server().on('GET', URL, json({ website } satisfies WebsiteResponse))
  render(<WebsiteSettingsPage mailSettings={mailSettings} />, { wrapper: signedIn({ at }).wrapper })
  await screen.findAllByRole('button', { name: 'ذخیره' })
}

/** The PATCH answering with `website`, as the server keeps it. */
function saves(website: Website) {
  server().on('PATCH', URL, json({ website } satisfies WebsiteResponse))
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const valueOf = (label: string | RegExp) => (screen.getByLabelText(label) as HTMLInputElement).value
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const sent = () =>
  server()
    .sent('PATCH', URL)
    .map((request) => request.body)

/** The section on screen, by the name the sidebar lists it under — the others are mounted too, hidden. */
const section = (name: string) => within(screen.getByRole('region', { name }))

/** The card titled `title` — one of a section's several —, its fields and its save within it. */
function card(title: string) {
  const form = screen.getByRole('heading', { name: title }).closest('form')
  if (form === null) throw new Error(`No card «${title}» on screen.`)
  return within(form)
}

/* The optional fields: their labels carry «(اختیاری)» after the name. */
const ORIGINS = /^Originهای مجاز دیگر/
const SECRET = /^Client Secret/

const SITE_KEY = 'Site Key'
const SECRET_KEY = 'Secret Key'
const SIGNUP = 'ثبت‌نام با ایمیل'
const CAPTCHA = 'تایید امنیتی'
const CAPTCHA_DRIVER = 'نوع تایید امنیتی'
const SIGNUP_SWITCH = { name: 'ثبت‌نام با ایمیل و رمز عبور' }

/** Cloudflare Turnstile asked, its keys kept. */
const TURNSTILE = websiteCaptcha('turnstile', { site_key: '0x4AAAAAAA-site', secret_key: { set: true, hint: '••••••cret' } })

/* The server's words (App\Core\Captcha\Drivers\Turnstile): a kept secret whose site key moved, a key that is none of Cloudflare's, and a key missing. */
const MOVED = 'با Site Key تازه، Secret Key همان ویجت را هم وارد کنید.'
const MISMATCH = 'Site Key را همان‌طور که Cloudflare نشان می‌دهد کپی کنید؛ فاصله و حروف فارسی ندارد.'
const SITE_KEY_MISSING = 'Site Key را وارد کنید.'
const SECRET_MISSING = 'Secret Key را هم از Cloudflare وارد کنید.'

/** The option `option` picked in the select labelled `label`. */
function pick(label: string, option: string) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: option }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the website’s settings', () => {
  it('open on their first section', async () => {
    await show('/website-settings')

    expect(screen.getByRole('switch', { name: 'API وب‌سایت روشن باشد' })).toBeTruthy()
  })
})

describe('the website’s card', () => {
  it('sends its own fields alone: the switch, the address and the origins typed, as a list', async () => {
    saves({ ...SAVED, url: 'https://new.example', origins: ['http://localhost:3000', 'https://staging.example', 'http://127.0.0.1:5173'] })
    await show('/website-settings/connection')

    type('آدرس وب‌سایت', 'https://new.example/')
    type(ORIGINS, ' http://localhost:3000\n\nhttps://staging.example, http://127.0.0.1:5173 \n')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ enabled: true, url: 'https://new.example/', origins: ['http://localhost:3000', 'https://staging.example', 'http://127.0.0.1:5173'] }])
  })

  it('starts again from the website as the server keeps it, without reading it again', async () => {
    saves({ ...SAVED, url: 'https://new.example', origins: [] })
    await show('/website-settings/connection')

    type('آدرس وب‌سایت', 'https://new.example/')
    type(ORIGINS, '')
    save()

    await until(() => expect(valueOf('آدرس وب‌سایت')).toBe('https://new.example'))
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(server().sent('GET', URL)).toHaveLength(1)
  })

  it('has nothing unsaved for the origins typed again otherwise — what it would send is the same list', async () => {
    await show('/website-settings/connection')

    type(ORIGINS, '\n http://localhost:3000 \n')

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('keeps a refusal under the field it is about', async () => {
    const words = 'آدرس وب‌سایت را وارد کنید؛ وب‌سایت بدون آدرس روشن نمی‌شود.'
    server().on('PATCH', URL, refusal(422, 'اطلاعات واردشده معتبر نیست.', { url: [words] }))
    await show('/website-settings/connection', websiteRow({ enabled: false, url: null }))

    fireEvent.click(screen.getByRole('switch', { name: 'API وب‌سایت روشن باشد' }))
    save()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText('آدرس وب‌سایت').getAttribute('aria-invalid')).toBe('true')
    expect(sent()[0]).toEqual({ enabled: true, url: '', origins: ['http://localhost:3000'] })
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the Telegram sign-in card', () => {
  it('sends its own fields alone, a blank secret left out — the kept one stays', async () => {
    saves(SAVED)
    await show('/website-settings/telegram', websiteRow({ telegram: { ...SAVED.telegram, enabled: false, client_id: null } }))

    fireEvent.click(screen.getByRole('switch', { name: 'ورود با تلگرام' }))
    type('Client ID', '۷۱۲۳۴۵۶۷۸۹')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ telegram_login: true, telegram_client_id: '۷۱۲۳۴۵۶۷۸۹', clear_telegram_client_secret: false }])
    // The digits as the server keeps them.
    expect(valueOf('Client ID')).toBe('7123456789')
  })

  it('says the bot’s own id beside the Client ID, and the address BotFather must allow', async () => {
    await show('/website-settings/telegram')

    expect(screen.getByText(/شناسه ربات این فروشگاه/).textContent).toContain('7123456789')
    expect(section('ورود با تلگرام').getByText('https://shop.example')).toBeTruthy()
  })

  it('without an address, says to save one in «اتصال» first', async () => {
    await show('/website-settings/telegram', websiteRow({ enabled: false, url: null }))

    expect(screen.getByRole('link', { name: 'اتصال' }).getAttribute('href')).toBe('/website-settings/connection')
  })

  it('sends a secret typed — and once it is kept, the field is blank again, saying one is kept', async () => {
    saves(SAVED)
    await show('/website-settings/telegram', websiteRow({ telegram: { ...SAVED.telegram, has_secret: false } }))

    type(SECRET, 'aB3-secret_from_BotFather')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()[0]).toMatchObject({ telegram_client_secret: 'aB3-secret_from_BotFather', clear_telegram_client_secret: false })
    expect(valueOf(SECRET)).toBe('')
    expect((screen.getByLabelText(SECRET) as HTMLInputElement).placeholder).toBe('برای نگه‌داشتن مقدار فعلی خالی بگذارید')
    expect(screen.getByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' })).toBeTruthy()
  })

  it('empties the kept secret when asked to', async () => {
    saves(websiteRow({ telegram: { ...SAVED.telegram, has_secret: false } }))
    await show('/website-settings/telegram')

    fireEvent.click(screen.getByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' }))
    expect((screen.getByLabelText(SECRET) as HTMLInputElement).placeholder).toBe('با ذخیره حذف می‌شود')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()[0]).toMatchObject({ clear_telegram_client_secret: true })
    expect(sent()[0]).not.toHaveProperty('telegram_client_secret')
    expect((screen.getByLabelText(SECRET) as HTMLInputElement).placeholder).toBe('')
    expect(screen.queryByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' })).toBeNull()
  })

  it('keeps a refusal under the field it is about', async () => {
    const words = 'Client ID باید فقط عدد باشد.'
    server().on('PATCH', URL, refusal(422, 'اطلاعات واردشده معتبر نیست.', { telegram_client_id: [words] }))
    await show('/website-settings/telegram')

    type('Client ID', 'my_shop_bot')
    save()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText('Client ID').getAttribute('aria-invalid')).toBe('true')
    expect(screen.queryByRole('alert')).toBeNull()
  })
})

describe('the Google sign-in card', () => {
  const CLIENT_ID = '481516234200-a1b2c3d4e5f6g7h8.apps.googleusercontent.com'

  it('sends the Client ID alone, and says which origin Google Cloud must allow', async () => {
    saves(websiteRow({ google: { client_id: CLIENT_ID } }))
    await show('/website-settings/google', websiteRow({ url: 'https://Shop.Example/app' }))

    // The website's origin — Google takes no path —, as the browser sends it.
    expect(section('ورود با گوگل').getByText('https://shop.example')).toBeTruthy()
    type('Client ID گوگل', CLIENT_ID)
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ google_client_id: CLIENT_ID }])
    expect(valueOf('Client ID گوگل')).toBe(CLIENT_ID)
  })

  it('turns Google sign-in off with the field left blank', async () => {
    saves(SAVED)
    await show('/website-settings/google', websiteRow({ google: { client_id: CLIENT_ID } }))

    type('Client ID گوگل', '')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ google_client_id: '' }])
  })

  it('without an address, says to save one in «اتصال» first', async () => {
    await show('/website-settings/google', websiteRow({ enabled: false, url: null }))

    expect(screen.getByRole('link', { name: 'اتصال' }).getAttribute('href')).toBe('/website-settings/connection')
  })

  it('keeps a refusal under the field', async () => {
    const words = 'Client ID گوگل را همان‌طور که Google Cloud نشان می‌دهد کپی کنید؛ مثل 1234567890-abc123.apps.googleusercontent.com.'
    server().on('PATCH', URL, refusal(422, words, { google_client_id: [words] }))
    await show('/website-settings/google')

    type('Client ID گوگل', 'my-google-app')
    save()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText('Client ID گوگل').getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the email sign-up card', () => {
  it('switches sign-up on alone', async () => {
    saves(websiteRow({ email: { enabled: true, mail_ready: true } }))
    await show('/website-settings/email')

    fireEvent.click(screen.getByRole('switch', SIGNUP_SWITCH))
    fireEvent.click(card(SIGNUP).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ email_signup: true }])
    expect(screen.getByRole('switch', SIGNUP_SWITCH).getAttribute('aria-checked')).toBe('true')
  })

  it('says those with an email account keep their way in whatever it says', async () => {
    await show('/website-settings/email')

    expect(card(SIGNUP).getByText(/هر وضعیتی که این گزینه داشته باشد، با ایمیل و رمز عبورش وارد می‌شود/)).toBeTruthy()
  })

  it('is held while no email goes out — the owner sent to the panel’s email settings', async () => {
    await show('/website-settings/email', websiteRow({ email: { enabled: false, mail_ready: false } }), MAIL_SETTINGS)
    const signup = screen.getByRole('switch', SIGNUP_SWITCH)

    fireEvent.click(signup)

    expect(signup.getAttribute('aria-disabled')).toBe('true')
    expect(signup.getAttribute('aria-checked')).toBe('false')
    expect(card(SIGNUP).getByText(/تا راه نیفتد، این گزینه روشن نمی‌شود/)).toBeTruthy()
    expect(card(SIGNUP).getByRole('link', { name: 'تنظیمات پنل › ایمیل' }).getAttribute('href')).toBe(MAIL_SETTINGS)
    expect(screen.queryByText(/مالک فروشگاه راه می‌اندازد/)).toBeNull()
  })

  it('is held while no email goes out — an agent told the owner sets it up', async () => {
    await show('/website-settings/email', websiteRow({ email: { enabled: false, mail_ready: false } }))

    expect(screen.getByRole('switch', SIGNUP_SWITCH).getAttribute('aria-disabled')).toBe('true')
    expect(card(SIGNUP).getByText(/ارسال ایمیل را مالک فروشگاه راه می‌اندازد\./)).toBeTruthy()
    expect(card(SIGNUP).queryByRole('link')).toBeNull()
  })

  it('kept on while no email goes out, may still be switched off', async () => {
    saves(websiteRow({ email: { enabled: false, mail_ready: false } }))
    await show('/website-settings/email', websiteRow({ email: { enabled: true, mail_ready: false } }), MAIL_SETTINGS)
    const signup = screen.getByRole('switch', SIGNUP_SWITCH)
    expect(card(SIGNUP).getByText(/کسی نمی‌تواند با ایمیل ثبت‌نام کند/)).toBeTruthy()

    fireEvent.click(signup)
    fireEvent.click(card(SIGNUP).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ email_signup: false }])
  })

  it('says the server’s refusal under the switch', async () => {
    const words = 'ارسال ایمیل در این نصب راه نیفتاده است؛ مالک فروشگاه باید آن را در تنظیمات پنل › ایمیل راه بیندازد.'
    server().on('PATCH', URL, refusal(422, words, { email_signup: [words] }))
    await show('/website-settings/email')

    fireEvent.click(screen.getByRole('switch', SIGNUP_SWITCH))
    fireEvent.click(card(SIGNUP).getByRole('button', { name: 'ذخیره' }))

    expect(await card(SIGNUP).findByText(words)).toBeTruthy()
    expect(screen.getByRole('switch', SIGNUP_SWITCH).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the captcha card', () => {
  it('asks none at first, and draws the form of the captcha picked: Turnstile’s keys, ALTCHA’s nothing', async () => {
    await show('/website-settings/email')
    expect(screen.getByText(/فرم‌های وب‌سایت تایید امنیتی نمی‌خواهند/)).toBeTruthy()
    expect(screen.queryByLabelText(SITE_KEY)).toBeNull()

    pick(CAPTCHA_DRIVER, 'Cloudflare Turnstile')
    expect(screen.getByLabelText(SITE_KEY)).toBeTruthy()
    expect(screen.getByLabelText(SECRET_KEY)).toBeTruthy()
    expect(screen.getByText('ویجت تایید امنیتی Cloudflare.')).toBeTruthy()
    expect(card(CAPTCHA).getByText('password_reset')).toBeTruthy()

    pick(CAPTCHA_DRIVER, 'ALTCHA')
    expect(screen.queryByLabelText(SITE_KEY)).toBeNull()
    expect(screen.getByText('تایید امنیتی متن‌باز و بدون سرویس بیرونی.')).toBeTruthy()
  })

  it('saves Turnstile with its keys — its secret blank again once kept, a hint of it in its place', async () => {
    saves(websiteRow({ captcha: TURNSTILE }))
    await show('/website-settings/email')

    pick(CAPTCHA_DRIVER, 'Cloudflare Turnstile')
    type(SITE_KEY, '0x4AAAAAAA-site')
    type(SECRET_KEY, '0x4AAAAAAA-secret')
    fireEvent.click(card(CAPTCHA).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ captcha: { driver: 'turnstile', site_key: '0x4AAAAAAA-site', secret_key: '0x4AAAAAAA-secret', clear_secret_key: false } }])
    expect(valueOf(SECRET_KEY)).toBe('')
    expect((screen.getByLabelText(SECRET_KEY) as HTMLInputElement).placeholder).toBe('••••••cret')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
  })

  it('asks for Turnstile’s kept secret again once its key moved, as the server does — the blank one left out', async () => {
    server().on('PATCH', URL, refusal(422, MISMATCH, { 'captcha.site_key': [MISMATCH], 'captcha.secret_key': [MOVED] }))
    await show('/website-settings/email', websiteRow({ captcha: TURNSTILE }))
    expect(valueOf(SITE_KEY)).toBe('0x4AAAAAAA-site')
    expect(screen.queryByText(MOVED)).toBeNull()

    type(SITE_KEY, '0x4AAAAAAA-other')
    expect(screen.getByText(MOVED)).toBeTruthy()
    type(SITE_KEY, '0x4AAAAAAA-site')
    expect(screen.queryByText(MOVED)).toBeNull()

    type(SITE_KEY, 'کلید سایت')
    fireEvent.click(card(CAPTCHA).getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(MISMATCH)).toBeTruthy()
    expect(screen.getAllByText(MOVED)).toHaveLength(1)
    expect(screen.getByLabelText(SECRET_KEY).getAttribute('aria-invalid')).toBe('true')
    expect(sent()).toEqual([{ captcha: { driver: 'turnstile', site_key: 'کلید سایت', clear_secret_key: false } }])

    // Back where it started, the refusal goes whole: it was about a key no longer on screen.
    type(SITE_KEY, '0x4AAAAAAA-site')
    expect(screen.queryByText(MISMATCH)).toBeNull()
    expect(screen.queryByText(MOVED)).toBeNull()
    expect(screen.getByLabelText(SECRET_KEY).getAttribute('aria-invalid')).toBe('false')
  })

  it('switches to ALTCHA, which asks nothing more, and off — the driver alone sent', async () => {
    saves(websiteRow({ captcha: websiteCaptcha('altcha', TURNSTILE.values.turnstile) }))
    await show('/website-settings/email', websiteRow({ captcha: TURNSTILE }))

    pick(CAPTCHA_DRIVER, 'ALTCHA')
    fireEvent.click(card(CAPTCHA).getByRole('button', { name: 'ذخیره' }))
    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))

    saves(websiteRow())
    pick(CAPTCHA_DRIVER, 'خاموش')
    fireEvent.click(card(CAPTCHA).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(sent()).toHaveLength(2))
    expect(sent()).toEqual([{ captcha: { driver: 'altcha' } }, { captcha: { driver: 'none' } }])
  })

  it('says a refusal under its field until it is typed, and lets every one of them go with another captcha picked', async () => {
    server().on('PATCH', URL, refusal(422, SECRET_MISSING, { 'captcha.site_key': [SITE_KEY_MISSING], 'captcha.secret_key': [SECRET_MISSING] }))
    await show('/website-settings/email')

    pick(CAPTCHA_DRIVER, 'Cloudflare Turnstile')
    fireEvent.click(card(CAPTCHA).getByRole('button', { name: 'ذخیره' }))

    expect(await screen.findByText(SECRET_MISSING)).toBeTruthy()
    expect(screen.getAllByText(SECRET_MISSING)).toHaveLength(1)
    expect(sent()).toEqual([{ captcha: { driver: 'turnstile', site_key: '', clear_secret_key: false } }])
    expect(screen.getByLabelText(SITE_KEY).getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByLabelText(SECRET_KEY).getAttribute('aria-invalid')).toBe('true')

    type(SECRET_KEY, '0x4AAAAAAA-secret')
    expect(screen.queryByText(SECRET_MISSING)).toBeNull()
    expect(screen.getByLabelText(SITE_KEY).getAttribute('aria-invalid')).toBe('true')

    pick(CAPTCHA_DRIVER, 'ALTCHA')
    pick(CAPTCHA_DRIVER, 'Cloudflare Turnstile')
    expect(screen.queryByText(SITE_KEY_MISSING)).toBeNull()
    expect(screen.getByLabelText(SITE_KEY).getAttribute('aria-invalid')).toBe('false')
  })
})

const REVIEWS_SWITCH = { name: 'نظرات در وب‌سایت' }

describe('the reviews’ card', () => {
  it('switches the website’s reviews on, sending its switch alone', async () => {
    saves(websiteRow({ reviews: { enabled: true } }))
    await show('/website-settings/reviews')
    expect(screen.getByRole('switch', REVIEWS_SWITCH).getAttribute('aria-checked')).toBe('false')

    fireEvent.click(screen.getByRole('switch', REVIEWS_SWITCH))
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ reviews_enabled: true }])
    expect(screen.getByRole('switch', REVIEWS_SWITCH).getAttribute('aria-checked')).toBe('true')
  })

  it('leads to the reviews it shows — and, while no captcha guards the website, says a guest writes none', async () => {
    await show('/website-settings/reviews')

    expect(section('نظرات').getByRole('link', { name: 'نظرات' }).getAttribute('href')).toBe('/reviews')
    expect(section('نظرات').getByText(/فقط مشتری‌هایی که وارد حسابشان شده‌اند نظر می‌نویسند/)).toBeTruthy()
    expect(section('نظرات').getByRole('link', { name: 'ورود با ایمیل' }).getAttribute('href')).toBe('/website-settings/email')
  })

  it('behind a captcha, says guests write too', async () => {
    await show('/website-settings/reviews', websiteRow({ captcha: TURNSTILE }))

    expect(section('نظرات').getByText(/مهمان‌ها هم پشت تایید امنیتی وب‌سایت نظر می‌نویسند/)).toBeTruthy()
    expect(section('نظرات').queryByRole('link', { name: 'ورود با ایمیل' })).toBeNull()
  })
})

const LET_IN = { name: 'کار مدیران از وب‌سایت' }
const STRONG = { name: 'ورود امن مدیران' }

/** A grant's switch on the admins' card, by its words. */
const grant = (name: string) => screen.getByRole('switch', { name })

describe('the shop’s admins’ card', () => {
  it('lets them in, nothing granted at first and a strong sign-in asked', async () => {
    await show('/website-settings/staff')

    expect(screen.getByRole('switch', LET_IN).getAttribute('aria-checked')).toBe('false')
    expect(screen.getByRole('switch', STRONG).getAttribute('aria-checked')).toBe('true')
    for (const name of ['پلن‌ها و دسته‌بندی‌ها', 'کیف پول مشتری', 'بازپرداخت', 'افزایش زمان و حجم سرویس', 'حذف سرویس', 'امنیت حساب مشتری']) {
      expect(grant(name).getAttribute('aria-checked')).toBe('false')
    }
  })

  it('sends its own fields whole — the grants in their own order, whichever was switched first', async () => {
    saves(websiteRow({ staff: { enabled: true, strong_sign_in: true, grants: ['wallet', 'refunds'] } }))
    await show('/website-settings/staff')

    fireEvent.click(screen.getByRole('switch', LET_IN))
    fireEvent.click(grant('بازپرداخت'))
    fireEvent.click(grant('کیف پول مشتری'))
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ staff_enabled: true, staff_strong_sign_in: true, staff_grants: ['wallet', 'refunds'] }])
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(grant('بازپرداخت').getAttribute('aria-checked')).toBe('true')
  })

  it('takes a grant back, and lets a password alone do', async () => {
    saves(websiteRow({ staff: { enabled: true, strong_sign_in: false, grants: ['delete'] } }))
    await show('/website-settings/staff', websiteRow({ staff: { enabled: true, strong_sign_in: true, grants: ['catalog', 'delete'] } }))

    fireEvent.click(screen.getByRole('switch', STRONG))
    fireEvent.click(grant('پلن‌ها و دسته‌بندی‌ها'))
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ staff_enabled: true, staff_strong_sign_in: false, staff_grants: ['delete'] }])
  })

  it('is unchanged once a grant switched on is switched off again', async () => {
    await show('/website-settings/staff', websiteRow({ staff: { enabled: true, strong_sign_in: true, grants: ['extend'] } }))

    fireEvent.click(grant('حذف سرویس'))
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    fireEvent.click(grant('حذف سرویس'))

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
  })

  it('says who the admins are: the users list, narrowed to the role', async () => {
    await show('/website-settings/staff')

    expect(section('مدیران سایت').getByRole('link', { name: 'لیست کاربران' }).getAttribute('href')).toBe('/users?role=admin')
  })

  it('says how far the guards on them go: an admin’s own account alone — two can do for each other what each is refused', async () => {
    await show('/website-settings/staff')

    expect(section('مدیران سایت').getByText(/هر مدیر فقط درباره حساب خودش محدود است/).textContent).toContain(
      'دو مدیر این کارها را برای هم انجام می‌دهند؛ پس نقش مدیر را فقط به کسانی بدهید که به آن‌ها اعتماد دارید.',
    )
    expect(section('مدیران سایت').getByText(/ورود هر مدیر تا ۱۲ ساعت برای کار مدیریتی معتبر است/).textContent).toContain('تایید پرداخت هم، چون سرویس تحویل می‌دهد، ورود ۱۵ دقیقه گذشته را می‌خواهد.')
  })

  it('says the server’s refusal under the switch it is about', async () => {
    const words = 'مقدار این گزینه باید روشن یا خاموش باشد.'
    server().on('PATCH', URL, refusal(422, words, { staff_enabled: [words] }))
    await show('/website-settings/staff')

    fireEvent.click(screen.getByRole('switch', LET_IN))
    save()

    expect(await section('مدیران سایت').findByText(words)).toBeTruthy()
    expect(screen.getByRole('switch', LET_IN).getAttribute('aria-invalid')).toBe('true')
  })
})

describe('the cards', () => {
  it('save apart: another card’s save leaves a draft as it is', async () => {
    saves(websiteRow({ email: { enabled: true, mail_ready: true } }))
    await show('/website-settings/email')

    pick(CAPTCHA_DRIVER, 'Cloudflare Turnstile')
    type(SITE_KEY, '0x4AAAAAAA-site')
    fireEvent.click(screen.getByRole('switch', SIGNUP_SWITCH))
    fireEvent.click(card(SIGNUP).getByRole('button', { name: 'ذخیره' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith(SAVED_DONE))
    expect(sent()).toEqual([{ email_signup: true }])
    expect(valueOf(SITE_KEY)).toBe('0x4AAAAAAA-site')
  })
})

describe('the API’s address', () => {
  it('is copied whole', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined)
    await show('/website-settings/connection')

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'کپی' })))

    expect(write).toHaveBeenCalledWith(SAVED.base_url)
    expect(toast.success).toHaveBeenCalledWith('آدرس API کپی شد')
  })

  it('turns over with a new key once asked, and shows the new address', async () => {
    const turned = websiteRow({ key: 'fedcba9876543210fedcba98', base_url: 'https://shop.example/api/store/v1/fedcba9876543210fedcba98' })
    server().on('POST', KEY, json({ website: turned } satisfies WebsiteResponse))
    await show('/website-settings/connection')

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کلید جدید' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('آدرس API فعلی بلافاصله از کار می‌افتد و وب‌سایت باید آدرس جدید را بگیرد.')).toBeTruthy()
    expect(server().sent('POST', KEY)).toEqual([])

    fireEvent.click(within(dialog).getByRole('button', { name: 'ساخت کلید جدید' }))

    await until(() => expect(screen.getByText(turned.base_url)).toBeTruthy())
    expect(screen.queryByText(SAVED.base_url)).toBeNull()
    expect(screen.getByText(turned.key)).toBeTruthy()
    expect(toast.success).toHaveBeenCalledWith('کلید جدید ساخته شد؛ آدرس تازه را به وب‌سایت بدهید')
    expect(server().sent('POST', KEY)).toHaveLength(1)
    expect(server().sent('GET', URL)).toHaveLength(1)
  })

  it('stays as it is when the new key is not asked for after all', async () => {
    await show('/website-settings/connection')

    fireEvent.click(screen.getByRole('button', { name: 'ساخت کلید جدید' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'انصراف' }))

    expect(screen.getByText(SAVED.base_url)).toBeTruthy()
    expect(server().sent('POST', KEY)).toEqual([])
  })
})
