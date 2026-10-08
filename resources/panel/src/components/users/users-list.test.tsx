import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { UsersList } from '@/components/users/users-list'
import type { CustomerGroupsResponse, UsersResponse } from '@/lib/api-types'
import { UsersPage } from '@/pages/users'
import { providers, until } from '@/test/render'
import { userRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * The customers' list (components/users/users-list): each customer's number beside them — what «#12» in the search box
 * finds alone, as every list takes it —, the bot's admins alone by the role's pill (where the website's settings send the
 * owner to see who its admins are), a search or a filter that found nobody says what to change: the search, the filters,
 * or both; and a row's dialog goes with the list when its section goes out of sight.
 */

const META = { page: 1, per_page: 25, sort: 'joined', dir: 'desc' } as const

/** The list at `at`, the server answering `users`. */
function open(at: string, users = [userRow()]) {
  server()
    .on('GET', '/api/admin/users', json({ users, meta: { ...META, total: users.length, last_page: 1 } } satisfies UsersResponse))
    .on('GET', '/api/admin/customer-groups', json({ groups: [] } satisfies CustomerGroupsResponse))
  render(<UsersList />, { wrapper: providers({ at }).wrapper })
}

describe('the customers’ list', () => {
  it('shows each customer’s number', async () => {
    open('/users')

    expect(await screen.findByText('#10')).toBeTruthy()
  })

  it('shows a website customer’s email where the handle would be, and offers no chat in Telegram they do not have', async () => {
    open('/users', [userRow({ id: 11, name: 'سارا', username: null, telegram_id: null, email: 'sara@example.com' })])

    expect((await screen.findByText('sara@example.com')).getAttribute('dir')).toBe('ltr')
    fireEvent.pointerDown(screen.getByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    expect(await screen.findByRole('menuitem', { name: 'کیف پول' })).toBeTruthy()
    expect(screen.queryByRole('menuitem', { name: 'گفتگو در تلگرام' })).toBeNull()
  })
})

/** The role each read of the list asked for — null for none. */
const roles = () =>
  server()
    .sent('GET', '/api/admin/users')
    .map((request) => request.query.get('role'))

describe('the role’s pill', () => {
  it('narrows the list to the bot’s admins from the address the website’s settings link to', async () => {
    open('/users?role=admin', [userRow({ role: 'admin' })])

    await screen.findByText('#10')
    expect(roles()).toEqual(['admin'])
    expect(screen.getByRole('combobox', { name: 'نقش مدیران ربات' })).toBeTruthy()
  })

  it('reads a role it does not offer as no filter', async () => {
    open('/users?role=owner')

    await screen.findByText('#10')
    expect(roles()).toEqual([null])
    expect(screen.getByRole('combobox', { name: 'نقش همه' })).toBeTruthy()
  })
})

describe('a list that found nobody', () => {
  it('asks for a shorter search when the search found nobody', async () => {
    open('/users', [])

    fireEvent.change(screen.getByRole('searchbox', { name: 'جستجوی کاربر' }), { target: { value: 'کسی' } })

    expect(await screen.findByText('با این جستجو کسی نیست. عبارت را کوتاه‌تر کنید.')).toBeTruthy()
  })

  it('asks for the filters off when a filter alone found nobody — no search to shorten', async () => {
    open('/users?status=banned', [])

    expect(await screen.findByText('با این فیلتر کسی نیست.')).toBeTruthy()
    expect(screen.queryByText(/کوتاه‌تر/)).toBeNull()
  })

  it('asks for either when both narrow it', async () => {
    open('/users?status=banned&search=amir', [])

    await until(() => expect(screen.getByText('با این جستجو و فیلتر کسی نیست. عبارت را کوتاه‌تر کنید یا فیلترها را بردارید.')).toBeTruthy())
  })
})

describe('a row’s dialog', () => {
  it('closes as a link in it leads to the page’s other section — never left open over it', async () => {
    server()
      .on('GET', '/api/admin/users', json({ users: [userRow()], meta: { ...META, total: 1, last_page: 1 } } satisfies UsersResponse))
      .on('GET', '/api/admin/customer-groups', json({ groups: [] } satisfies CustomerGroupsResponse))
    render(<UsersPage />, { wrapper: providers({ at: '/users' }).wrapper })
    fireEvent.pointerDown(await screen.findByRole('button', { name: /^عملیات / }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
    fireEvent.click(await screen.findByRole('menuitem', { name: 'گروه‌ها' }))
    const dialog = (await screen.findByRole('dialog', { name: /^گروه‌های / })) as HTMLDialogElement

    fireEvent.click(await within(dialog).findByRole('link', { name: '«گروه‌ها»' }))

    await until(() => expect(dialog.open).toBe(false))
    expect(screen.getByRole('region', { name: 'گروه‌ها' }).hidden).toBe(false)
  })
})
