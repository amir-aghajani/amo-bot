import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GrantsCard } from '@/components/servers/grants-card'
import type { ServerGrantRow, ServerGrantsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A server's «افزودن زمان و حجم» (components/servers/grants-card): a grant that would reach no service is not started —
 * the dialog says so, its submit held, its «انصراف» free —; ticking in the services still waiting for their first
 * connection, when there are some, makes one it would reach. The form's count and the running grant's numbers read
 * together — of every active service it goes through, how many it reaches, then how many were checked and what came
 * of them —, and a grant held at a service says what holds it, in the panel's own diagnosis.
 */

function showCard(audience: ServerGrantsResponse['audience'], grants: ServerGrantRow[] = []) {
  server().on('GET', '/api/admin/servers/1/grants', json({ grants, audience } satisfies ServerGrantsResponse))
  render(<GrantsCard server={serverRow()} />, { wrapper: providers().wrapper })
}

/** Grant 7 on server 1, running: three active services to go through, none checked yet. */
function grantRow(overrides: Partial<ServerGrantRow> = {}): ServerGrantRow {
  return {
    id: 7,
    mass_grant_id: null,
    agents_only: false,
    days: 3,
    traffic_bytes: 0,
    reason: null,
    notify: true,
    include_unstarted: false,
    status: 'running',
    total: 3,
    granted: 0,
    skipped: 0,
    failed: 0,
    waiting_reason: null,
    last_failure: null,
    reviewer: 'owner',
    created_at: '2026-10-06T09:00:00Z',
    finished_at: null,
    ...overrides,
  }
}

const dialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)
const submit = () => dialog().getByRole('button', { name: 'افزودن به سرویس‌ها' }) as HTMLButtonElement

async function openForm() {
  fireEvent.click(await screen.findByRole('button', { name: 'افزودن به سرویس‌ها' }))
  await dialog().findByLabelText('تعداد روز')
}

describe('a server’s grant', () => {
  it('that would reach no service is not started, the way out left open', async () => {
    showCard({ running: 0, unstarted: 0 })
    await openForm()

    expect(dialog().getByText(/فعلا سرویسی روی این سرور نیست که این به آن اضافه شود/)).toBeTruthy()
    expect(submit().disabled).toBe(true)
    expect((dialog().getByRole('button', { name: 'انصراف' }) as HTMLButtonElement).disabled).toBe(false)
  })

  it('reaches the waiting services once they are ticked in', async () => {
    showCard({ running: 0, unstarted: 3 })
    await openForm()
    expect(submit().disabled).toBe(true)

    fireEvent.click(dialog().getByRole('checkbox', { name: 'سرویس‌های در انتظار اولین اتصال هم شامل شوند' }))

    expect(dialog().getByText(/به ۳ سرویس روی این سرور اضافه می‌شود/)).toBeTruthy()
    expect(submit().disabled).toBe(false)
  })

  it('says how many it reaches of every active service it goes through — the count its card then checks', async () => {
    showCard({ running: 2, unstarted: 1 })
    await openForm()

    expect(dialog().getByText(/از ۳ سرویس فعال روی این سرور، به ۲ سرویس اضافه می‌شود/)).toBeTruthy()
  })

  it('running, names its numbers, and what holds it up is the panel’s own diagnosis', async () => {
    // The card works on it while open: its run waits here.
    const run = server().hold('POST', '/api/admin/servers/1/grants/7/run')
    showCard({ running: 2, unstarted: 1 }, [grantRow({ granted: 1, skipped: 1, waiting_reason: 'توکن API یا نام کاربری و رمز عبور وارد نشده است.' })])

    expect(await screen.findByText('۲ از ۳ سرویس فعال بررسی شد · به ۱ سرویس اضافه شد · ۱ سرویس شامل نشد')).toBeTruthy()
    expect(screen.getByText('توکن API یا نام کاربری و رمز عبور وارد نشده است.')).toBeTruthy()
    expect(screen.getByText('کار روی این سرور تا رفع این مشکل پنل منتظر می‌ماند؛ خودکار دوباره تلاش می‌شود.')).toBeTruthy()
    expect(screen.queryByText(/جواب نمی‌دهد/)).toBeNull()
    await until(() => expect(run.waiting).toBe(1))
  })
})
