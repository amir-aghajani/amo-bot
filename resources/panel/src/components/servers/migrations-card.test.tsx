import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { MigrationsCard } from '@/components/servers/migrations-card'
import type { ServerMigrationPreview, ServerMigrationRow, ServerMigrationsResponse, ServerRow, ServersResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverMigrationRow, serverRow } from '@/test/rows'
import { json, server } from '@/test/server'

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
    showCard({ migrations: [serverMigrationRow({ moved: 1, failed: 1, waiting_reason: 'سرور قبلی «آلمان»: پاسخ نداد.' })] })

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
})
