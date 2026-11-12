import { useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SmsSection, SmsTestCard } from '@/apps/admin/settings/sms-section'
import { api } from '@/lib/api'
import type { ConfigSettingsResponse, ConfigSmsSettings, DriverDescription, FieldDescription, SmsCreditResponse, SmsTestResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { websiteRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The shop's SMS, «تنظیمات پنل › پیامک» (apps/admin/settings/sms-section): the way codes go out — none, or an SMS
 * driver whose form is drawn from its description — and, while any goes, whether agents' websites may send codes too;
 * saved as the chosen driver's own request, what the other drivers keep left unsent; a key kept is asked for again, in
 * the server's words, once the API's address moved. Beside it, a test code by the settings as saved — the code shown
 * once it went, the number refused under it, any other failure in the server's message — and the account's credit while
 * a driver is saved.
 */

const SAVE = '/api/admin/settings/config/sms'
const TEST = '/api/admin/settings/config/sms/test'
const CREDIT = '/api/admin/settings/config/sms/credit'

/** The words the server says of a key kept for an address that moved (IpPanel::KEY_MOVED). */
const MOVED = 'آدرس API عوض شده است؛ کلید دسترسی IPPanel را دوباره وارد کنید: کلید ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.'

const AGENTS = 'سایت نماینده‌ها هم بتوانند کد ورود پیامکی بفرستند'

function field(name: string, overrides: Partial<FieldDescription>): FieldDescription {
  return {
    name,
    type: 'text',
    label: name,
    hint: null,
    placeholder: null,
    required: true,
    secret: false,
    bound_to: [],
    moved: null,
    advanced: false,
    options: [],
    when: {},
    unit: null,
    min: null,
    max: null,
    ltr: true,
    default: '',
    ...overrides,
  }
}

/** The SMS drivers as the server describes them (App\Core\Sms\Drivers). */
const DRIVERS: DriverDescription[] = [
  {
    key: 'ippanel',
    label: 'IPPanel (ای‌پی پنل)',
    description: 'سامانه پیامک IPPanel: کد ورود با یک الگوی (پترن) تاییدشده در پنل IPPanel فرستاده می‌شود.',
    notes: ['الگو باید در پنل IPPanel تایید شده باشد و فقط یک متغیر، برای کد ورود، داشته باشد.'],
    traits: {},
    fields: [
      field('api_key', { type: 'secret', label: 'کلید دسترسی', secret: true, default: null, ltr: false, bound_to: ['api_url'], moved: MOVED }),
      field('from', { label: 'شماره فرستنده', placeholder: '+983000505' }),
      field('pattern', { label: 'کد الگو' }),
      field('variable', { label: 'نام متغیر کد', default: 'code' }),
      field('api_url', { type: 'url', label: 'آدرس API', advanced: true, default: 'https://edge.ippanel.com/v1' }),
    ],
  },
]

/** IPPanel set up, as config.php holds it. */
const IPPANEL: ConfigSmsSettings = {
  driver: 'ippanel',
  agents: false,
  drivers: DRIVERS,
  values: {
    ippanel: { api_key: { set: true, hint: '••••••Zg==' }, from: '+983000505', pattern: 'jhaskdab45s6f4sfw', variable: 'code', api_url: 'https://edge.ippanel.com/v1' },
  },
}

/** Nothing set up yet: no driver, IPPanel's form at its defaults. */
const OFF: ConfigSmsSettings = {
  ...IPPANEL,
  driver: 'none',
  values: { ippanel: { api_key: { set: false, hint: '' }, from: '', pattern: '', variable: 'code', api_url: 'https://edge.ippanel.com/v1' } },
}

/** config.php as the server answers it, its SMS `sms`. */
function configWith(sms: ConfigSmsSettings): ConfigSettingsResponse {
  return {
    file: { path: '/home/shop/amobot/config.php', exists: true, writable: true },
    groups: {
      app: { name: 'AmoBot', url: 'https://shop.example', debug: false, timezone: 'Asia/Tehran' },
      database: { driver: 'mysql', drivers: [], values: {} },
      telegram: { token: { set: true, hint: '1234…wxyz' }, username: 'amo_bot', api_url: 'https://api.telegram.org', poll_timeout: 25, webhook_secret: { set: false, hint: '' } },
      mail: { transport: 'none', from_address: '', from_name: '', drivers: [], values: {} },
      sms,
      advanced: { log_level: 'info', session_lifetime: 120, session_secure_cookie: false, http_timeout: 30, cron_token: { set: false, hint: '' } },
    },
    meta: { timezones: [], log_levels: ['info'], cron_url: null, webhook_url: 'https://shop.example/webhooks/telegram/…' },
  }
}

/** The section and the test card as the owner's settings page draws them: fed by its read of config.php, which a save answers in place of. */
function SmsSettings() {
  const { data } = useQuery({ queryKey: queryKeys.configSettings, queryFn: () => api.get<ConfigSettingsResponse>('/settings/config') })

  return data ? (
    <>
      <SmsSection settings={data.groups.sms} disabled={false} />
      <SmsTestCard driver={data.groups.sms.driver} />
    </>
  ) : null
}

const CREDIT_ANSWER: SmsCreditResponse = { credit: { amount: 6084722932, unit: 'ریال' } }

/** The section, config.php's SMS read as `settings`; resolved, once it is on screen, to the panel's cache. */
async function show(settings: ConfigSmsSettings = IPPANEL) {
  server()
    .on('GET', '/api/admin/settings/config', json(configWith(settings)))
    .on('GET', CREDIT, json(CREDIT_ANSWER))
  const { client, wrapper } = providers()
  render(<SmsSettings />, { wrapper })
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

/* The key's label carries «(اختیاری)» once kept; the address sits under «تنظیمات پیشرفته». */
const KEY = /^کلید دسترسی/

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the shop’s SMS', () => {
  it('shows the form of the way out: nothing with none, the driver’s fields and the agents’ switch with one', async () => {
    await show(OFF)
    expect(screen.queryByLabelText('شماره فرستنده')).toBeNull()
    expect(screen.queryByText(AGENTS)).toBeNull()
    expect(screen.getByText('پیامکی فرستاده نمی‌شود: ورود با شماره موبایل در وب‌سایت‌ها روشن نمی‌شود.')).toBeTruthy()

    pick('روش ارسال', 'IPPanel (ای‌پی پنل)')
    for (const label of [KEY, 'شماره فرستنده', 'کد الگو', 'نام متغیر کد']) expect(screen.getByLabelText(label)).toBeTruthy()
    expect((screen.getByLabelText('نام متغیر کد') as HTMLInputElement).value).toBe('code')
    expect(screen.getByText(AGENTS)).toBeTruthy()
    expect(screen.getByText('هزینه هر پیامک، از وب‌سایت هر فروشگاهی که باشد، از اعتبار همین حساب پیامک شما کم می‌شود.')).toBeTruthy()
    expect(screen.getByText(/الگو باید در پنل IPPanel تایید شده باشد/)).toBeTruthy()
  })

  it('saves IPPanel as typed — the key with it, blank again once kept — and the agents’ switch, the website and the credit read again', async () => {
    server().on('PUT', SAVE, json(configWith({ ...IPPANEL, agents: true })))
    const client = await show(OFF)
    client.setQueryData(queryKeys.website, { website: websiteRow() })

    pick('روش ارسال', 'IPPanel (ای‌پی پنل)')
    type(KEY, 'OWVhN2Q5ZjEtYmM0Yy00ZjRhLTk2ZTMtNDg2Zg==')
    type('شماره فرستنده', '3000505')
    type('کد الگو', 'jhaskdab45s6f4sfw')
    fireEvent.click(screen.getByRole('switch', { name: new RegExp(AGENTS) }))
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('تنظیمات پیامک ذخیره شد'))
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({
      driver: 'ippanel',
      api_key: 'OWVhN2Q5ZjEtYmM0Yy00ZjRhLTk2ZTMtNDg2Zg==',
      clear_api_key: false,
      from: '3000505',
      pattern: 'jhaskdab45s6f4sfw',
      variable: 'code',
      api_url: 'https://edge.ippanel.com/v1',
      agents: true,
    })
    expect((screen.getByLabelText(KEY) as HTMLInputElement).value).toBe('')
    expect((screen.getByLabelText('شماره فرستنده') as HTMLInputElement).value).toBe('+983000505')
    expect(client.getQueryState(queryKeys.website)?.isInvalidated).toBe(true)
    // Saved with a driver, the test card reads the account's credit.
    expect(await screen.findByText(`${formatNumber(6084722932)} ریال`)).toBeTruthy()
  })

  it('switched off, sends the way out alone — what IPPanel keeps left as it is — and says what no SMS means', async () => {
    server().on('PUT', SAVE, json(configWith({ ...IPPANEL, driver: 'none' })))
    await show()

    type('کد الگو', 'half typed')
    pick('روش ارسال', 'خاموش')
    expect(screen.getByText('پیامکی فرستاده نمی‌شود: ورود با شماره موبایل در وب‌سایت‌ها روشن نمی‌شود.')).toBeTruthy()
    save()

    await until(() => expect(toast.success).toHaveBeenCalled())
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ driver: 'none' })
  })

  it('asks for the key again while the API’s address it was kept for moved, as the server does', async () => {
    server().on('PUT', SAVE, refusal(422, MOVED, { api_key: [MOVED] }))
    await show()
    expect(screen.queryByText(MOVED)).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'تنظیمات پیشرفته' }))
    type('آدرس API', 'https://sms.elsewhere.example/v1')
    expect(screen.getByText(MOVED)).toBeTruthy()
    type('آدرس API', 'https://edge.ippanel.com/v2')
    expect(screen.queryByText(MOVED)).toBeNull()
    type('آدرس API', 'https://sms.elsewhere.example/v1')
    save()

    await until(() => expect(screen.getByLabelText(KEY).getAttribute('aria-invalid')).toBe('true'))
    expect(screen.getAllByText(MOVED)).toHaveLength(1)
  })

  it('has nothing unsaved once the way out saved is picked again — what it would send is what is kept', async () => {
    await show()

    pick('روش ارسال', 'خاموش')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    pick('روش ارسال', 'IPPanel (ای‌پی پنل)')

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'ذخیره' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('keeps a refusal under the field it is about', async () => {
    const words = 'شماره فرستنده را مثل 3000505 یا +983000505 بنویسید؛ فقط عدد، با + یا بی آن.'
    server().on('PUT', SAVE, refusal(422, words, { from: [words] }))
    await show()

    type('شماره فرستنده', 'خط من')
    save()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getByLabelText('شماره فرستنده').getAttribute('aria-invalid')).toBe('true')
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the test code and the credit', () => {
  it('goes to the number typed, and shows the code sent — the credit read again', async () => {
    server().on('POST', TEST, json({ sent: true, code: '482913' } satisfies SmsTestResponse))
    await show()
    expect(await screen.findByText(`${formatNumber(6084722932)} ریال`)).toBeTruthy()
    server().on('GET', CREDIT, json({ credit: { amount: 6084700000, unit: 'ریال' } } satisfies SmsCreditResponse))

    type('شماره موبایل گیرنده', '09121234567')
    sendTest()

    expect((await screen.findByRole('status')).textContent).toContain('کد آزمایشی 482913 فرستاده شد')
    expect(server().sent('POST', TEST)[0]?.body).toEqual({ to: '09121234567' })
    expect(screen.queryByRole('alert')).toBeNull()
    expect(await screen.findByText(`${formatNumber(6084700000)} ریال`)).toBeTruthy()
  })

  it('shows why it was not sent, with the server’s message, until a send succeeds', async () => {
    const words = 'پیامک فرستاده نشد: IPPanel نپذیرفت: اعتبار شما کافی نیست'
    server().on('POST', TEST, refusal(502, words))
    await show()

    type('شماره موبایل گیرنده', '09121234567')
    sendTest()

    await until(() => expect(screen.getByRole('alert').textContent).toContain(words))

    server().on('POST', TEST, json({ sent: true, code: '111222' } satisfies SmsTestResponse))
    sendTest()

    expect((await screen.findByRole('status')).textContent).toContain('111222')
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says a refused number under it, once', async () => {
    const words = 'شماره موبایل گیرنده درست نیست؛ آن را مثل 09121234567 یا +989121234567 بنویسید.'
    server().on('POST', TEST, refusal(422, words, { to: [words] }))
    await show()

    type('شماره موبایل گیرنده', '0912')
    sendTest()

    expect(await screen.findByText(words)).toBeTruthy()
    expect(screen.getAllByText(words)).toHaveLength(1)
    expect(screen.getByLabelText('شماره موبایل گیرنده').getAttribute('aria-invalid')).toBe('true')
  })

  it('reads no credit while no driver is saved', async () => {
    await show(OFF)
    expect(screen.queryByText(/اعتبار حساب:/)).toBeNull()
    expect(server().sent('GET', CREDIT)).toHaveLength(0)
  })

  it('has no credit line for a driver that tells none', async () => {
    server()
      .on('GET', '/api/admin/settings/config', json(configWith(IPPANEL)))
      .on('GET', CREDIT, json({ credit: null } satisfies SmsCreditResponse))
    const { wrapper } = providers()
    render(<SmsSettings />, { wrapper })

    await until(() => expect(server().sent('GET', CREDIT)).toHaveLength(1))
    await until(() => expect(screen.queryByText(/اعتبار حساب:/)).toBeNull())
    expect(screen.queryByRole('button', { name: 'خواندن دوباره اعتبار' })).toBeNull()
  })

  it('says the provider’s words when the credit could not be read, and reads it again on demand', async () => {
    const words = 'اعتبار حساب پیامک خوانده نشد: IPPanel کلید دسترسی را نپذیرفت (اطلاعات وارد شده صحیح نمی باشد)'
    server()
      .on('GET', '/api/admin/settings/config', json(configWith(IPPANEL)))
      .on('GET', CREDIT, refusal(502, words))
    const { wrapper } = providers()
    render(<SmsSettings />, { wrapper })

    expect(await screen.findByText(words)).toBeTruthy()
    server().on('GET', CREDIT, json(CREDIT_ANSWER))
    fireEvent.click(screen.getByRole('button', { name: 'خواندن دوباره اعتبار' }))
    expect(await screen.findByText(`${formatNumber(6084722932)} ریال`)).toBeTruthy()
  })
})
