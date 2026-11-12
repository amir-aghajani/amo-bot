import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { BackupsSection } from '@/components/backups/backups-section'
import type { BackupInspection, BackupInspectionResponse, BackupRestoreResponse, BackupsResponse, BackupsStatus } from '@/lib/api-types'
import { ThemeProvider } from '@/lib/theme'
import { AGENT_SHOP, OWNER, providers, signedIn, until } from '@/test/render'
import { json, refusal, server, withHeaders, type Answer } from '@/test/server'

/*
 * «تنظیمات پنل › پشتیبان‌گیری» (components/backups), as the owner works it: in the main shop of the whole installation,
 * in an agent's shop of that shop alone — the server says which, the header says it from the session. A backup made now
 * is saved by the server's name for it, the button spinning meanwhile; the report group's switch is saved as it is
 * pressed, with how often the backup goes (set on «زمان‌بندی کارها», a link away), when it last went or why not, and the
 * group it needs. A file picked is held to the host's limit, checked by the server and described — its tables, what a
 * restore leaves out, what it loses —, restored only after the admin's yes, or dropped; the state kept from before is
 * saved like any backup. Read only while the section is on screen, with none of config.php's words over it.
 */

const URL_ = '/api/admin/backups'
const MB = 1024 ** 2

/** Now, as the fixtures count from it. */
const NOW = Date.now()

/** A moment `minutes` from now (before it, below zero). */
const at = (minutes: number) => new Date(NOW + minutes * 60_000).toISOString()

const KEPT = 'amobot-installation-20261110-093000.json.gz'

/** The owner's status in the main shop: sent to the group a day apart, two hours ago; one state kept; 64 MB uploads. */
function status(overrides: Partial<BackupsStatus> = {}, auto: Partial<BackupsStatus['auto']> = {}): BackupsStatus {
  return {
    scope: 'installation',
    shop: null,
    auto: { enabled: true, group: true, every_minutes: 1440, last_sent_at: at(-120), last_problem: null, ...auto },
    kept: [{ name: KEPT, made_at: at(-3 * 1440), size: 3 * MB }],
    upload_max_bytes: 64 * MB,
    ...overrides,
  }
}

/** A file of the whole installation, made three days ago by 0.7.2, with a table the panel has no name for (as a later version's would be). */
const INSPECTION: BackupInspection = {
  token: 'f3c1a9',
  scope: 'installation',
  shop: null,
  made_at: at(-3 * 1440),
  app_version: '0.7.2',
  tables: [
    { table: 'users', rows: 1200 },
    { table: 'subscriptions', rows: 840 },
    { table: 'device_limits', rows: 9 },
  ],
  rows: 2049,
  warnings: ['پلن «طلایی» روی سروری بود که دیگر نیست؛ بدون آن سرور برمی‌گردد.'],
  expires_at: at(30),
}

const RESTORED: BackupRestoreResponse = { restore: { rows: { users: 1200, subscriptions: 840, report_topics: 9 }, kept: 'amobot-installation-20261112-101500.json.gz' } }

/** The section on its own, the status read as `read`. */
async function show(read: BackupsStatus = status()) {
  server().on('GET', URL_, json({ backups: read } satisfies BackupsResponse))
  render(<BackupsSection scheduleSettings="/settings/schedule" />, { wrapper: providers().wrapper })
  await screen.findByRole('heading', { name: 'دریافت پشتیبان' })
}

/** A backup file as the server sends it, named by its `Content-Disposition` (none: no name given). */
function backupFile(name?: string): Answer {
  const file: Answer = { kind: 'response', status: 200, body: 'gzip bytes', type: 'application/gzip' }
  return name === undefined ? file : withHeaders(file, { 'Content-Disposition': `attachment; filename="${name}"` })
}

/** The names files were saved by, as the browser was handed them. */
function savedFiles(): string[] {
  const saved: string[] = []
  vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:backup')
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    saved.push(this.download)
  })
  return saved
}

/** The picker's input, given `file`. */
function pick(file: File) {
  fireEvent.change(screen.getByLabelText('انتخاب فایل پشتیبان'), { target: { files: [file] } })
}

const backup = (name = 'amobot-installation-20261109.json.gz') => new File(['gzip bytes'], name, { type: 'application/gzip' })

/** The file described: the picked file's frame. */
const picked = () => screen.getByRole('group', { name: 'فایل انتخاب‌شده' })

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'error')
})

describe('the status', () => {
  it('says what a backup of the whole installation holds, how often it goes to the group and when it last went', async () => {
    await show()

    expect(screen.getByText(/یک فایل پشتیبان همین حالا ساخته می‌شود/).textContent).toContain('کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها')
    const auto = screen.getByRole('switch', { name: 'ارسال خودکار' })
    expect(auto.getAttribute('aria-checked')).toBe('true')
    expect(screen.getByText('فایل پشتیبان هر ۲۴ ساعت یک بار فرستاده می‌شود.')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تنظیمات پنل › زمان‌بندی کارها' }).getAttribute('href')).toBe('/settings/schedule')
    expect(screen.getByText(/آخرین ارسال:/).textContent).toContain('آخرین ارسال: ۲ ساعت قبل.')
    expect(screen.queryByRole('link', { name: 'اتصال گروه گزارش‌ها' })).toBeNull()
    expect(screen.getByText(/هاست فایل بزرگ‌تر از ۶۴ مگابایت را نمی‌پذیرد/)).toBeTruthy()
  })

  it('words other intervals in days, hours or minutes', async () => {
    await show(status({}, { every_minutes: 2880 }))
    expect(screen.getByText('فایل پشتیبان هر ۲ روز یک بار فرستاده می‌شود.')).toBeTruthy()
  })

  it('in an agent’s shop says the shop alone is in it', async () => {
    await show(status({ scope: 'shop', shop: { id: 2, username: 'reza_shop_bot', title: 'فروشگاه رضا' }, upload_max_bytes: 0, kept: [] }, { every_minutes: 90, last_sent_at: null }))

    expect(screen.getByText(/یک فایل پشتیبان همین حالا ساخته می‌شود/).textContent).toContain('فروشگاه @reza_shop_bot: فقط داده‌های همین فروشگاه، بدون فروشگاه‌های دیگر و سرورها')
    expect(screen.getByText('فایل پشتیبان هر ۹۰ دقیقه یک بار فرستاده می‌شود.')).toBeTruthy()
    expect(screen.getByText(/هنوز فایلی فرستاده نشده است/)).toBeTruthy()
    // The host did not say its limit: none is said.
    expect(screen.queryByText(/را نمی‌پذیرد/)).toBeNull()
  })

  it('without a report group says one must be connected first, pointing to it', async () => {
    await show(status({}, { group: false }))

    expect(screen.getByText(/اول باید گروه گزارش‌ها به ربات وصل شود/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'اتصال گروه گزارش‌ها' }).getAttribute('href')).toBe('/bot-settings/reports')
  })

  it('says why the last one did not go', async () => {
    await show(status({}, { last_problem: 'Bad Request: file is too big' }))

    expect(screen.getByText(/انجام نشد: Bad Request: file is too big/).textContent).toContain('آخرین ارسال (۲ ساعت قبل) انجام نشد')
    expect(screen.queryByText(/آخرین ارسال:/)).toBeNull()
  })

  it('that could not be read says so, and is asked again', async () => {
    server().on('GET', URL_, refusal(500, 'خطای داخلی'))
    render(<BackupsSection />, { wrapper: providers().wrapper })

    expect(await screen.findByText(/پشتیبان‌گیری بارگذاری نشد/)).toBeTruthy()
    server().on('GET', URL_, json({ backups: status() } satisfies BackupsResponse))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByRole('heading', { name: 'دریافت پشتیبان' })).toBeTruthy()
    expect(server().sent('GET', URL_)).toHaveLength(2)
  })
})

describe('sending to the report group', () => {
  it('is saved as the switch is pressed — held meanwhile —, the server’s answer back on screen', async () => {
    const saving = server().hold('PUT', `${URL_}/auto`)
    await show()
    const auto = screen.getByRole('switch', { name: 'ارسال خودکار' })

    fireEvent.click(auto)

    await until(() => expect(saving.waiting).toBe(1))
    expect(auto.getAttribute('aria-checked')).toBe('false')
    expect(auto.getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('PUT', `${URL_}/auto`)[0]?.body).toEqual({ enabled: false })

    saving.answer(json({ backups: status({}, { enabled: false }) } satisfies BackupsResponse))
    await until(() => expect(auto.getAttribute('aria-disabled')).toBeNull())
    expect(auto.getAttribute('aria-checked')).toBe('false')
  })

  it('refused goes back as it was, saying why', async () => {
    server().on('PUT', `${URL_}/auto`, refusal(500, 'خطای داخلی'))
    await show()
    const auto = screen.getByRole('switch', { name: 'ارسال خودکار' })

    fireEvent.click(auto)

    await until(() => expect(toast.error).toHaveBeenCalled())
    expect(auto.getAttribute('aria-checked')).toBe('true')
  })
})

describe('a backup made now', () => {
  it('is saved by the server’s name for it, the button spinning while it is made', async () => {
    const saved = savedFiles()
    const making = server().hold('GET', `${URL_}/download`)
    await show()
    const button = screen.getByRole('button', { name: 'دریافت فایل پشتیبان' })

    fireEvent.click(button)

    await until(() => expect(making.waiting).toBe(1))
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(screen.getByText('فایل پشتیبان در حال ساخت است؛ چند لحظه صبر کنید.')).toBeTruthy()

    making.answer(backupFile('amobot-installation-20261112-101500.json.gz'))
    await until(() => expect(saved).toEqual(['amobot-installation-20261112-101500.json.gz']))
    expect(button.getAttribute('aria-busy')).toBeNull()
    expect(server().sent('GET', `${URL_}/download`)[0]?.headers['x-shop']).toBe('1')
  })

  it('without a name from the server is saved by one of its own', async () => {
    const saved = savedFiles()
    server().on('GET', `${URL_}/download`, backupFile())
    await show()

    fireEvent.click(screen.getByRole('button', { name: 'دریافت فایل پشتیبان' }))

    await until(() => expect(saved).toEqual(['amobot-backup.json.gz']))
  })

  it('that could not be made says so', async () => {
    const saved = savedFiles()
    server().on('GET', `${URL_}/download`, refusal(500, 'فضای دیسک کافی نیست.'))
    await show()

    fireEvent.click(screen.getByRole('button', { name: 'دریافت فایل پشتیبان' }))

    await until(() => expect(toast.error).toHaveBeenCalledWith('فایل پشتیبان دریافت نشد', expect.anything()))
    expect(saved).toEqual([])
  })
})

describe('a file to restore', () => {
  it('is sent as a form, then described: when, by which version, of what, its rows by table, and what a restore leaves out', async () => {
    server().on('POST', `${URL_}/inspect`, json({ inspection: INSPECTION } satisfies BackupInspectionResponse))
    await show()
    const file = backup()

    pick(file)

    const frame = await screen.findByRole('group', { name: 'فایل انتخاب‌شده' })
    const sent = server().sent('POST', `${URL_}/inspect`)[0]?.body
    expect(sent).toBeInstanceOf(FormData)
    expect((sent as FormData).get('file')).toBe(file)

    expect(frame.textContent).toContain('amobot-installation-20261109.json.gz')
    expect(frame.textContent).toContain('۳ روز قبل')
    expect(within(frame).getByText('0.7.2')).toBeTruthy()
    expect(frame.textContent).toContain('کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها')
    expect(frame.textContent).toContain('۲٬۰۴۹ ردیف در ۳ جدول')

    // The tables folded, each by the panel's name for it — or the database's.
    const toggle = within(frame).getByRole('button', { name: 'ردیف‌های هر جدول' })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(toggle)
    const tables = within(frame).getByRole('list', { name: 'ردیف‌های هر جدول' })
    expect(
      within(tables)
        .getAllByRole('listitem')
        .map((row) => row.textContent),
    ).toEqual(['کاربران۱٬۲۰۰', 'اشتراک‌ها۸۴۰', 'device_limits۹'])

    expect(within(frame).getByText('پلن «طلایی» روی سروری بود که دیگر نیست؛ بدون آن سرور برمی‌گردد.')).toBeTruthy()
    expect(frame.textContent).toContain('همه فروشگاه‌ها، سرورها و نماینده‌ها، به حالت')
    expect(frame.textContent).toContain('کاربرانی که در وب‌سایت‌ها و مینی‌اپ‌ها وارد شده‌اند باید دوباره وارد شوند، گفت‌وگوهای نیمه‌کاره با ربات‌ها از اول شروع می‌شود')
    // The panels' own sign-ins stay.
    expect(frame.textContent).not.toContain('خود شما')
    expect(screen.getByRole('button', { name: 'انتخاب فایل دیگر' })).toBeTruthy()
  })

  it('larger than the host takes is said so, never sent', async () => {
    await show(status({ upload_max_bytes: 8 * MB }))
    const big = backup()
    Object.defineProperty(big, 'size', { value: 20 * MB })

    pick(big)

    expect(await screen.findByText('حجم این فایل ۲۰ مگابایت است؛ هاست فایل بزرگ‌تر از ۸ مگابایت را نمی‌پذیرد.')).toBeTruthy()
    expect(server().sent('POST', `${URL_}/inspect`)).toHaveLength(0)
  })

  it('refused says why, under the picker', async () => {
    const why = 'این فایل را نصب دیگری ساخته یا پس از ساخته شدن تغییر کرده است.'
    server().on('POST', `${URL_}/inspect`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { file: [why] }))
    await show()

    pick(backup())

    expect(await screen.findByText(why)).toBeTruthy()
    expect(screen.queryByRole('group', { name: 'فایل انتخاب‌شده' })).toBeNull()
  })

  it('is dropped by «انصراف», the focus back on the picker', async () => {
    server().on('POST', `${URL_}/inspect`, json({ inspection: INSPECTION } satisfies BackupInspectionResponse))
    await show()
    pick(backup())
    await screen.findByRole('group', { name: 'فایل انتخاب‌شده' })

    fireEvent.click(within(picked()).getByRole('button', { name: 'انصراف' }))

    expect(screen.queryByRole('group', { name: 'فایل انتخاب‌شده' })).toBeNull()
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'انتخاب فایل پشتیبان' }))
    expect(server().sent('POST', `${URL_}/restore`)).toHaveLength(0)
  })
})

describe('the restore', () => {
  /** A file picked and described. */
  async function inspected() {
    server().on('POST', `${URL_}/inspect`, json({ inspection: INSPECTION } satisfies BackupInspectionResponse))
    await show()
    pick(backup())
    await screen.findByRole('group', { name: 'فایل انتخاب‌شده' })
  }

  const restoreButton = () => within(picked()).getByRole('button', { name: 'بازگردانی' })
  const confirm = () => fireEvent.click(within(picked()).getByRole('checkbox', { name: 'می‌دانم هرچه بعد از این پشتیبان تغییر کرده، از دست می‌رود.' }))

  it('waits for the admin’s yes, then sends it with the token — the state kept from before shown, to save, and every read asked again', async () => {
    const saved = savedFiles()
    server().on('POST', `${URL_}/restore`, json(RESTORED)).on('GET', `${URL_}/kept/${RESTORED.restore.kept}`, backupFile(RESTORED.restore.kept))
    await inspected()

    expect(restoreButton().getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(restoreButton())
    expect(server().sent('POST', `${URL_}/restore`)).toHaveLength(0)

    confirm()
    expect(restoreButton().getAttribute('aria-disabled')).toBeNull()
    fireEvent.click(restoreButton())

    await until(() => expect(toast.success).toHaveBeenCalledWith('نصب به حالت پشتیبان برگشت', expect.anything()))
    expect(server().sent('POST', `${URL_}/restore`)[0]?.body).toEqual({ token: 'f3c1a9', confirm: true })
    expect(screen.queryByRole('group', { name: 'فایل انتخاب‌شده' })).toBeNull()
    const done = screen.getByText(/به حالت پشتیبان برگشت:/)
    expect(done.textContent).toContain('کل نصب به حالت پشتیبان برگشت: ۲٬۰۴۹ ردیف در ۳ جدول.')
    expect(screen.getByText(RESTORED.restore.kept)).toBeTruthy()
    await until(() => expect(server().sent('GET', URL_)).toHaveLength(2))

    fireEvent.click(screen.getByRole('button', { name: 'دریافت حالت قبل' }))
    await until(() => expect(saved).toEqual([RESTORED.restore.kept]))
  })

  it('refused — the file waited too long — says why, the file still described', async () => {
    const why = 'این فایل بیش از ۳۰ دقیقه پیش بررسی شده است؛ آن را دوباره انتخاب کنید.'
    server().on('POST', `${URL_}/restore`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { file: [why] }))
    await inspected()

    confirm()
    fireEvent.click(restoreButton())

    expect(await within(picked()).findByText(why)).toBeTruthy()
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the states kept from before a restore', () => {
  it('are listed newest first, each saved by its name', async () => {
    const saved = savedFiles()
    const older = 'amobot-installation-20261101-080000.json.gz'
    server().on('GET', `${URL_}/kept/${KEPT}`, backupFile(KEPT))
    await show(status({ kept: [status().kept[0]!, { name: older, made_at: at(-11 * 1440), size: 2 * MB }] }))

    const rows = within(screen.getByRole('list', { name: 'حالت‌های پیش از بازگردانی' })).getAllByRole('listitem')
    expect(rows.map((row) => row.textContent)).toEqual([`${KEPT}۳ روز قبل · ۳ مگابایتدریافت`, `${older}۱۱ روز قبل · ۲ مگابایتدریافت`])

    fireEvent.click(screen.getByRole('button', { name: `دریافت ${KEPT}` }))

    await until(() => expect(saved).toEqual([KEPT]))
  })

  it('none yet says so', async () => {
    await show(status({ kept: [] }))

    expect(screen.getByText('هنوز بازگردانی‌ای انجام نشده است')).toBeTruthy()
  })
})

describe('on the panel’s settings', () => {
  /** The settings page at /settings/backups for `session`, config.php's read refused by the server. */
  function page(session = OWNER) {
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ session, at: '/settings/backups' }).wrapper },
    )
  }

  it('is the database’s: read on its own, the whole installation’s in the main shop, with none of config.php’s words over it', async () => {
    server().on('GET', URL_, json({ backups: status() } satisfies BackupsResponse))
    page()

    expect(await screen.findByRole('heading', { name: 'دریافت پشتیبان' })).toBeTruthy()
    expect(screen.getByText(/^پشتیبان کل نصب: همه فروشگاه‌ها، سرورها و نماینده‌ها\./)).toBeTruthy()
    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
    expect(screen.queryByText(/config\.php/)).toBeNull()
  })

  it('in an agent’s shop is that shop’s alone', async () => {
    server().on('GET', URL_, json({ backups: status({ scope: 'shop', shop: { id: 2, username: 'reza_shop_bot', title: 'فروشگاه رضا' } }) } satisfies BackupsResponse))
    page(AGENT_SHOP)

    const description = await screen.findByText(/^پشتیبان فروشگاه /)
    expect(description.textContent).toMatch(/^پشتیبان فروشگاه @reza_shop_bot: فقط داده‌های همین فروشگاه\./)
  })

  it('is not read while another section is on screen', async () => {
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: '/settings/telegram' }).wrapper },
    )

    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(server().sent('GET', URL_)).toHaveLength(0)
  })
})
