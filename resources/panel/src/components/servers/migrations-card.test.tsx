import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { MigrationsCard } from '@/components/servers/migrations-card'
import type { ServerMigrationPreview, ServerMigrationRow, ServerMigrationsResponse, ServerRow, ServersResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverMigrationRow, serverRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * A server's «انتقال سرویس‌ها» (components/servers/migrations-card): a move under way says how far it got and what holds
 * it up — a migration waiting on this server's panel offers to go on as a rescue —, and the card works it while open;
 * the dialog says what it would move as the choices change — of which shops, and when the target has too little room —,
 * starts in rescue for a server whose panel failed, and sends what was chosen; the clients a rescue left on the panel
 * are said, with the way to remove them now.
 */

const GERMANY = serverRow()
const NETHERLANDS = serverRow({ id: 3, name: 'هلند', counts: { inbounds: 1, selectable_inbounds: 1, active_subscriptions: 8, shop_active_subscriptions: 8 }, capacity: 10 })

function previewOf(overrides: Partial<ServerMigrationPreview> = {}): ServerMigrationPreview {
  return {
    services: 4,
    shops: [
      { shop: { id: 1, name: 'فروشگاه اصلی', username: null, status: 'active', selling_suspended: null }, services: 3 },
      { shop: { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active', selling_suspended: null }, services: 1 },
    ],
    plans: [],
    unplanned: 0,
    target: null,
    ...overrides,
  }
}

/** What the card reads of server 1 from now on: these moves, and no client left on its panel. */
function showList(migrations: ServerMigrationRow[]) {
  server().on('GET', '/api/admin/servers/1/migrations', json({ migrations, leftovers: { pending: 0, last_error: null } } satisfies ServerMigrationsResponse))
}

function showCard(data: Partial<ServerMigrationsResponse> = {}, of: ServerRow = GERMANY) {
  server().on('GET', '/api/admin/servers/1/migrations', json({ migrations: [], leftovers: { pending: 0, last_error: null }, ...data } satisfies ServerMigrationsResponse))
  render(<MigrationsCard server={of} />, { wrapper: providers().wrapper })
}

const dialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)

/** The dialog's asks of what a move would carry, so far. */
const previews = () => server().sent('POST', '/api/admin/servers/1/migrations/preview')

async function openDialog() {
  server().on('GET', '/api/admin/servers', json({ servers: [GERMANY, NETHERLANDS] } satisfies ServersResponse))
  fireEvent.click(await screen.findByRole('button', { name: 'انتقال سرویس‌ها' }))
  await dialog().findByRole('combobox', { name: 'سرور مقصد' })
}

describe('a server’s move of its services', () => {
  it('under way, names its numbers, and a migration held by this server’s panel offers to go on as a rescue', async () => {
    // The card works on it while open: its run waits here.
    const run = server().hold('POST', '/api/admin/servers/1/migrations/5/run')
    showCard({ migrations: [serverMigrationRow({ moved: 1, failed: 1, waiting_reason: 'سرور قبلی «آلمان»: پاسخ نداد.', waiting_on: 'source' })] })

    expect(await screen.findByText('۲ از ۴ سرویس بررسی شد · ۱ سرویس منتقل شد · ۱ ناموفق')).toBeTruthy()
    expect(screen.getByText('سرور قبلی «آلمان»: پاسخ نداد.')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'ادامه با حالت نجات' })).toBeTruthy()
    expect((screen.getByRole('button', { name: 'انتقال سرویس‌ها' }) as HTMLButtonElement).disabled).toBe(true)
    await until(() => expect(run.waiting).toBe(1))

    const rescued = serverMigrationRow({ mode: 'rescue', moved: 1, failed: 1 })
    server().on('POST', '/api/admin/servers/1/migrations/5/rescue', json({ migration: rescued }))
    showList([rescued])
    fireEvent.click(screen.getByRole('button', { name: 'ادامه با حالت نجات' }))
    fireEvent.click(await dialog().findByRole('button', { name: 'ادامه با حالت نجات' }))
    await until(() => expect(server().sent('POST', '/api/admin/servers/1/migrations/5/rescue')).toHaveLength(1))

    const done = serverMigrationRow({ mode: 'rescue', status: 'done', moved: 3, failed: 1, left_behind: 3, finished_at: '2026-10-14T09:05:00Z' })
    showList([done])
    await act(async () => run.answer(json({ migration: done })))
    expect(await screen.findByText('۳ سرویس منتقل شد · ۱ ناموفق · ۳ کلاینت روی پنل مانده است')).toBeTruthy()
  })

  it('names who started it as their account has it where the server read it', async () => {
    showCard({ migrations: [serverMigrationRow({ status: 'done', moved: 4, reviewer: '@boss', reviewer_name: 'سارا احمدی', finished_at: '2026-10-14T09:05:00Z' })] })

    expect(await screen.findByText('سارا احمدی')).toBeTruthy()
    expect(screen.queryByText('@boss')).toBeNull()
  })

  it('offers no rescue while the target’s panel holds a migration — a rescue would wait on it as well', async () => {
    server().hold('POST', '/api/admin/servers/1/migrations/5/run')
    showCard({ migrations: [serverMigrationRow({ waiting_reason: 'سرور مقصد «هلند»: پاسخ نداد.', waiting_on: 'target' })] })

    expect(await screen.findByText('سرور مقصد «هلند»: پاسخ نداد.')).toBeTruthy()
    expect(screen.getByText('انتقال تا جواب دادن این پنل منتظر می‌ماند و خودکار دوباره تلاش می‌شود.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'ادامه با حالت نجات' })).toBeNull()
    expect(screen.queryByText(/با حالت نجات ادامه دهید/)).toBeNull()
  })

  it('holds its actions while one is under way — the one pressed keeping the focus, not dropping it on the page', async () => {
    server().hold('POST', '/api/admin/servers/1/migrations/5/run')
    const pausing = server().hold('POST', '/api/admin/servers/1/migrations/5/pause')
    showCard({ migrations: [serverMigrationRow()] })
    const pause = await screen.findByRole('button', { name: 'توقف موقت' })

    pause.focus()
    fireEvent.click(pause)
    await until(() => expect(pausing.waiting).toBe(1))

    for (const name of ['توقف موقت', 'لغو']) {
      const button = screen.getByRole('button', { name }) as HTMLButtonElement
      expect([button.disabled, button.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    }
    expect(document.activeElement).toBe(pause)
    fireEvent.click(pause)
    expect(server().sent('POST', '/api/admin/servers/1/migrations/5/pause')).toHaveLength(1)
  })

  it('worked to its end on the card, says how it ended — stopped because the target could take no more — and why', async () => {
    vi.spyOn(toast, 'error')
    const failed = serverMigrationRow({ status: 'failed', moved: 2, reason: 'سرور مقصد «هلند»: ظرفیت سرور پر است.', finished_at: '2026-10-14T09:05:00Z' })
    server().on('POST', '/api/admin/servers/1/migrations/5/run', () => {
      showList([failed])
      return json({ migration: failed })
    })
    showCard({ migrations: [serverMigrationRow()] })

    await until(() => expect(toast.error).toHaveBeenCalledWith('انتقال ناتمام ماند؛ دلیلش روی کارت آمده است'))
    expect(await screen.findByText('سرور مقصد «هلند»: ظرفیت سرور پر است.')).toBeTruthy()
    expect(screen.getByText('ناتمام')).toBeTruthy()
    expect(server().sent('POST', '/api/admin/servers/1/migrations/5/run')).toHaveLength(1)
  })

  it('says what it would move as the choices change, and the target’s too little room', async () => {
    showCard()
    server().on('POST', '/api/admin/servers/1/migrations/preview', (request) =>
      json(previewOf(request.body !== undefined && (request.body as { target_server_id: number | null }).target_server_id === 3 ? { target: { problem: null, room: 2 } } : {})),
    )
    await openDialog()

    expect(await dialog().findByText('۴ سرویس منتقل می‌شود (فروشگاه اصلی ۳، فروشگاه رضا ۱)؛ لینک هر کدام عوض می‌شود.')).toBeTruthy()
    expect((dialog().getByRole('button', { name: 'شروع انتقال' }) as HTMLButtonElement).disabled).toBe(true)

    fireEvent.keyDown(dialog().getByRole('combobox', { name: 'سرور مقصد' }), { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: /هلند/ }))

    expect(await dialog().findByText(/ظرفیت سرور مقصد فقط برای ۲ سرویس دیگر جا دارد/)).toBeTruthy()
    const started = serverMigrationRow({ status: 'queued', started_at: null })
    server().on('POST', '/api/admin/servers/1/migrations', json({ migration: started }, 201))
    showList([started])
    server().hold('POST', '/api/admin/servers/1/migrations/5/run')
    fireEvent.click(dialog().getByRole('button', { name: 'شروع انتقال' }))

    await until(() =>
      expect(server().sent('POST', '/api/admin/servers/1/migrations')[0]?.body).toEqual({ target_server_id: 3, mode: 'migrate', include_disabled: false, notify: true, plan_ids: null }),
    )
    expect(await screen.findByText('در صف')).toBeTruthy()
  })

  it('holds its start while the choices on screen are still being counted — never started on the last choices’ count', async () => {
    showCard()
    const plans = [
      { id: 1, name: 'طلایی', shop: 'فروشگاه اصلی', services: 3 },
      { id: 2, name: 'نقره‌ای', shop: 'فروشگاه اصلی', services: 1 },
    ]
    server().on('POST', '/api/admin/servers/1/migrations/preview', json(previewOf({ plans, target: { problem: null, room: null } })))
    await openDialog()
    fireEvent.keyDown(dialog().getByRole('combobox', { name: 'سرور مقصد' }), { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: /هلند/ }))
    const start = dialog().getByRole('button', { name: 'شروع انتقال' })
    await until(() => expect(start.getAttribute('aria-disabled')).toBeNull())

    const counting = server().hold('POST', '/api/admin/servers/1/migrations/preview')
    fireEvent.click(dialog().getByRole('checkbox', { name: 'نقره‌ای' }))
    expect(start.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(start)
    await until(() => expect(counting.waiting).toBe(1))
    expect(start.getAttribute('aria-disabled')).toBe('true')
    expect(server().sent('POST', '/api/admin/servers/1/migrations')).toHaveLength(0)

    await act(async () => counting.answer(json(previewOf({ services: 1, plans, target: { problem: null, room: null } }))))
    await until(() => expect(start.getAttribute('aria-disabled')).toBeNull())
  })

  it('says a refusal of the plans ticked under them — not lost for want of a place', async () => {
    showCard()
    const plans = [
      { id: 1, name: 'طلایی', shop: 'فروشگاه اصلی', services: 3 },
      { id: 2, name: 'نقره‌ای', shop: 'فروشگاه اصلی', services: 1 },
    ]
    const gone = 'یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.'
    server()
      .on('POST', '/api/admin/servers/1/migrations/preview', json(previewOf({ plans, target: { problem: null, room: null } })))
      .on('POST', '/api/admin/servers/1/migrations', refusal(422, gone, { plan_ids: [gone] }))
    await openDialog()
    fireEvent.keyDown(dialog().getByRole('combobox', { name: 'سرور مقصد' }), { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: /هلند/ }))
    fireEvent.click(await dialog().findByRole('checkbox', { name: 'طلایی' }))
    const start = dialog().getByRole('button', { name: 'شروع انتقال' })
    await until(() => expect(start.getAttribute('aria-disabled')).toBeNull())

    fireEvent.click(start)

    const field = await dialog().findByRole('group', { name: 'فقط سرویس‌های این پلن‌ها' })
    await until(() => expect(field.textContent).toContain(gone))
    expect(field.hasAttribute('data-invalid')).toBe(true)
  })

  it('starts in rescue for a server whose panel failed', async () => {
    showCard({}, serverRow({ last_error: 'پاسخ نداد.' }))
    server().on('POST', '/api/admin/servers/1/migrations/preview', json(previewOf()))
    await openDialog()

    expect(dialog().getByRole('combobox', { name: 'روش' }).textContent).toContain('نجات')
    expect(dialog().getByText(/پنل این سرور پرسیده نمی‌شود/)).toBeTruthy()
  })

  it('asks what it would move once the choices settle: plans ticked in a row, once', async () => {
    showCard()
    const plans = [
      { id: 1, name: 'طلایی', shop: 'فروشگاه اصلی', services: 2 },
      { id: 2, name: 'نقره‌ای', shop: 'فروشگاه اصلی', services: 1 },
      { id: 3, name: 'برنزی', shop: 'فروشگاه رضا', services: 1 },
    ]
    server().on('POST', '/api/admin/servers/1/migrations/preview', json(previewOf({ plans })))
    await openDialog()

    fireEvent.click(await dialog().findByRole('checkbox', { name: 'طلایی' }))
    fireEvent.click(dialog().getByRole('checkbox', { name: 'نقره‌ای' }))

    await until(() => expect(previews()).toHaveLength(2))
    expect(previews().map((request) => request.body)).toEqual([
      { target_server_id: null, include_disabled: false, plan_ids: [] },
      { target_server_id: null, include_disabled: false, plan_ids: [1, 2] },
    ])
  })

  it('says the clients a rescue left on the panel, and removes them now on the owner’s word', async () => {
    showCard({ leftovers: { pending: 3, last_error: 'پاسخ نداد.' } })
    server().on('POST', '/api/admin/servers/1/leftovers/clean', json({ leftovers: { pending: 0, last_error: null } }))

    expect(await screen.findByText(/۳ کلاینت از انتقال سرویس‌ها روی پنل این سرور مانده است/)).toBeTruthy()
    expect(screen.getByText('آخرین تلاش: پاسخ نداد.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'حذف همین حالا' }))

    await until(() => expect(server().sent('POST', '/api/admin/servers/1/leftovers/clean')).toHaveLength(1))
  })

  it('says the latest service a panel refused, as a status of its line', async () => {
    showCard({ migrations: [serverMigrationRow({ status: 'done', moved: 3, failed: 1, last_failure: 'amir_1: inbound not found', finished_at: '2026-10-14T09:05:00Z' })] })

    const line = (await screen.findByText('amir_1: inbound not found')).closest('[role]')
    expect(line?.getAttribute('role')).toBe('status')
    expect(line?.textContent).toBe('آخرین خطا: amir_1: inbound not found')
  })
})

/** A retry of a failed read: it spins, asks once and keeps the failure said until `again` answers. */
async function retried(path: string, lead: string) {
  const retry = await screen.findByRole('button', { name: 'تلاش دوباره' })
  const again = server().hold('GET', path)
  fireEvent.click(retry)
  await until(() => expect(retry.getAttribute('aria-busy')).toBe('true'))
  expect(screen.getByText(lead)).toBeTruthy()
  fireEvent.click(retry)
  expect(server().sent('GET', path)).toHaveLength(2)
  return again
}

describe('the moves’ reads', () => {
  it('of the list, failed, stays said while it is asked again, until the answer', async () => {
    server().on('GET', '/api/admin/servers/1/migrations', refusal(500, 'خطای سرور.'))
    render(<MigrationsCard server={GERMANY} />, { wrapper: providers().wrapper })

    const again = await retried('/api/admin/servers/1/migrations', 'لیست بارگذاری نشد.')
    expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0)

    again.answer(json({ migrations: [], leftovers: { pending: 0, last_error: null } } satisfies ServerMigrationsResponse))
    await until(() => expect((screen.getByRole('button', { name: 'انتقال سرویس‌ها' }) as HTMLButtonElement).disabled).toBe(false))
    expect(screen.queryByText('لیست بارگذاری نشد.')).toBeNull()
  })

  it('of a move’s failed services, failed, stays said while it is asked again, until the answer', async () => {
    const job = serverMigrationRow({ status: 'done', moved: 3, failed: 1, finished_at: '2026-10-14T09:05:00Z' })
    showCard({ migrations: [job] })
    server().on('GET', '/api/admin/servers/1/migrations/5', refusal(500, 'خطای سرور.'))
    fireEvent.click(await screen.findByRole('button', { name: 'سرویس‌های ناموفق' }))

    const again = await retried('/api/admin/servers/1/migrations/5', 'لیست بارگذاری نشد.')

    again.answer(json({ migration: job, failures: [{ id: 1, subscription_id: 9, client: 'amir_9', shop: null, reason: 'inbound not found', created_at: '2026-10-14T09:04:00Z' }] }))
    expect(await dialog().findByText('amir_9')).toBeTruthy()
  })
})
