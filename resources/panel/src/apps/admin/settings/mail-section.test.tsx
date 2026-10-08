import { useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MailSection, MailTestCard } from '@/apps/admin/settings/mail-section'
import { api } from '@/lib/api'
import type { ConfigMailSettings, ConfigSettingsResponse, DriverDescription, FieldDescription, MailTestResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { websiteRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The shop's email, «تنظیمات پنل › ایمیل» (apps/admin/settings/mail-section): the way out — none, or a mail driver whose
 * form is drawn from its description —, and who the emails come from while any goes out; saved as the chosen driver's
 * own request, what the other drivers keep left unsent; a password kept is asked for again, in the server's words, once
 * its server, the connection's encryption or its account moved. Beside it, a test email by the settings as saved: a
 * word once it went, the address refused under it, any other failure in its words.
 */

const SAVE = '/api/admin/settings/config/mail'
const TEST = '/api/admin/settings/config/mail/test'

/** The words the server says of a password kept for an account that moved (Smtp::PASSWORD_MOVED). */
const MOVED = 'سرور، رمزنگاری اتصال یا نام کاربری SMTP عوض شده است؛ رمز SMTP را دوباره وارد کنید.'

function field(name: string, overrides: Partial<FieldDescription>): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: false,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: false,
    default: '',
    ...overrides,
  }
}

/** The mail drivers as the server describes them (App\Core\Mail\Drivers). */
const DRIVERS: DriverDescription[] = [
  {
    key: 'smtp',
    label: 'SMTP',
    description: 'یک حساب ایمیل روی یک سرور ایمیل؛ مثلا یکی از Email Accountهای cPanel هاست.',
    notes: [],
    traits: { sender: 'یکی از حساب‌های همان سرور ایمیل؛ سرور از حساب‌های دیگر نمی‌فرستد.' },
    fields: [
      field('host', { label: 'سرور SMTP', required: true, ltr: true, hint: 'بدون http:// و بدون پورت.', placeholder: 'mail.example.com' }),
      field('port', { type: 'number', label: 'پورت', required: true, default: 587, min: 1, max: 65535 }),
      field('encryption', {
        type: 'choice',
        label: 'رمزنگاری اتصال',
        required: true,
        default: 'tls',
        options: [
          { value: 'tls', label: 'STARTTLS (پیشنهادی)' },
          { value: 'ssl', label: 'SSL' },
          { value: 'none', label: 'بدون رمزنگاری' },
        ],
      }),
      field('username', { label: 'نام کاربری', ltr: true }),
      field('password', { type: 'secret', label: 'رمز عبور', secret: true, default: null, bound_to: ['host', 'port', 'encryption', 'username'], moved: MOVED, hint: 'رمز همان حساب ایمیل.' }),
    ],
  },
  { key: 'native', label: 'ایمیل خود هاست', description: 'ایمیل خود هاست، همان که PHP با sendmail_path در php.ini می‌فرستد.', notes: [], traits: { sender: 'آدرسی روی دامنه خود هاست.' }, fields: [] },
  {
    key: 'resend',
    label: 'Resend',
    description: 'سرویس ارسال ایمیل Resend.',
    notes: [],
    traits: { sender: 'آدرسی روی دامنه‌ای که در Resend › Domains تایید کرده‌اید.' },
    fields: [field('resend_key', { type: 'secret', label: 'کلید API', secret: true, required: true, default: null })],
  },
]

/** An SMTP account, as config.php holds it. */
const SMTP: ConfigMailSettings = {
  transport: 'smtp',
  from_address: 'no-reply@example.com',
  from_name: '',
  drivers: DRIVERS,
  values: {
    smtp: { host: 'mail.example.com', port: 587, encryption: 'tls', username: 'no-reply@example.com', password: { set: true, hint: '••••••••' } },
    native: {},
    resend: { resend_key: { set: false, hint: '' } },
  },
}

/** config.php as the server answers it, its email `mail`. */
function configWith(mail: ConfigMailSettings): ConfigSettingsResponse {
  return {
    file: { path: '/home/shop/amobot/config.php', exists: true, writable: true },
    groups: {
      app: { name: 'AmoBot', url: 'https://shop.example', debug: false, timezone: 'Asia/Tehran' },
      database: { driver: 'mysql', drivers: [], values: {} },
      telegram: { token: { set: true, hint: '1234…wxyz' }, username: 'amo_bot', api_url: 'https://api.telegram.org', poll_timeout: 25, webhook_secret: { set: false, hint: '' } },
      mail,
      advanced: { log_level: 'info', session_lifetime: 120, session_secure_cookie: false, http_timeout: 30, cron_token: { set: false, hint: '' } },
    },
    meta: { timezones: [], log_levels: ['info'], cron_url: null, webhook_url: 'https://shop.example/webhooks/telegram/…' },
  }
}

/** The section and the test card as the owner's settings page draws them: fed by its read of config.php, which a save answers in place of. */
function MailSettings() {
  const { data } = useQuery({ queryKey: queryKeys.configSettings, queryFn: () => api.get<ConfigSettingsResponse>('/settings/config') })

  return data ? (
    <>
      <MailSection settings={data.groups.mail} disabled={false} />
      <MailTestCard />
    </>
  ) : null
}

/** The section, config.php's email read as `settings`; resolved, once it is on screen, to the panel's cache. */
async function show(settings: ConfigMailSettings = SMTP) {
  server().on('GET', '/api/admin/settings/config', json(configWith(settings)))
  const { client, wrapper } = providers()
  render(<MailSettings />, { wrapper })
  await screen.findByRole('button', { name: 'ذخیره' })
  return client
}

/** The option `option` picked in the select labelled `label`. */
function pick(label: string, option: string) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: option }))
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const sendTest = () => fireEvent.click(screen.getByRole('button', { name: 'ارسال' }))

/* The optional fields: their labels carry «(اختیاری)» after the name. */
const USERNAME = /^نام کاربری/
const PASSWORD = /^رمز عبور/
const FROM_NAME = /^نام فرستنده/

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the shop’s email', () => {
  it('shows the form of the way out: nothing with none, the driver’s fields and who it comes from with any', async () => {
    await show({ ...SMTP, transport: 'none' })
    expect(screen.queryByLabelText('سرور SMTP')).toBeNull()
    expect(screen.queryByLabelText('ایمیل فرستنده')).toBeNull()

    pick('روش ارسال', 'SMTP')
    for (const label of ['سرور SMTP', 'پورت', USERNAME, PASSWORD, 'ایمیل فرستنده', FROM_NAME]) expect(screen.getByLabelText(label)).toBeTruthy()
    expect(screen.getByRole('radio', { name: 'STARTTLS (پیشنهادی)' }).getAttribute('aria-checked')).toBe('true')
    expect((screen.getByLabelText('سرور SMTP') as HTMLInputElement).value).toBe('mail.example.com')
    expect(screen.getByText('یکی از حساب‌های همان سرور ایمیل؛ سرور از حساب‌های دیگر نمی‌فرستد.')).toBeTruthy()

    pick('روش ارسال', 'ایمیل خود هاست')
    expect(screen.queryByLabelText('سرور SMTP')).toBeNull()
    expect(screen.queryByRole('radiogroup', { name: 'رمزنگاری اتصال' })).toBeNull()
    expect(screen.getByLabelText('ایمیل فرستنده')).toBeTruthy()
    expect(screen.getByText('آدرسی روی دامنه خود هاست.')).toBeTruthy()
  })

  it('saves an SMTP account as typed — the password with it, and blank again once kept —, the website’s settings read again', async () => {
    server().on('PUT', SAVE, json(configWith({ ...SMTP, values: { ...SMTP.values, smtp: { ...SMTP.values.smtp, host: 'smtp.example.com', port: 465, encryption: 'ssl' } } })))
    const client = await show()
    client.setQueryData(queryKeys.website, { website: websiteRow({ email: { enabled: false, mail_ready: false } }) })

    type('سرور SMTP', 'smtp.example.com')
    type('پورت', '۴۶۵')
    fireEvent.click(screen.getByRole('radio', { name: 'SSL' }))
    type(PASSWORD, 'smtp p@ss/1')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('تنظیمات ایمیل ذخیره شد'))
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({
      transport: 'smtp',
      host: 'smtp.example.com',
      port: '۴۶۵',
      encryption: 'ssl',
      username: 'no-reply@example.com',
      password: 'smtp p@ss/1',
      clear_password: false,
      from_address: 'no-reply@example.com',
      from_name: '',
    })
    expect((screen.getByLabelText(PASSWORD) as HTMLInputElement).value).toBe('')
    expect((screen.getByLabelText('پورت') as HTMLInputElement).value).toBe('465')
    // Whether email goes out is what the website's email sign-up waits for.
    expect(client.getQueryState(queryKeys.website)?.isInvalidated).toBe(true)
  })

  it('saves Resend with its key — blank again once kept — and who it comes from', async () => {
    server().on(
      'PUT',
      SAVE,
      json(configWith({ ...SMTP, transport: 'resend', from_address: 'no-reply@shop.example', values: { ...SMTP.values, resend: { resend_key: { set: true, hint: '••••••cdef' } } } })),
    )
    await show({ ...SMTP, transport: 'none' })
    expect(screen.queryByLabelText('کلید API')).toBeNull()

    pick('روش ارسال', 'Resend')
    expect(screen.queryByLabelText('سرور SMTP')).toBeNull()
    expect(screen.getByText('آدرسی روی دامنه‌ای که در Resend › Domains تایید کرده‌اید.')).toBeTruthy()
    type('کلید API', 're_test_1234567890abcdef')
    type('ایمیل فرستنده', 'no-reply@shop.example')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('تنظیمات ایمیل ذخیره شد'))
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ transport: 'resend', resend_key: 're_test_1234567890abcdef', clear_resend_key: false, from_address: 'no-reply@shop.example', from_name: '' })
    expect((screen.getByLabelText('کلید API') as HTMLInputElement).value).toBe('')
  })

  it('sends the way out chosen alone: what another driver keeps is left as it is — the password kept, a half-typed server unsent', async () => {
    server().on('PUT', SAVE, json(configWith({ ...SMTP, transport: 'native', from_address: 'shop@example.com' })))
    await show()

    type('سرور SMTP', 'https://half typed')
    pick('روش ارسال', 'ایمیل خود هاست')
    type('ایمیل فرستنده', 'shop@example.com')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('تنظیمات ایمیل ذخیره شد'))
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ transport: 'native', from_address: 'shop@example.com', from_name: '' })
  })

  it('switched off, sends the way out alone, and says what no email means', async () => {
    server().on('PUT', SAVE, json(configWith({ ...SMTP, transport: 'none' })))
    await show()

    pick('روش ارسال', 'خاموش')
    expect(screen.getByText(/مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند اعلان‌های فروشگاه را با ایمیل نمی‌گیرند/)).toBeTruthy()
    expect(screen.getByText(/و اعلان‌های فروشگاه به مشتری‌هایی که تلگرام ندارند یا ربات را بسته‌اند، با این تنظیمات فرستاده می‌شوند/)).toBeTruthy()
    save()

    await until(() => expect(toast.success).toHaveBeenCalled())
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ transport: 'none' })
  })

  it('asks for the password again while the server, its encryption or the account it was kept for moved, as the server does', async () => {
    server().on('PUT', SAVE, refusal(422, MOVED, { password: [MOVED] }))
    await show()
    expect(screen.queryByText(MOVED)).toBeNull()

    type('سرور SMTP', 'smtp.elsewhere.example')
    expect(screen.getByText(MOVED)).toBeTruthy()
    type('سرور SMTP', 'mail.example.com')
    type('پورت', '۵۸۷')
    expect(screen.queryByText(MOVED)).toBeNull()
    fireEvent.click(screen.getByRole('radio', { name: 'بدون رمزنگاری' }))
    expect(screen.getByText(MOVED)).toBeTruthy()
    fireEvent.click(screen.getByRole('radio', { name: 'STARTTLS (پیشنهادی)' }))
    expect(screen.queryByText(MOVED)).toBeNull()
    type(USERNAME, 'other@example.com')
    expect(screen.getByText(MOVED)).toBeTruthy()
    type(PASSWORD, 'the new one')
    expect(screen.queryByText(MOVED)).toBeNull()
    type(PASSWORD, '')

    save()

    await until(() => expect(screen.getByLabelText(PASSWORD).getAttribute('aria-invalid')).toBe('true'))
    expect(screen.getAllByText(MOVED)).toHaveLength(1)
  })

  it('has nothing unsaved once the way out saved is picked again — what it would send is what is kept', async () => {
    await show()

    pick('روش ارسال', 'Resend')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    pick('روش ارسال', 'SMTP')

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('keeps a password manager from filling the panel’s own sign-in into the password it keeps', async () => {
    await show()

    expect(screen.getByLabelText(PASSWORD).getAttribute('autocomplete')).toBe('new-password')
  })

  it('keeps a refusal under the field it is about', async () => {
    const words = 'سرور SMTP را مثل mail.example.com بنویسید؛ بدون http:// و بدون پورت.'
    server().on('PUT', SAVE, refusal(422, words, { host: [words] }))
    await show()

    type('سرور SMTP', 'https://mail.example.com:587')
    save()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText('سرور SMTP').getAttribute('aria-invalid')).toBe('true')
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the test email', () => {
  it('goes to the address typed, and says so', async () => {
    server().on('POST', TEST, json({ sent: true } satisfies MailTestResponse))
    await show()

    type('ایمیل گیرنده', 'owner@example.com')
    sendTest()

    await until(() => expect(toast.success).toHaveBeenCalledWith('ایمیل تست فرستاده شد؛ صندوق ورودی و پوشه Spam را نگاه کنید'))
    expect(server().sent('POST', TEST)[0]?.body).toEqual({ to: 'owner@example.com' })
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says why it did not go, in the server’s words, until a send goes', async () => {
    const words = 'ایمیل فرستاده نشد: Expected response code "250" but got code "550", with message "550 Relaying denied".'
    server().on('POST', TEST, refusal(502, words))
    await show()

    type('ایمیل گیرنده', 'owner@example.com')
    sendTest()

    await until(() => expect(screen.getByRole('alert').textContent).toContain(words))
    expect(toast.success).not.toHaveBeenCalled()

    server().on('POST', TEST, json({ sent: true } satisfies MailTestResponse))
    type('ایمیل گیرنده', 'other@example.com')
    sendTest()

    await until(() => expect(toast.success).toHaveBeenCalled())
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says so while no email goes out yet', async () => {
    const words = 'ارسال ایمیل هنوز راه نیفتاده است؛ اول تنظیمات را ذخیره کنید.'
    server().on('POST', TEST, refusal(422, words))
    await show({ ...SMTP, transport: 'none' })

    type('ایمیل گیرنده', 'owner@example.com')
    sendTest()

    await until(() => expect(screen.getByRole('alert').textContent).toContain(words))
  })

  it('says a refused address under it, once', async () => {
    const words = 'ایمیل گیرنده درست نیست؛ آن را مثل name@example.com بنویسید.'
    server().on('POST', TEST, refusal(422, words, { to: [words] }))
    await show()

    type('ایمیل گیرنده', 'owner')
    sendTest()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getAllByText(words)).toHaveLength(1)
    expect(screen.getByLabelText('ایمیل گیرنده').getAttribute('aria-invalid')).toBe('true')
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
