import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GroupsList } from '@/components/users/groups-list'
import type { CustomerGroupRow, CustomerGroupsResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { groupRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The admin's groups of customers (components/users/groups-list, components/users/group-form): deleting one says who is
 * in it — the verb by the count, one customer «است», more «هستند» — and that nobody's account is touched. A «همکار»
 * group — its customers pay plans' agent prices, as the agents do — is marked so beside its name, what that means
 * behind a press of the mark; its form's switch says it, and its save sends it.
 */

const group = (id: number, name: string, users: number): CustomerGroupRow => groupRow({ id, name, sort: id, counts: { users } })

const AGENT_PRICES = 'مشتری‌های این گروه مثل نماینده‌ها قیمت نماینده پلن‌ها را می‌پردازند.'

function showGroups(groups: CustomerGroupRow[]) {
  server().on('GET', '/api/admin/customer-groups', json({ groups } satisfies CustomerGroupsResponse))
  render(<GroupsList />, { wrapper: providers().wrapper })
}

/** The delete of `name` asked: its dialog. */
async function deleting(name: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات ${name}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
  return within(await screen.findByRole('dialog', { name: 'حذف گروه' }))
}

describe('deleting a group', () => {
  it('says who is in it, the verb by the count', async () => {
    showGroups([group(1, 'VIP', 1), group(2, 'همکاران', 4)])

    expect((await deleting('VIP')).getByText('یک مشتری در این گروه است؛ فقط از گروه بیرون می‌آید و حسابش دست نمی‌خورد.')).toBeTruthy()
  })

  it('— more than one', async () => {
    showGroups([group(2, 'همکاران', 4)])

    expect((await deleting('همکاران')).getByText('۴ مشتری در این گروه هستند؛ فقط از گروه بیرون می‌آیند و حسابشان دست نمی‌خورد.')).toBeTruthy()
  })
})

describe('a «همکار» group', () => {
  it('is marked so beside its name — what it means behind a press —, and no other group is', async () => {
    showGroups([groupRow({ id: 1, name: 'همکاران', agent_pricing: true }), groupRow({ id: 2, name: 'VIP', sort: 2 })])

    const mark = await screen.findByRole('button', { name: 'همکار' })
    expect(screen.getAllByRole('button', { name: 'همکار' })).toHaveLength(1)
    expect(mark.closest('tr')?.textContent).toContain('همکاران')

    fireEvent.click(mark)
    expect(await screen.findByText(AGENT_PRICES)).toBeTruthy()
  })

  it('made: its switch, off to start with, says what it is — and the save sends it', async () => {
    showGroups([])
    server().on('POST', '/api/admin/customer-groups', json({ group: groupRow({ id: 5, name: 'همکاران', agent_pricing: true }) }))

    fireEvent.click((await screen.findAllByRole('button', { name: 'افزودن گروه' }))[0]!)
    const dialog = within(await screen.findByRole('dialog', { name: 'افزودن گروه' }))
    const toggle = dialog.getByRole('switch', { name: 'همکار' })
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    expect(dialog.getByText(AGENT_PRICES)).toBeTruthy()
    fireEvent.change(dialog.getByLabelText('نام گروه'), { target: { value: 'همکاران' } })
    fireEvent.click(toggle)
    fireEvent.click(dialog.getByRole('button', { name: 'افزودن گروه' }))

    await until(() => expect(server().sent('POST', '/api/admin/customer-groups')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/customer-groups')[0]?.body).toEqual({ name: 'همکاران', agent_pricing: true })
    expect(await screen.findByRole('button', { name: 'همکار' })).toBeTruthy()
  })

  it('switched off from its form goes as it is, its name with it', async () => {
    showGroups([groupRow({ id: 1, name: 'همکاران', agent_pricing: true })])
    server().on('PUT', '/api/admin/customer-groups/1', json({ group: groupRow({ id: 1, name: 'همکاران' }) }))

    fireEvent.click(await screen.findByRole('button', { name: 'همکاران' }))
    const dialog = within(await screen.findByRole('dialog', { name: 'ویرایش «همکاران»' }))
    const toggle = dialog.getByRole('switch', { name: 'همکار' })
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)
    fireEvent.click(dialog.getByRole('button', { name: 'ذخیره تغییرات' }))

    await until(() => expect(server().sent('PUT', '/api/admin/customer-groups/1')).toHaveLength(1))
    expect(server().sent('PUT', '/api/admin/customer-groups/1')[0]?.body).toEqual({ name: 'همکاران', agent_pricing: false })
    await until(() => expect(screen.queryByRole('button', { name: 'همکار' })).toBeNull())
  })
})
