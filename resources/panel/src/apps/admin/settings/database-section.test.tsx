import { useQuery } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { DatabaseSection } from '@/apps/admin/settings/database-section'
import { api } from '@/lib/api'
import type { ConfigSettingsResponse, DatabaseCheckResponse, DatabaseSettings, DriverDescription, FieldDescription } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The database, «تنظیمات پنل › دیتابیس» (apps/admin/settings/database-section): the driver config.php names and its
 * form drawn from its description — another driver to switch to when more than one is on offer, its own form with it
 * —, tried before it is saved, and sent as the driver's own request; a password kept is asked for again, in the server's
 * words, once the address it was given for moved.
 */

const SAVE = '/api/admin/settings/config/database'
const TEST = '/api/admin/settings/config/database/test'

const MOVED = 'آدرس دیتابیس عوض شده است؛ رمز عبور را دوباره وارد کنید.'

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

/** The database drivers as the server describes them (App\Core\Database\Drivers). */
const MYSQL: DriverDescription = {
  key: 'mysql',
  label: 'MySQL / MariaDB',
  description: 'یک دیتابیس خالی بسازید و مشخصاتش را این‌جا بنویسید.',
  notes: ['MySQL 5.7.8 یا بالاتر، یا MariaDB 10.3 یا بالاتر'],
  traits: { installable: true },
  fields: [
    field('host', { label: 'هاست', default: 'localhost' }),
    field('port', { type: 'number', label: 'پورت', default: 3306, ltr: false }),
    field('database', { label: 'نام دیتابیس', default: 'amobot' }),
    field('username', { label: 'نام کاربری', default: 'root' }),
    field('password', { type: 'secret', label: 'رمز عبور', secret: true, required: false, ltr: false, default: null, bound_to: ['host', 'port', 'socket'], moved: MOVED }),
    field('socket', { type: 'path', label: 'سوکت', required: false, advanced: true, ltr: false }),
    field('prefix', { label: 'پیشوند جدول‌ها', required: false, advanced: true }),
  ],
}

const SQLITE: DriverDescription = {
  key: 'sqlite',
  label: 'SQLite',
  description: 'یک فایل در پوشه برنامه.',
  notes: ['SQLite 3.26 یا بالاتر'],
  traits: { installable: false },
  fields: [field('path', { type: 'path', label: 'فایل دیتابیس', default: 'storage/amobot.sqlite', ltr: false })],
}

const SETTINGS: DatabaseSettings = {
  driver: 'mysql',
  drivers: [MYSQL, SQLITE],
  values: { host: '127.0.0.1', port: 3306, database: 'shop', username: 'shop', password: { set: true, hint: '••••••••' }, socket: '', prefix: '' },
}

function configWith(database: DatabaseSettings): ConfigSettingsResponse {
  return {
    file: { path: 'config.php', exists: true, writable: true },
    groups: {
      app: { name: 'AmoBot', url: 'https://shop.example', debug: false, timezone: 'Asia/Tehran' },
      database,
      telegram: { token: { set: false, hint: '' }, username: '', api_url: 'https://api.telegram.org', poll_timeout: 25, webhook_secret: { set: false, hint: '' } },
      mail: { transport: 'none', from_address: '', from_name: '', drivers: [], values: {} },
      advanced: { log_level: 'info', session_lifetime: 120, session_secure_cookie: false, http_timeout: 30, cron_token: { set: false, hint: '' } },
    },
    meta: { timezones: [], log_levels: ['info'], cron_url: null, webhook_url: 'https://shop.example/webhooks/telegram/…' },
  }
}

/** The section as the owner's settings page draws it, fed by its read of config.php. */
function Database() {
  const { data } = useQuery({ queryKey: queryKeys.configSettings, queryFn: () => api.get<ConfigSettingsResponse>('/settings/config') })
  return data ? <DatabaseSection settings={data.groups.database} disabled={false} /> : null
}

async function show(settings: DatabaseSettings = SETTINGS) {
  server().on('GET', '/api/admin/settings/config', json(configWith(settings)))
  render(<Database />, { wrapper: providers().wrapper })
  await screen.findByRole('button', { name: 'ذخیره' })
}

const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the database', () => {
  it('shows the driver config.php names with its settings, and the drivers on offer', async () => {
    await show()

    expect(screen.getByRole('combobox', { name: 'نوع دیتابیس' }).textContent).toBe('MySQL / MariaDB')
    expect((screen.getByLabelText('هاست') as HTMLInputElement).value).toBe('127.0.0.1')
    expect((screen.getByLabelText('پورت') as HTMLInputElement).value).toBe('3306')
    expect((screen.getByLabelText(/^رمز عبور/) as HTMLInputElement).placeholder).toBe('••••••••')
    expect(screen.getByLabelText(/^سوکت/).closest('[hidden]')).not.toBeNull()
  })

  it('saves the driver’s own request — the password only when typed — once a database answers', async () => {
    server().on('PUT', SAVE, json(configWith(SETTINGS)))
    await show()

    type('نام دیتابیس', 'shop2')
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('اتصال دیتابیس ذخیره شد'))
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ driver: 'mysql', host: '127.0.0.1', port: '3306', database: 'shop2', username: 'shop', clear_password: false, socket: '', prefix: '' })
  })

  it('tries the settings typed before they are saved, and says what answered', async () => {
    server().on('POST', TEST, json({ database: { version: 'MariaDB 10.4.32', tables: 3 } } satisfies DatabaseCheckResponse))
    await show()

    type('هاست', 'db.internal')
    type(/^رمز عبور/, 'its own')
    fireEvent.click(screen.getByRole('button', { name: 'تست اتصال' }))

    expect(await screen.findByText('MariaDB 10.4.32')).toBeTruthy()
    expect(server().sent('POST', TEST)[0]?.body).toEqual({
      driver: 'mysql',
      host: 'db.internal',
      port: '3306',
      database: 'shop',
      username: 'shop',
      password: 'its own',
      clear_password: false,
      socket: '',
      prefix: '',
    })
  })

  it('switches to another driver with its own form, nothing of the last one sent', async () => {
    server().on('PUT', SAVE, json(configWith(SETTINGS)))
    await show()

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'نوع دیتابیس' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'SQLite' }))
    expect(screen.queryByLabelText('هاست')).toBeNull()
    expect((screen.getByLabelText('فایل دیتابیس') as HTMLInputElement).value).toBe('storage/amobot.sqlite')
    save()

    await until(() => expect(toast.success).toHaveBeenCalled())
    expect(server().sent('PUT', SAVE)[0]?.body).toEqual({ driver: 'sqlite', path: 'storage/amobot.sqlite' })
  })

  it('has nothing unsaved once the driver saved is picked again — what is left of another driver’s form is not sent', async () => {
    await show()

    fireEvent.keyDown(screen.getByRole('combobox', { name: 'نوع دیتابیس' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'SQLite' }))
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    fireEvent.keyDown(screen.getByRole('combobox', { name: 'نوع دیتابیس' }), { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'MySQL / MariaDB' }))

    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(screen.getByRole('button', { name: 'بازگردانی تغییرات' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('asks for the password again once the address it was kept for moved, as the server does', async () => {
    server().on('PUT', SAVE, refusal(422, MOVED, { password: [MOVED] }))
    await show()

    type('هاست', 'db.elsewhere.example')
    expect(screen.getByText(MOVED)).toBeTruthy()
    type('هاست', '127.0.0.1')
    type('پورت', '۳۳۰۶')
    expect(screen.queryByText(MOVED)).toBeNull()
    type('پورت', '3307')
    expect(screen.getByText(MOVED)).toBeTruthy()

    save()

    await until(() => expect(screen.getByLabelText(/^رمز عبور/).getAttribute('aria-invalid')).toBe('true'))
  })
})
