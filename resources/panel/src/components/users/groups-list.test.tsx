import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GroupsList } from '@/components/users/groups-list'
import type { CustomerGroupRow, CustomerGroupsResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * The admin's groups of customers (components/users/groups-list): deleting one says who is in it — the verb by the
 * count, one customer «است», more «هستند» — and that nobody's account is touched.
 */

const group = (id: number, name: string, users: number): CustomerGroupRow => ({ id, name, sort: id, counts: { users }, created_at: '2026-10-01T10:00:00Z' })

/** The delete of `name` asked: its dialog. */
async function deleting(name: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات ${name}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
  return within(await screen.findByRole('dialog', { name: 'حذف گروه' }))
}

describe('deleting a group', () => {
  it('says who is in it, the verb by the count', async () => {
    server().on('GET', '/api/admin/customer-groups', json({ groups: [group(1, 'VIP', 1), group(2, 'همکاران', 4)] } satisfies CustomerGroupsResponse))
    render(<GroupsList />, { wrapper: providers().wrapper })

    expect((await deleting('VIP')).getByText('یک مشتری در این گروه است؛ فقط از گروه بیرون می‌آید و حسابش دست نمی‌خورد.')).toBeTruthy()
  })

  it('— more than one', async () => {
    server().on('GET', '/api/admin/customer-groups', json({ groups: [group(2, 'همکاران', 4)] } satisfies CustomerGroupsResponse))
    render(<GroupsList />, { wrapper: providers().wrapper })

    expect((await deleting('همکاران')).getByText('۴ مشتری در این گروه هستند؛ فقط از گروه بیرون می‌آیند و حسابشان دست نمی‌خورد.')).toBeTruthy()
  })
})
