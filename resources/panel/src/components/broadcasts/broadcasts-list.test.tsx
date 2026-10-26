import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { BroadcastsList } from '@/components/broadcasts/broadcasts-list'
import type { BroadcastRow, BroadcastsResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The broadcasts' runs (components/broadcasts/broadcasts-list): a pause, a resume or a cancel puts the run the server
 * answers in its place; «لغو پین», after a second look, starts a run of its own — on top of the list, which is read
 * again, its source's row with it.
 */

function broadcast(overrides: Partial<BroadcastRow> = {}): BroadcastRow {
  return {
    id: 5,
    kind: 'message',
    source_id: null,
    mode: 'copy',
    audience: { key: 'all', id: null, label: 'همه مشتری‌ها' },
    pin: true,
    pinned: 120,
    buttons: [],
    content: 'text',
    excerpt: 'تخفیف آخر هفته',
    status: 'done',
    total: 120,
    sent: 120,
    blocked: 0,
    failed: 0,
    admin: null,
    reviewer: 'root',
    reviewer_name: null,
    created_at: '2026-10-06T09:00:00Z',
    finished_at: '2026-10-06T09:05:00Z',
    actions: { pause: false, resume: false, cancel: false, unpin: true },
    ...overrides,
  }
}

const runs = (...broadcasts: BroadcastRow[]) => json({ broadcasts, meta: { page: 1, per_page: 25, total: broadcasts.length, last_page: 1 } } satisfies BroadcastsResponse)

const reads = () => server().sent('GET', '/api/admin/broadcasts').length

/** A run's ⋮ menu — named by the run's number, whole in its Persian words —, and one of its items chosen. */
async function choose(id: number, item: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات ارسال ${idLabel(id)}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: item }))
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('who started a run', () => {
  it('is named as their account has it where the server read it, else as they are kept', async () => {
    const unpinned = broadcast({ id: 6, kind: 'unpin', source_id: 5, pinned: 0, reviewer: '@boss', reviewer_name: 'سارا احمدی', actions: { pause: false, resume: false, cancel: false, unpin: false } })
    server().on('GET', '/api/admin/broadcasts', runs(unpinned, broadcast()))
    render(<BroadcastsList />, { wrapper: providers({ at: '/broadcasts' }).wrapper })

    expect(await screen.findByText('سارا احمدی')).toBeTruthy()
    expect(screen.queryByText('@boss')).toBeNull()
    expect(screen.getByText('root')).toBeTruthy()
  })
})

describe('a run’s controls', () => {
  it('replace the run with the server’s answer, without reading the list again', async () => {
    const sending = broadcast({ status: 'sending', pin: false, pinned: 0, actions: { pause: true, resume: false, cancel: true, unpin: false } })
    server()
      .on('GET', '/api/admin/broadcasts', runs(sending))
      .on('POST', '/api/admin/broadcasts/5/pause', json({ broadcast: { ...sending, status: 'paused', actions: { pause: false, resume: true, cancel: true, unpin: false } } }))
    render(<BroadcastsList />, { wrapper: providers({ at: '/broadcasts' }).wrapper })

    await choose(5, 'توقف')

    await until(() => expect(toast.success).toHaveBeenCalledWith('ارسال متوقف شد'))
    expect(reads()).toBe(1)
  })

  it('start «لغو پین» after confirmation as a new run, and read the list again', async () => {
    const pinned = broadcast()
    const unpinning = broadcast({ id: 6, kind: 'unpin', source_id: 5, status: 'sending', sent: 0, pinned: 0, actions: { pause: true, resume: false, cancel: true, unpin: false } })
    let latest = [pinned]
    server()
      .on('GET', '/api/admin/broadcasts', () => runs(...latest))
      .on('POST', '/api/admin/broadcasts/5/unpin', () => {
        latest = [unpinning, { ...pinned, actions: { ...pinned.actions, unpin: false } }]
        return json({ broadcast: unpinning }, 201)
      })
    render(<BroadcastsList />, { wrapper: providers({ at: '/broadcasts' }).wrapper })

    await choose(5, 'لغو پین')
    const dialog = await screen.findByRole('dialog', { name: 'لغو پین' })
    expect(within(dialog).getByText(`ارسال ${idLabel(5)}`)).toBeTruthy()
    fireEvent.click(within(dialog).getByRole('button', { name: 'لغو پین' }))

    await until(() => expect(reads()).toBe(2))
    await until(() => expect(document.body.textContent).toContain('لغو پین ارسال #5'))
    expect(toast.success).toHaveBeenCalledWith('لغو پین شروع شد؛ ربات پیام‌ها را یکی‌یکی از حالت پین درمی‌آورد')
  })
})

describe('a run a row edited by hand left half-known', () => {
  it('has a dash for its audience, its time and an unpin’s source, and no word of its mode', async () => {
    const unknown = broadcast({ mode: null, audience: null, created_at: null })
    const orphan = broadcast({ id: 6, kind: 'unpin', mode: null, audience: null, pinned: 0, actions: { pause: false, resume: false, cancel: false, unpin: false } })
    server().on('GET', '/api/admin/broadcasts', runs(orphan, unknown))
    render(<BroadcastsList />, { wrapper: providers({ at: '/broadcasts' }).wrapper })

    const row = (await screen.findByText('تخفیف آخر هفته')).closest('tr')
    const cells = row?.querySelectorAll('td')
    // Its audience's column, and its sender's: who, then when.
    expect(cells?.[1]?.textContent).toBe('—')
    expect(cells?.[4]?.textContent).toMatch(/—$/)
    expect(row?.querySelector('time')).toBeNull()
    expect(row?.textContent).not.toContain('کپی')
    expect(document.body.textContent).toContain('لغو پین ارسال —')
  })
})
