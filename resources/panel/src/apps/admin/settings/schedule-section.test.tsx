import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SettingsPage } from '@/apps/admin/pages/settings'
import { SCHEDULE_DESCRIPTION, ScheduleSection } from '@/apps/admin/settings/schedule-section'
import type { Schedule, ScheduleField, ScheduleRequest, ScheduleResponse, ScheduleTask } from '@/lib/api-types'
import { ThemeProvider } from '@/lib/theme'
import { providers, signedIn, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * «تنظیمات پنل › زمان‌بندی کارها» (apps/admin/settings/schedule-section): every scheduled task in the order a run takes
 * them, with what it does, when it last ran and is next due, how often it runs and the times it decides by — each beside
 * its default, put back by a press of its own, or all at once. The form is saved whole and shows the server's answer; a
 * refusal stays under the field it is about. The section is the database's: read on its own, only while on screen, and
 * none of config.php's words are said over it.
 */

const URL = '/api/admin/schedule'

/** Now, as the fixtures count from it. */
const NOW = Date.now()

/** A moment `minutes` from now (before it, below zero). */
const at = (minutes: number) => new Date(NOW + minutes * 60_000).toISOString()

/** A time a task decides by, at its default. */
function timing(name: string, label: string, unit: string, value: number, min: number, max: number, hint: string): ScheduleField {
  return { name, label, hint, unit, value, default: value, min, max }
}

/** The times the tasks decide by, beside their intervals — the server's (ScheduleSettings). */
const TIMINGS: Record<string, ScheduleField[]> = {
  expire_orders: [timing('expire_orders_hours', 'مهلت سفارش پرداخت‌نشده', 'ساعت', 48, 1, 720, 'سفارش پرداخت‌نشده‌ای که این مدت دست نخورده کنار گذاشته می‌شود.')],
  online_payments: [
    timing('online_payments_check_minutes', 'فاصله پرسیدن، صفحه پرداخت باز', 'دقیقه', 3, 1, 60, 'تا وقتی صفحه پرداخت درگاه باز است، هر این چند دقیقه یک بار از درگاه پرسیده می‌شود.'),
    timing('online_payments_late_hours', 'پرسیدن پس از بسته شدن صفحه', 'ساعت', 2, 0, 72, 'تا این مدت پس از بسته شدن صفحه پرداخت هنوز پرسیده می‌شود.'),
    timing('online_payments_late_check_minutes', 'فاصله پرسیدن، صفحه بسته', 'دقیقه', 15, 1, 1440, 'پس از بسته شدن صفحه پرداخت، هر این چند دقیقه یک بار پرسیده می‌شود.'),
    timing('online_payments_review_hours', 'مهلت پول تسویه‌نشده', 'ساعت', 48, 1, 720, 'پرداختی که درگاه پولی از آن دارد ولی تسویه نکرده، تا این مدت پرسیده می‌شود.'),
  ],
  prune_accounts: [timing('notices_keep_days', 'نگه‌داری اعلان‌های وب‌سایت', 'روز', 180, 1, 3650, 'اعلان‌های مشتری در وب‌سایت تا این مدت نگه داشته و بعد پاک می‌شوند.')],
  prune_tickets: [timing('ticket_pictures_keep_days', 'نگه‌داری تصویرهای تیکت بسته', 'روز', 30, 1, 3650, 'تصویرهای تیکت تا این مدت پس از بسته شدن تیکت نگه داشته می‌شوند.')],
  send_reports: [
    timing('reports_keep_days', 'نگه‌داری گزارش‌های فرستاده‌شده', 'روز', 7, 1, 365, 'گزارش‌های گروه تا این مدت نگه داشته می‌شوند.'),
    timing('reports_wait_hours', 'مهلت ارسال گزارش', 'ساعت', 24, 1, 168, 'گزارشی که این مدت در صف مانده، کنار گذاشته می‌شود.'),
  ],
}

/** Every task in the order a run takes them: its key, its title and its interval's default minutes. */
const TASKS: [key: string, title: string, minutes: number][] = [
  ['auto_approve_receipts', 'تایید خودکار رسیدها', 1],
  ['online_payments', 'بررسی پرداخت‌های آنلاین', 1],
  ['resume_deliveries', 'ادامه تحویل‌های نیمه‌کاره', 1],
  ['expire_orders', 'کنار گذاشتن سفارش‌های رهاشده', 10],
  ['send_broadcasts', 'ارسال پیام‌های همگانی', 1],
  ['auto_renew', 'تمدید خودکار', 30],
  ['next_period', 'شروع دوره تمدیدشده', 10],
  ['grants', 'افزودن زمان و حجم و هدیه همگانی', 1],
  ['migrations', 'انتقال سرویس‌ها', 1],
  ['sync_subscriptions', 'همگام‌سازی سرویس‌ها', 15],
  ['send_reminders', 'یادآوری‌ها', 15],
  ['cleanup_services', 'حذف سرویس‌های تمام‌شده', 15],
  ['agency_standing', 'شرایط نمایندگی', 60],
  ['prune_updates', 'پاک‌سازی سابقه پیام‌های ربات', 60],
  ['prune_accounts', 'پاک‌سازی حساب‌های وب‌سایت', 60],
  ['prune_tickets', 'پاک‌سازی تیکت‌ها', 60],
  ['nightly_draw', 'قرعه‌کشی شبانه', 10],
  ['send_backups', 'ارسال پشتیبان به گروه', 1440],
  ['check_releases', 'بررسی نسخه تازه', 1440],
  ['send_reports', 'ارسال گزارش‌ها', 1],
]

/** A task at its defaults, run a minute ago. */
function task(key: string, title: string, minutes: number): ScheduleTask {
  return {
    key,
    title,
    description: `کار «${title}» انجام می‌شود.`,
    each_shop: false,
    last_ran_at: at(-1),
    next_run_at: at(minutes - 1),
    fields: [{ name: `${key}_minutes`, label: 'فاصله اجرا', hint: null, unit: 'دقیقه', value: minutes, default: minutes, min: 1, max: 10080 }, ...(TIMINGS[key] ?? [])],
  }
}

/** Every task at its defaults, Cron run a minute ago. */
const DEFAULTS: Schedule = { tasks: TASKS.map(([key, title, minutes]) => task(key, title, minutes)), last_run_at: at(-1) }

/** The schedule with some tasks changed, by key — and a field's value by its name (`values`). */
function scheduleWith(tasks: Record<string, Partial<ScheduleTask>>, values: Record<string, number> = {}, more: Partial<Schedule> = {}): Schedule {
  return {
    ...DEFAULTS,
    ...more,
    tasks: DEFAULTS.tasks.map((each) => ({ ...each, ...tasks[each.key], fields: each.fields.map((field) => ({ ...field, value: values[field.name] ?? field.value })) })),
  }
}

/**
 * What the owner set: the services read every 30 minutes (15 by default), in every shop, last 5 minutes ago and next in
 * 10; the broadcasts due already; the new version never looked for.
 */
const SET = scheduleWith(
  {
    sync_subscriptions: { each_shop: true, last_ran_at: at(-5), next_run_at: at(10) },
    send_broadcasts: { last_ran_at: at(-3), next_run_at: at(-2) },
    check_releases: { last_ran_at: null, next_run_at: null },
  },
  { sync_subscriptions_minutes: 30 },
)

/** Every field's value of a schedule, by its name: the request a save of it sends. */
function requestOf(schedule: Schedule): ScheduleRequest {
  return Object.fromEntries(schedule.tasks.flatMap((each) => each.fields.map((field) => [field.name, field.value]))) as unknown as ScheduleRequest
}

/** The section on its own, the schedule read as `schedule`. */
async function show(schedule: Schedule = SET) {
  server().on('GET', URL, json({ schedule } satisfies ScheduleResponse))
  render(<ScheduleSection />, { wrapper: providers().wrapper })
  await screen.findByRole('group', { name: 'همگام‌سازی سرویس‌ها' })
}

/** A task's frame, by its title. */
const taskOf = (title: string) => screen.getByRole('group', { name: title })

/** The input of a task's field, by the task's title and the field's label. */
const input = (title: string, label = 'فاصله اجرا') => within(taskOf(title)).getByLabelText(label) as HTMLInputElement

const type = (title: string, label: string, value: string) => fireEvent.change(input(title, label), { target: { value } })
const save = () => fireEvent.click(screen.getByRole('button', { name: 'ذخیره' }))
const resetAll = () => screen.getByRole('button', { name: 'بازگشت به همه پیش‌فرض‌ها' })

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('the schedule', () => {
  it('lists every task in the order a run takes them, with what it does, when it ran and is due, and its numbers beside their defaults', async () => {
    await show()

    const titles = screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)
    expect(titles).toEqual(TASKS.map(([, title]) => title))

    const sync = taskOf('همگام‌سازی سرویس‌ها')
    expect(within(sync).getByText('کار «همگام‌سازی سرویس‌ها» انجام می‌شود.')).toBeTruthy()
    expect(within(sync).getByText('در هر فروشگاه')).toBeTruthy()
    expect(sync.textContent).toContain('آخرین اجرا: ۵ دقیقه قبل')
    expect(sync.textContent).toContain('اجرای بعدی: در ۱۰ دقیقه')
    expect(input('همگام‌سازی سرویس‌ها').value).toBe('30')
    expect(input('همگام‌سازی سرویس‌ها').id).toBe('schedule_sync_subscriptions_minutes')
    expect(within(sync).getByText('پیش‌فرض: ۱۵ دقیقه')).toBeTruthy()
    expect(within(sync).getByRole('button', { name: 'بازگرداندن فاصله اجرا به پیش‌فرض' })).toBeTruthy()

    // Its unit and its default are read with the input.
    const described = (input('همگام‌سازی سرویس‌ها').getAttribute('aria-describedby') ?? '').split(' ').map((id) => document.getElementById(id)?.textContent)
    expect(described).toEqual(['دقیقه', 'پیش‌فرض: ۱۵ دقیقه'])

    const releases = taskOf('بررسی نسخه تازه')
    expect(releases.textContent).toContain('هنوز اجرا نشده')
    expect(releases.textContent).toContain('اجرای بعدی: در نوبت بعدی Cron')
    expect(within(releases).getByText('پیش‌فرض: ۱٬۴۴۰ دقیقه')).toBeTruthy()
    expect(within(releases).queryByText('در هر فروشگاه')).toBeNull()
    expect(within(releases).queryByRole('button', { name: /به پیش‌فرض/ })).toBeNull()

    // Due already: at Cron's next run.
    expect(taskOf('ارسال پیام‌های همگانی').textContent).toContain('اجرای بعدی: در نوبت بعدی Cron')
  })

  it('shows the times a task decides by under its interval, each with its label, hint, unit and default', async () => {
    await show()

    const payments = taskOf('بررسی پرداخت‌های آنلاین')
    expect(
      within(payments)
        .getAllByRole('textbox')
        .map((box) => box.id),
    ).toEqual([
      'schedule_online_payments_minutes',
      'schedule_online_payments_check_minutes',
      'schedule_online_payments_late_hours',
      'schedule_online_payments_late_check_minutes',
      'schedule_online_payments_review_hours',
    ])
    expect(input('بررسی پرداخت‌های آنلاین', 'مهلت پول تسویه‌نشده').value).toBe('48')
    expect(within(payments).getByText('پرداختی که درگاه پولی از آن دارد ولی تسویه نکرده، تا این مدت پرسیده می‌شود.')).toBeTruthy()
    expect(within(payments).getByText('پیش‌فرض: ۴۸ ساعت')).toBeTruthy()
    expect(within(taskOf('ارسال گزارش‌ها')).getByText('پیش‌فرض: ۷ روز')).toBeTruthy()

    // Every input on the page has an id of its own.
    const ids = screen.getAllByRole('textbox').map((box) => box.id)
    expect(ids).toHaveLength(29)
    expect(new Set(ids).size).toBe(29)
  })

  it('warns while Cron never ran, pointing to where it is set up', async () => {
    await show({ ...SET, last_run_at: null })

    expect(screen.getByText(/Cron هنوز اجرا نشده است/)).toBeTruthy()
    expect(screen.getByRole('link', { name: 'راه‌اندازی Cron' }).getAttribute('href')).toBe('/settings/advanced')
  })
})

describe('saving the schedule', () => {
  it('sends every field whole — Persian digits as numbers —, says so and shows the server’s answer', async () => {
    const saved = scheduleWith({}, { sync_subscriptions_minutes: 20, expire_orders_hours: 72 })
    server().on('PUT', URL, json({ schedule: saved } satisfies ScheduleResponse))
    await show()

    type('همگام‌سازی سرویس‌ها', 'فاصله اجرا', '۲۰')
    type('کنار گذاشتن سفارش‌های رهاشده', 'مهلت سفارش پرداخت‌نشده', ' ۷۲ ')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    save()

    await until(() => expect(toast.success).toHaveBeenCalledWith('زمان‌بندی ذخیره شد'))
    expect(server().sent('PUT', URL)[0]?.body).toEqual(requestOf(saved))
    expect(Object.keys(server().sent('PUT', URL)[0]?.body as object)).toHaveLength(29)
    await until(() => expect(input('همگام‌سازی سرویس‌ها').value).toBe('20'))
    expect(input('کنار گذاشتن سفارش‌های رهاشده', 'مهلت سفارش پرداخت‌نشده').value).toBe('72')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
  })

  it('keeps the form as typed, with the server’s refusal under the field it is about', async () => {
    const interval = 'فاصله اجرای «همگام‌سازی سرویس‌ها» باید عددی بین 1 تا 10080 دقیقه باشد.'
    const review = 'مهلت پول تسویه‌نشده نمی‌تواند از پرسیدن پس از بسته شدن صفحه کمتر باشد.'
    server().on('PUT', URL, refusal(422, 'اطلاعات واردشده معتبر نیست.', { sync_subscriptions_minutes: [interval], online_payments_review_hours: [review] }))
    await show()

    type('همگام‌سازی سرویس‌ها', 'فاصله اجرا', '20000')
    type('بررسی پرداخت‌های آنلاین', 'مهلت پول تسویه‌نشده', '1')
    type('بررسی پرداخت‌های آنلاین', 'پرسیدن پس از بسته شدن صفحه', '5')
    save()

    expect(await within(taskOf('همگام‌سازی سرویس‌ها')).findByText(interval)).toBeTruthy()
    expect(input('همگام‌سازی سرویس‌ها').getAttribute('aria-invalid')).toBe('true')
    expect(input('همگام‌سازی سرویس‌ها').value).toBe('20000')
    expect(within(taskOf('بررسی پرداخت‌های آنلاین')).getByText(review)).toBeTruthy()
    expect(input('بررسی پرداخت‌های آنلاین', 'مهلت پول تسویه‌نشده').getAttribute('aria-invalid')).toBe('true')
    // The refused field's hint gives way to the refusal.
    expect(screen.queryByText('پرداختی که درگاه پولی از آن دارد ولی تسویه نکرده، تا این مدت پرسیده می‌شود.')).toBeNull()
    expect(toast.success).not.toHaveBeenCalled()
  })
})

describe('the defaults', () => {
  it('are put back one field at a time, the focus left on its input — saved only with «ذخیره»', async () => {
    await show()
    const reset = within(taskOf('همگام‌سازی سرویس‌ها')).getByRole('button', { name: 'بازگرداندن فاصله اجرا به پیش‌فرض' })

    fireEvent.click(reset)

    expect(input('همگام‌سازی سرویس‌ها').value).toBe('15')
    expect(document.activeElement).toBe(input('همگام‌سازی سرویس‌ها'))
    expect(within(taskOf('همگام‌سازی سرویس‌ها')).queryByRole('button', { name: /به پیش‌فرض/ })).toBeNull()
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    expect(server().sent('PUT', URL)).toHaveLength(0)

    // Another value than the default — however its digits are written — offers it again.
    type('تمدید خودکار', 'فاصله اجرا', '۴۵')
    expect(within(taskOf('تمدید خودکار')).getByRole('button', { name: 'بازگرداندن فاصله اجرا به پیش‌فرض' })).toBeTruthy()
    type('تمدید خودکار', 'فاصله اجرا', '۳۰')
    expect(within(taskOf('تمدید خودکار')).queryByRole('button', { name: /به پیش‌فرض/ })).toBeNull()
  })

  it('are put back all at once, then saved whole', async () => {
    server().on('PUT', URL, json({ schedule: DEFAULTS } satisfies ScheduleResponse))
    await show(scheduleWith({}, { sync_subscriptions_minutes: 30, expire_orders_hours: 12, reports_wait_hours: 100 }))
    expect(resetAll().getAttribute('aria-disabled')).toBeNull()

    resetAll().focus()
    fireEvent.click(resetAll())

    expect(input('همگام‌سازی سرویس‌ها').value).toBe('15')
    expect(input('کنار گذاشتن سفارش‌های رهاشده', 'مهلت سفارش پرداخت‌نشده').value).toBe('48')
    expect(input('ارسال گزارش‌ها', 'مهلت ارسال گزارش').value).toBe('24')
    // Held, not gone: the focus stays on it.
    expect(resetAll().getAttribute('aria-disabled')).toBe('true')
    expect(document.activeElement).toBe(resetAll())
    expect(server().sent('PUT', URL)).toHaveLength(0)

    save()
    await until(() => expect(toast.success).toHaveBeenCalledWith('زمان‌بندی ذخیره شد'))
    expect(server().sent('PUT', URL)[0]?.body).toEqual(requestOf(DEFAULTS))
  })

  it('all in already hold the press that would put them back', async () => {
    await show(DEFAULTS)

    expect(resetAll().getAttribute('aria-disabled')).toBe('true')
    expect(screen.queryByRole('button', { name: /به پیش‌فرض$/ })).toBeNull()
  })
})

describe('unsaved changes', () => {
  it('are said, and reverted to what the shop runs with — a value typed back in other digits being none', async () => {
    await show()
    const saveButton = screen.getByRole('button', { name: 'ذخیره' })
    expect(saveButton.getAttribute('aria-disabled')).toBe('true')

    type('همگام‌سازی سرویس‌ها', 'فاصله اجرا', '۳۰')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()

    type('یادآوری‌ها', 'فاصله اجرا', '60')
    expect(screen.getByText('تغییرات ذخیره نشده')).toBeTruthy()
    expect(saveButton.getAttribute('aria-disabled')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))

    expect(input('یادآوری‌ها').value).toBe('15')
    expect(input('همگام‌سازی سرویس‌ها').value).toBe('30')
    expect(screen.queryByText('تغییرات ذخیره نشده')).toBeNull()
    expect(saveButton.getAttribute('aria-disabled')).toBe('true')
  })
})

describe('the read', () => {
  it('failed says so, and is asked again', async () => {
    server().on('GET', URL, refusal(500, 'خطای داخلی'))
    render(<ScheduleSection />, { wrapper: providers().wrapper })

    expect(await screen.findByText(/زمان‌بندی کارها بارگذاری نشد/)).toBeTruthy()
    server().on('GET', URL, json({ schedule: SET } satisfies ScheduleResponse))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))

    expect(await screen.findByRole('group', { name: 'همگام‌سازی سرویس‌ها' })).toBeTruthy()
    expect(server().sent('GET', URL)).toHaveLength(2)
  })
})

describe('on the panel’s settings', () => {
  /** The settings page at `address`, config.php's read refused by the server. */
  function page(address: string) {
    server().on('GET', '/api/admin/settings/config', refusal(500, 'خطای داخلی'))
    render(
      <ThemeProvider>
        <SettingsPage />
      </ThemeProvider>,
      { wrapper: signedIn({ at: address }).wrapper },
    )
  }

  it('is the database’s: read on its own, with none of config.php’s words over it', async () => {
    server().on('GET', URL, json({ schedule: SET } satisfies ScheduleResponse))
    page('/settings/schedule')

    expect(await screen.findByRole('group', { name: 'همگام‌سازی سرویس‌ها' })).toBeTruthy()
    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(screen.getByText(SCHEDULE_DESCRIPTION)).toBeTruthy()
    expect(screen.queryByText('تنظیمات بارگذاری نشد.')).toBeNull()
    expect(screen.queryByText(/config\.php/)).toBeNull()
  })

  it('is not read while another section is on screen', async () => {
    page('/settings/telegram')

    await until(() => expect(server().sent('GET', '/api/admin/settings/config')).toHaveLength(1))
    expect(server().sent('GET', URL)).toHaveLength(0)
  })
})
