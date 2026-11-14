import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { IMPORTS_DESCRIPTION, ImportsSection } from '@/components/imports/imports-section'
import type { ImportProblemsResponse, ImportsResponse, ImportState } from '@/lib/api-types'
import { ThemeProvider } from '@/lib/theme'
import { AGENT_SHOP, providers, signedIn, until } from '@/test/render'
import { importChoices, importState, importSurvey, migratorRow, serverRow } from '@/test/rows'
import { json, noContent, refusal, server, withHeaders } from '@/test/server'

/*
 * «تنظیمات پنل › انتقال از ربات دیگر» (components/imports), as the owner works it, phase by phase: with no import under
 * way the bots it reads as cards, the backup file to upload or the database to connect to, and the earlier imports;
 * reading, a bar while the panel steps on until it is read; the review — what was found, each panel's servers, what to
 * bring, what never comes —, its choices saved before it starts, after asking; importing, stage by stage to its report
 * — what came of each kind, what did not and why, a page at a time —; a failure's words, a cancel after asking. The
 * main shop's alone, read only while the section is on screen.
 */

const URL_ = '/api/admin/imports'
const CONTINUE = `${URL_}/7/continue`

/** What GET /imports answers now: the test moves it on as the import moves. */
let imports: ImportsResponse

function serve(data: Partial<ImportsResponse> = {}) {
  imports = { migrators: [migratorRow()], current: null, history: [], upload_max_bytes: 0, ...data }
  server().on('GET', URL_, () => json(imports))
}

/** The section on its own, the imports read as `data`. */
async function show(data: Partial<ImportsResponse> = {}) {
  serve(data)
  server().on('GET', '/api/admin/servers', json({ servers: [serverRow()] }))
  const { client, wrapper } = providers()
  render(<ImportsSection />, { wrapper })
  await until(() => expect(server().sent('GET', URL_)).toHaveLength(1))
  return client
}

/** An import in review, with the survey and the server's proposal. */
const inReview = (overrides: Partial<ImportState> = {}) => importState({ phase: 'review', survey: importSurvey(), proposed: importChoices(), ...overrides })

/** Import 7 done: what came of each kind, a service not on its panel and the reseller bots' customers left out. */
const done = (overrides: Partial<ImportState> = {}) =>
  importState({
    phase: 'done',
    survey: importSurvey(),
    choices: importChoices(),
    progress: { stage: 'cards', stage_label: 'کارت‌های بانکی', done: 2, total: 2 },
    counts: { customers: { created: 110, linked: 2, skipped: 8 }, services: { created: 79, linked: 0, skipped: 5 }, cards: { created: 0, linked: 2, skipped: 0 } },
    problems: {
      total: 11,
      by_reason: [
        { reason: 'not_on_panel', label: 'روی پنلش پیدا نشد', count: 3 },
        { reason: 'reseller', label: 'از ربات‌های نماینده؛ انتخاب نشده بود', count: 8 },
      ],
    },
    started_at: '2026-11-13T09:05:00Z',
    finished_at: '2026-11-13T09:09:00Z',
    ...overrides,
  })

/** The «آلمان» panel's choice, its name isolated as the field says it. */
const PANEL = 'سرویس‌های «⁨آلمان⁩» کجا بروند'

const dialog = (name: string) => within(screen.getByRole('dialog', { name }))

beforeEach(() => {
  vi.spyOn(toast, 'success')
  vi.spyOn(toast, 'error')
})

describe('with no import under way', () => {
  it('says what it does, and offers the bots it reads as cards and where their data comes from', async () => {
    await show()

    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
    const bot = screen.getByRole('radio', { name: 'Faoxima', description: /نسخه‌های 1.x/ })
    expect(bot.getAttribute('aria-checked')).toBe('true')
    expect(bot.textContent).toContain('نسخه‌های 1.x')
    expect(screen.getByRole('link', { name: 'github.com/Mmd-Amir/Faoxima' }).getAttribute('href')).toBe('https://github.com/Mmd-Amir/Faoxima')
    expect(screen.getByRole('tab', { name: 'فایل پشتیبان' }).getAttribute('aria-selected')).toBe('true')
    expect(screen.getByText(/به کانال گزارش‌هایش می‌فرستد/).textContent).toContain('backup.zip')
    expect(screen.getByText(/برای انتقال نهایی، اول ربات قبلی را خاموش کنید و بعد پشتیبانش را بگیرید/)).toBeTruthy()
    expect(screen.getByText(/هنوز انتقالی انجام نشده است/)).toBeTruthy()
  })

  it('uploads the picked backup with the bot it is of, and reads it from then on', async () => {
    server().on('POST', URL_, json({ import: importState() }, 201))
    const stepping = server().hold('POST', CONTINUE)
    await show()
    const upload = await screen.findByRole('button', { name: 'بارگذاری و خواندن' })
    expect(upload.getAttribute('aria-disabled')).toBe('true')

    const file = new File(['PK zip bytes'], 'faoxima-1113.zip', { type: 'application/zip' })
    fireEvent.change(screen.getByLabelText('انتخاب فایل پشتیبان ربات قبلی'), { target: { files: [file] } })
    expect(screen.getByText('faoxima-1113.zip')).toBeTruthy()
    fireEvent.click(upload)

    expect(await screen.findByRole('heading', { name: 'در حال خواندن داده‌ها' })).toBeTruthy()
    const sent = server().sent('POST', URL_)[0]?.body as FormData
    expect(sent.get('migrator')).toBe('faoxima')
    expect(sent.get('file')).toBe(file)
    await until(() => expect(stepping.waiting).toBe(1))
  })

  it('says the host’s upload limit up front, and holds a larger file to it before anything goes', async () => {
    await show({ upload_max_bytes: 8 * 1024 ** 2 })

    expect((await screen.findByText(/هاست فایل بزرگ‌تر از/)).textContent).toContain('۸ مگابایت')
    const big = new File(['x'], 'backup.zip', { type: 'application/zip' })
    Object.defineProperty(big, 'size', { value: 9 * 1024 ** 2 })
    fireEvent.change(screen.getByLabelText('انتخاب فایل پشتیبان ربات قبلی'), { target: { files: [big] } })

    expect(screen.getByText(/حجم این فایل ۹ مگابایت است/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'بارگذاری و خواندن' }).getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('POST', URL_)).toHaveLength(0)
  })

  it('says why a file is refused, under it', async () => {
    const why = 'این فایل پشتیبان دیتابیس نیست؛ فایل .sql، .zip یا .gz ربات قبلی را انتخاب کنید.'
    server().on('POST', URL_, refusal(422, 'اطلاعات واردشده معتبر نیست.', { file: [why] }))
    await show()

    fireEvent.change(await screen.findByLabelText('انتخاب فایل پشتیبان ربات قبلی'), { target: { files: [new File(['x'], 'notes.txt')] } })
    fireEvent.click(screen.getByRole('button', { name: 'بارگذاری و خواندن' }))

    expect(await screen.findByText(why)).toBeTruthy()
    expect(server().sent('POST', CONTINUE)).toHaveLength(0)
  })

  it('refused as another import is under way (another tab’s), reads the imports again and shows that one', async () => {
    server().on('POST', URL_, refusal(422, 'اطلاعات واردشده معتبر نیست.', { import: ['یک انتقال دیگر در جریان است؛ اول آن را تمام یا لغو کنید.'] }))
    server().hold('POST', CONTINUE)
    await show()

    fireEvent.change(await screen.findByLabelText('انتخاب فایل پشتیبان ربات قبلی'), { target: { files: [new File(['x'], 'backup.zip')] } })
    imports = { ...imports, current: importState() }
    fireEvent.click(screen.getByRole('button', { name: 'بارگذاری و خواندن' }))

    expect(await screen.findByRole('heading', { name: 'در حال خواندن داده‌ها' })).toBeTruthy()
    expect(server().sent('GET', URL_)).toHaveLength(2)
  })

  it('connects to the database read-only, each refusal under its field', async () => {
    server().on('POST', `${URL_}/database`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { host: ['به این هاست وصل نشد؛ آدرس و پورت را بررسی کنید.'] }))
    await show()
    fireEvent.click(await screen.findByRole('tab', { name: 'اتصال به دیتابیس' }))

    fireEvent.change(screen.getByLabelText('هاست'), { target: { value: ' db.example.com ' } })
    fireEvent.change(screen.getByLabelText('نام دیتابیس'), { target: { value: 'faoxima' } })
    fireEvent.change(screen.getByLabelText('نام کاربری'), { target: { value: 'reader' } })
    fireEvent.change(screen.getByLabelText('رمز عبور'), { target: { value: 's3cret pass' } })
    fireEvent.click(screen.getByRole('button', { name: 'اتصال و خواندن' }))

    expect(await screen.findByText('به این هاست وصل نشد؛ آدرس و پورت را بررسی کنید.')).toBeTruthy()
    expect(screen.getByLabelText('هاست').getAttribute('aria-invalid')).toBe('true')
    expect(server().sent('POST', `${URL_}/database`)[0]?.body).toEqual({ migrator: 'faoxima', host: 'db.example.com', port: 3306, database: 'faoxima', username: 'reader', password: 's3cret pass' })

    server().on('POST', `${URL_}/database`, json({ import: importState({ source: 'database', file_name: null }) }, 201))
    server().hold('POST', CONTINUE)
    fireEvent.click(screen.getByRole('button', { name: 'اتصال و خواندن' }))
    expect(await screen.findByText(/از دیتابیس ربات خوانده می‌شود/)).toBeTruthy()
  })

  it('lists the earlier imports, each with its report, and goes back to the start', async () => {
    server().on('GET', `${URL_}/7/problems`, json({ problems: [], total: 0, page: 1, per_page: 50 } satisfies ImportProblemsResponse))
    await show({ history: [done()] })

    const list = await screen.findByRole('list', { name: 'انتقال‌های قبلی' })
    expect(list.textContent).toContain('انجام‌شده')
    fireEvent.click(within(list).getByRole('button', { name: /گزارش انتقال/ }))

    expect(await screen.findByRole('heading', { name: 'انتقال تمام شد' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'انتقال تازه' }))
    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
  })
})

describe('an import being read', () => {
  it('shows how much is read while the panel steps on — resumed on coming back —, then the review', async () => {
    const stepping = server().hold('POST', CONTINUE)
    await show({ current: importState({ progress: { stage: null, stage_label: null, done: 250, total: 1000 } }) })

    const bar = await screen.findByRole('progressbar', { name: 'پیشرفت خواندن' })
    expect(bar.getAttribute('aria-valuenow')).toBe('250')
    expect(bar.getAttribute('aria-valuemax')).toBe('1000')
    expect(screen.getByText(/خوانده شد$/)).toBeTruthy()
    await until(() => expect(stepping.waiting).toBe(1))

    // The first view drawn keeps the focus where it was; the live region says the phase.
    expect(document.activeElement).toBe(document.body)
    const live = screen.getByRole('status', { name: '' })
    expect(live.textContent).toBe('در حال خواندن داده‌ها')

    imports = { ...imports, current: inReview() }
    stepping.answer(json({ import: inReview(), stepped: true }))

    const heading = await screen.findByRole('heading', { name: 'آنچه در ربات قبلی پیدا شد' })
    await until(() => expect(document.activeElement).toBe(heading))
    expect(live.textContent).toBe('خواندن تمام شد؛ آنچه پیدا شد را بررسی کنید.')
    expect(screen.getByText(/این پشتیبان باید بعد از خاموش کردن ربات قبلی گرفته شده باشد/)).toBeTruthy()
  })

  it('stops at a refused step, saying so with its details, until the owner retries', async () => {
    let steps = 0
    server().on('POST', CONTINUE, () =>
      ++steps === 1
        ? withHeaders(refusal(422, 'اطلاعات واردشده معتبر نیست.', { import: ['این انتقال در مرحله خواندن یا نوشتن نیست.'] }), { 'X-Request-Id': 'req-4f2a' })
        : json({ import: inReview(), stepped: true }),
    )
    await show({ current: importState() })

    expect(await screen.findByText(/انتقال متوقف ماند/)).toBeTruthy()
    expect(screen.getByText(/req-4f2a/)).toBeTruthy()
    expect(server().sent('POST', CONTINUE)).toHaveLength(1)
    imports = { ...imports, current: inReview() }
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByRole('heading', { name: 'آنچه در ربات قبلی پیدا شد' })).toBeTruthy()
    expect(steps).toBe(2)
  })

  it('that is no data of the chosen bot says so, and starts afresh', async () => {
    const why = 'این داده‌ها مال ربات Faoxima نیست؛ ربات درست را انتخاب کنید.'
    const failed = importState({ phase: 'failed', error: why, finished_at: '2026-11-13T09:01:00Z' })
    const stepping = server().hold('POST', CONTINUE)
    await show({ current: importState() })
    await until(() => expect(stepping.waiting).toBe(1))

    imports = { ...imports, current: null, history: [failed] }
    stepping.answer(json({ import: failed, stepped: true }))

    expect(await screen.findByRole('heading', { name: 'انتقال انجام نشد' })).toBeTruthy()
    expect(screen.getByText(new RegExp(why))).toBeTruthy()
    expect(toast.error).toHaveBeenCalledWith('انتقال انجام نشد', { description: why })
    fireEvent.click(screen.getByRole('button', { name: 'انتقال تازه' }))
    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
  })

  it('is cancelled after asking, back to the start — no more steps asked once it is pressed', async () => {
    const stepping = server().hold('POST', CONTINUE)
    const cancelling = server().hold('DELETE', `${URL_}/7`)
    await show({ current: importState() })
    await until(() => expect(stepping.waiting).toBe(1))

    fireEvent.click(await screen.findByRole('button', { name: 'لغو انتقال' }))
    expect(dialog('لغو انتقال').getByText(/هنوز چیزی در فروشگاه نوشته نشده است/).textContent).toContain('فایل پشتیبان و داده‌هایی که از آن خوانده شده پاک می‌شود')
    fireEvent.click(dialog('لغو انتقال').getByRole('button', { name: 'لغو انتقال' }))
    await until(() => expect(cancelling.waiting).toBe(1))

    // The step under way answers while the cancel waits (PHP takes a session's requests one at a time): nothing follows it.
    stepping.answer(json({ import: importState({ progress: { stage: null, stage_label: null, done: 600, total: 1000 } }), stepped: true }))
    imports = { ...imports, current: null, history: [importState({ phase: 'cancelled' })] }
    cancelling.answer(noContent)

    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
    expect(toast.success).toHaveBeenCalledWith('انتقال لغو شد')
    await until(() => expect(server().sent('GET', URL_)).toHaveLength(2))
    expect(server().sent('POST', CONTINUE)).toHaveLength(1)
  })

  it('says a step that failed, and tries it again at once on «تلاش دوباره»', async () => {
    let steps = 0
    server().on('POST', CONTINUE, () => (++steps === 1 ? refusal(500, 'خطای داخلی') : json({ import: inReview(), stepped: true })))
    await show({ current: importState() })

    expect(await screen.findByText('آخرین قدم انجام نشد؛ کمی بعد خودکار دوباره تلاش می‌شود.')).toBeTruthy()
    imports = { ...imports, current: inReview() }
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByRole('heading', { name: 'آنچه در ربات قبلی پیدا شد' })).toBeTruthy()
    expect(steps).toBe(2)
  })
})

describe('the review', () => {
  it('shows what was found, where each panel’s services go, what to bring and what never comes', async () => {
    await show({ current: inReview() })

    const found = await screen.findByRole('region', { name: 'داده‌های ربات قبلی' })
    expect(found.textContent).toContain('مشتری‌ها۱۲۰')
    expect(found.textContent).toContain('۸ نفر از ربات‌های نماینده')
    expect(found.textContent).toContain('۴٬۲۵۰٬۰۰۰')

    // The 3x-ui panel to server 1, suggested on its address; the panel no connector speaks to stays behind, saying why.
    const panel = await screen.findByRole('combobox', { name: PANEL })
    expect(panel.textContent).toContain('سرور «آلمان»')
    expect(screen.getByText(/AmoBot هنوز به پنل Guard وصل نمی‌شود/)).toBeTruthy()
    expect(screen.getByText('https://guard.example.com')).toBeTruthy()

    expect(screen.getByRole('switch', { name: 'موجودی کیف پول' }).getAttribute('aria-checked')).toBe('true')
    const admins = screen.getByRole('switch', { name: 'مدیرها' })
    expect(admins.getAttribute('aria-checked')).toBe('false')
    expect(screen.getByText(/این افراد مدیر این فروشگاه هم می‌شوند/).textContent).toContain('⁨@old_admin⁩ (administrator)')
    expect(screen.getByText(/مشتری‌ها به این گروه‌ها می‌پیوندند/).textContent).toContain('نماینده عادی (۱۲ نفر، قیمت نماینده)')
    expect(screen.getByText(/۲ کارت، به روش پرداخت کارت به کارت/).textContent).toContain('خاموش می‌آیند')
    expect(screen.getByRole('switch', { name: 'کانال‌های اجباری' }).getAttribute('aria-checked')).toBe('false')

    const left = screen.getByRole('list', { name: 'آنچه منتقل نمی‌شود' })
    expect(left.textContent).toContain('تاریخچه پرداخت‌ها۹۴۰')
    expect(screen.getByText('۳ مشتری بدهکارند؛ بدهی‌شان به کیف پولشان منتقل می‌شود.')).toBeTruthy()
  })

  it('holds a switch that goes with another one while that is off — and sends it off, as it shows', async () => {
    server().on('PUT', `${URL_}/7/choices`, json({ import: inReview() }))
    server().on('POST', `${URL_}/7/start`, json({ import: importState({ phase: 'importing', started_at: '2026-11-13T09:05:00Z' }) }))
    server().hold('POST', CONTINUE)
    await show({ current: inReview({ proposed: importChoices({ plans_on: true }) }) })

    fireEvent.click(await screen.findByRole('switch', { name: 'سرویس‌ها' }))
    fireEvent.click(screen.getByRole('switch', { name: 'پلن‌ها' }))

    const tests = screen.getByRole('switch', { name: 'اکانت‌های تست' })
    expect(tests.getAttribute('aria-checked')).toBe('false')
    expect(tests.getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByText('با خاموش بودن «سرویس‌ها»، اکانت‌های تست هم منتقل نمی‌شوند.')).toBeTruthy()
    expect(screen.getByRole('switch', { name: 'پلن‌ها روشن بیایند' }).getAttribute('aria-checked')).toBe('false')

    fireEvent.click(screen.getByRole('button', { name: 'شروع انتقال' }))
    fireEvent.click(dialog('شروع انتقال').getByRole('button', { name: 'شروع انتقال' }))

    await until(() => expect(server().sent('PUT', `${URL_}/7/choices`)).toHaveLength(1))
    expect(server().sent('PUT', `${URL_}/7/choices`)[0]?.body).toEqual({ choices: importChoices({ services: false, tests: false, plans: false, plans_on: false }) })
  })

  it('saves the choices and starts once asked, then steps through the stages', async () => {
    server().on('PUT', `${URL_}/7/choices`, json({ import: inReview({ choices: importChoices() }) }))
    const importing = importState({
      phase: 'importing',
      survey: importSurvey(),
      choices: importChoices(),
      progress: { stage: 'customers', stage_label: 'مشتری‌ها', done: 40, total: 120 },
      started_at: '2026-11-13T09:05:00Z',
    })
    server().on('POST', `${URL_}/7/start`, json({ import: importing }))
    const stepping = server().hold('POST', CONTINUE)
    await show({ current: inReview() })

    fireEvent.click(await screen.findByRole('switch', { name: 'کانال‌های اجباری' }))
    const panel = await screen.findByRole('combobox', { name: PANEL })
    fireEvent.keyDown(panel, { key: 'Enter' })
    fireEvent.click(screen.getByRole('option', { name: 'افزودن به‌عنوان سرور تازه' }))
    fireEvent.click(screen.getByRole('button', { name: 'شروع انتقال' }))
    expect(server().sent('PUT', `${URL_}/7/choices`)).toHaveLength(0)
    fireEvent.click(dialog('شروع انتقال').getByRole('button', { name: 'شروع انتقال' }))

    const stages = await screen.findByRole('list', { name: 'مرحله‌های انتقال' })
    expect(server().sent('PUT', `${URL_}/7/choices`)[0]?.body).toEqual({
      choices: importChoices({ channels: true, panels: { Germany: { action: 'add', server_id: null }, 'guard-1': { action: 'skip', server_id: null } } }),
    })
    expect(server().sent('POST', `${URL_}/7/start`)).toHaveLength(1)
    const current = within(stages)
      .getAllByRole('listitem')
      .find((stage) => stage.getAttribute('aria-current') === 'step')
    expect(current?.textContent).toContain('مشتری‌ها')
    expect(current?.textContent).toContain('۴۰ از ۱۲۰')
    expect(stages.textContent).toContain('(انجام شد)')
    await until(() => expect(stepping.waiting).toBe(1))
  })

  it('says a refused choice under its panel, and does not start', async () => {
    const why = 'این سرور به همان نوع پنل وصل نیست.'
    server().on('PUT', `${URL_}/7/choices`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { 'choices.panels.Germany.server_id': [why] }))
    await show({ current: inReview() })

    fireEvent.click(await screen.findByRole('button', { name: 'شروع انتقال' }))
    fireEvent.click(dialog('شروع انتقال').getByRole('button', { name: 'شروع انتقال' }))

    expect(await screen.findByText(why)).toBeTruthy()
    expect(screen.getByRole('combobox', { name: PANEL }).getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByText('بعضی انتخاب‌ها پذیرفته نشد؛ دلیل هرکدام کنار خودش آمده است.')).toBeTruthy()
    expect(server().sent('POST', `${URL_}/7/start`)).toHaveLength(0)
    // The focus on the choice refused, cards above the button.
    await until(() => expect(document.activeElement).toBe(screen.getByRole('combobox', { name: PANEL })))

    // A choice changed is the refusal being fixed: it goes.
    fireEvent.click(screen.getByRole('switch', { name: 'کارت‌های بانکی' }))
    expect(screen.queryByText(why)).toBeNull()
  })
})

describe('an import being written', () => {
  it('ends in its report: what came of each kind, and what did not and why, a page at a time', async () => {
    const stepping = server().hold('POST', CONTINUE)
    const problems: ImportProblemsResponse = {
      problems: [{ kind: 'services', source_key: 'inv-41', reason: 'not_on_panel', label: 'روی پنلش پیدا نشد', detail: 'ali_4521 روی آلمان', created_at: '2026-11-13T09:08:00Z' }],
      total: 3,
      page: 1,
      per_page: 50,
    }
    server().on('GET', `${URL_}/7/problems`, json(problems))
    await show({ current: importState({ phase: 'importing', progress: { stage: 'services', stage_label: 'سرویس‌ها', done: 10, total: 84 }, started_at: '2026-11-13T09:05:00Z' }) })
    await until(() => expect(stepping.waiting).toBe(1))

    imports = { ...imports, current: null, history: [done()] }
    stepping.answer(json({ import: done(), stepped: true }))

    expect(await screen.findByRole('heading', { name: 'انتقال تمام شد' })).toBeTruthy()
    expect(toast.success).toHaveBeenCalledWith('انتقال تمام شد')
    const came = screen.getByRole('list', { name: 'آنچه منتقل شد' })
    expect(
      within(came)
        .getAllByRole('listitem')
        .map((row) => row.textContent),
    ).toEqual(['مشتری‌ها۱۱۰ تازه · ۲ از قبل بود · ۸ نیامد', 'سرویس‌ها۷۹ تازه · ۰ از قبل بود · ۵ نیامد', 'کارت‌های بانکی۰ تازه · ۲ از قبل بود · ۰ نیامد'])
    expect(screen.getByRole('list', { name: 'دلیل‌ها' }).textContent).toContain('از ربات‌های نماینده؛ انتخاب نشده بود۸')

    const rows = await screen.findByRole('list', { name: 'موارد' })
    expect(rows.textContent).toContain('سرویس')
    expect(rows.textContent).toContain('inv-41')
    expect(rows.textContent).toContain('ali_4521 روی آلمان')
    expect(server().sent('GET', `${URL_}/7/problems`)[0]?.query.get('page')).toBe('1')

    // Narrowed to a reason with rows: the counted ones are no choice.
    fireEvent.keyDown(screen.getByRole('combobox', { name: /دلیل/ }), { key: 'Enter' })
    expect(screen.queryByRole('option', { name: /از ربات‌های نماینده/ })).toBeNull()
    fireEvent.click(screen.getByRole('option', { name: /روی پنلش پیدا نشد/ }))
    await until(() => expect(server().sent('GET', `${URL_}/7/problems`).at(-1)?.query.get('reason')).toBe('not_on_panel'))
  })

  it('has its report deleted after asking, back to the start', async () => {
    server()
      .on('DELETE', `${URL_}/7`, noContent)
      .on('GET', `${URL_}/7/problems`, json({ problems: [], total: 0, page: 1, per_page: 50 } satisfies ImportProblemsResponse))
    await show({ history: [done()] })
    fireEvent.click(await screen.findByRole('button', { name: /گزارش انتقال/ }))

    fireEvent.click(await screen.findByRole('button', { name: 'حذف گزارش' }))
    // The imports read again only later: the record is gone from the history at once all the same.
    const reading = server().hold('GET', URL_)
    fireEvent.click(dialog('حذف گزارش انتقال').getByRole('button', { name: 'حذف گزارش' }))

    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
    expect(server().sent('DELETE', `${URL_}/7`)).toHaveLength(1)
    expect(screen.getByText('هنوز انتقالی انجام نشده است')).toBeTruthy()
    await until(() => expect(reading.waiting).toBe(1))
    reading.answer(json({ ...imports, history: [] }))
  })
})

describe('on the panel’s settings', () => {
  it('is a section of the main shop’s, with its words over it', async () => {
    serve()
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: '/settings/import' }).wrapper },
    )

    expect(await screen.findByRole('heading', { name: 'انتقال تازه' })).toBeTruthy()
    expect(screen.getByText(IMPORTS_DESCRIPTION)).toBeTruthy()
    // config.php's failure is none of this section's.
    expect(screen.queryByText(/تنظیمات بارگذاری نشد/)).toBeNull()
  })

  it('is none of an agent’s shop’s: its address goes to the first section, and nothing is read', async () => {
    serve()
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ session: AGENT_SHOP, at: '/settings/import' }).wrapper },
    )

    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(screen.queryByRole('heading', { name: 'انتقال تازه' })).toBeNull()
    expect(server().sent('GET', URL_)).toHaveLength(0)
  })
})
