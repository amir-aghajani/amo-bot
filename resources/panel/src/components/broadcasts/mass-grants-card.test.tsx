import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MassGrantsCard } from '@/components/broadcasts/mass-grants-card'
import { LeaveQuestion } from '@/components/leave-question'
import type { MassGrantRow, MassGrantsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * A new mass gift (components/broadcasts/mass-grants-card): a gift is made — nothing saved to go back to, no revert —,
 * and what is unsaved is what it would send: a server picked for one server's services, then everyone's again, is none
 * of it. A gift to a customer group names the group it picked, says whom it reaches by that group's numbers, and is
 * listed by its group — one deleted since shown as such.
 */

const READ: MassGrantsResponse = {
  grants: [],
  audience: {
    all: { running: 12, unstarted: 2 },
    agents: { running: 3, unstarted: 0 },
    servers: [{ id: 1, name: 'آلمان', running: 7, unstarted: 1 }],
    groups: [
      { id: 5, name: 'VIP', running: 4, unstarted: 0 },
      { id: 6, name: 'همکاران', running: 0, unstarted: 0 },
    ],
  },
}

/** A gift to group 5 «VIP», just issued. */
const GROUP_GIFT: MassGrantRow = {
  id: 9,
  days: 3,
  traffic_bytes: 0,
  reason: null,
  notify: true,
  include_unstarted: false,
  audience: 'group',
  server: null,
  group: { id: 5, name: 'VIP' },
  status: 'running',
  total: 4,
  granted: 0,
  skipped: 0,
  failed: 0,
  parts: [{ id: 21, server: { id: 1, name: 'آلمان' }, status: 'running', total: 4, granted: 0, skipped: 0, failed: 0, waiting_reason: null, last_failure: null }],
  reviewer: 'root',
  reviewer_name: null,
  created_at: '2026-10-01T10:00:00Z',
  finished_at: null,
}

function open() {
  render(
    <>
      <LeaveQuestion />
      <MassGrantsCard />
    </>,
    { wrapper: providers().wrapper },
  )
}

/** The option `option` picked in the select labelled `label`. */
function pick(label: string, option: string | RegExp) {
  fireEvent.keyDown(screen.getByRole('combobox', { name: label }), { key: 'Enter' })
  fireEvent.click(screen.getByRole('option', { name: option }))
}

describe('a new mass gift', () => {
  it('offers no revert, and closes without asking once the form is back to how it opened', async () => {
    server().on('GET', '/api/admin/mass-grants', json(READ))
    open()
    const add = await screen.findByRole('button', { name: 'هدیه جدید' })
    await until(() => expect(add.hasAttribute('disabled')).toBe(false))
    fireEvent.click(add)
    expect(screen.queryByRole('button', { name: 'بازگردانی تغییرات' })).toBeNull()

    pick('به چه سرویس‌هایی', 'سرویس‌های یک سرور')
    pick('سرور', /آلمان/)
    pick('به چه سرویس‌هایی', 'همه سرویس‌ها')
    fireEvent.click(screen.getByRole('button', { name: 'انصراف' }))

    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()
    await until(() => expect(screen.queryByRole('dialog', { name: 'هدیه همگانی' })).toBeNull())
  })

  it('to a customer group names the group, says whom it reaches by the group, and is listed by it', async () => {
    server()
      .on('GET', '/api/admin/mass-grants', json(READ))
      .on('POST', '/api/admin/mass-grants', json({ grant: GROUP_GIFT }, 201))
      .on('POST', '/api/admin/mass-grants/9/run', json({ grant: GROUP_GIFT }))
    open()
    const add = await screen.findByRole('button', { name: 'هدیه جدید' })
    await until(() => expect(add.hasAttribute('disabled')).toBe(false))
    fireEvent.click(add)

    pick('به چه سرویس‌هایی', 'سرویس‌های مشتری‌های یک گروه')
    expect(screen.getByText('اول گروهی انتخاب کنید که به سرویس‌های مشتری‌هایش اضافه شود.')).toBeTruthy()
    pick('گروه مشتریان', /VIP/)
    expect(screen.getByText(/^به ۴ سرویس اضافه می‌شود/)).toBeTruthy()
    fireEvent.change(screen.getByLabelText('تعداد روز'), { target: { value: '3' } })
    fireEvent.click(screen.getByRole('button', { name: 'شروع هدیه' }))

    await until(() => expect(server().sent('POST', '/api/admin/mass-grants')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/mass-grants')[0]?.body).toMatchObject({ audience: 'group', group_id: '5', server_id: '', days: '3' })
    await until(() => expect(screen.getByText(/سرویس‌های گروه «VIP»/)).toBeTruthy())
  })

  it('names a group deleted since its gift was given as such', async () => {
    server().on(
      'GET',
      '/api/admin/mass-grants',
      json({ ...READ, grants: [{ ...GROUP_GIFT, status: 'done', group: null, parts: [{ ...GROUP_GIFT.parts[0]!, status: 'done' }], finished_at: '2026-10-01T10:05:00Z' }] }),
    )
    open()

    expect(await screen.findByText(/سرویس‌های گروهی که حذف شد/)).toBeTruthy()
  })
})
