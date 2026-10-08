import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { GroupsModal } from '@/components/users/groups-modal'
import type { CustomerGroupRow, CustomerGroupsResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The groups a customer is in (components/users/groups-modal): with no group yet, the dialog says so and leads to where
 * one is made — the users page's «گروه‌ها» —, its save held while there is nothing to choose. The groups are a set: one
 * ticked off and on again is no change, wherever it came back in the list.
 */

const VIP: CustomerGroupRow = { id: 1, name: 'VIP', sort: 1, counts: { users: 3 }, created_at: '2026-10-01T10:00:00Z' }
const COLLEAGUES: CustomerGroupRow = { id: 3, name: 'همکاران', sort: 2, counts: { users: 1 }, created_at: '2026-10-01T10:00:00Z' }

/** The dialog over the customer — in VIP and «همکاران» —, the shop's groups as `groups`. */
function show(groups: CustomerGroupRow[]) {
  server().on('GET', '/api/admin/customer-groups', json({ groups } satisfies CustomerGroupsResponse))
  render(<GroupsModal user={userRow({ groups: [VIP, COLLEAGUES].map(({ id, name }) => ({ id, name })) })} invalidates={[]} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
}

const save = () => screen.getByRole('button', { name: 'ذخیره گروه‌ها' }) as HTMLButtonElement

describe('a customer’s groups', () => {
  it('with no group yet says so, leads to where one is made, and holds the save — there is nothing to choose', async () => {
    show([])

    const link = await screen.findByRole('link', { name: '«گروه‌ها»' })
    expect(link.getAttribute('href')).toBe('/users/groups')
    expect(save().disabled).toBe(true)
  })

  it('with groups to choose from lets the save go — once a group changes', async () => {
    show([VIP, COLLEAGUES])

    fireEvent.click(await screen.findByRole('checkbox', { name: 'VIP' }))
    expect(save().disabled).toBe(false)
    expect(save().getAttribute('aria-disabled')).toBeNull()
    expect(screen.queryByRole('link', { name: '«گروه‌ها»' })).toBeNull()
  })

  it('has nothing to save once a group is ticked off and on again, wherever it came back in the list', async () => {
    show([VIP, COLLEAGUES])

    fireEvent.click(await screen.findByRole('checkbox', { name: 'VIP' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'VIP' }))

    expect(save().getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: 'بازگردانی تغییرات' }).getAttribute('aria-disabled')).toBe('true')
  })
})
