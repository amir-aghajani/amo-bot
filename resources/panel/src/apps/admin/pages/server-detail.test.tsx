import { act, fireEvent, render, screen } from '@testing-library/react'
import { Link, Route, Routes } from 'react-router'
import { describe, expect, it } from 'vitest'
import { ServerDetailPage } from '@/apps/admin/pages/server-detail'
import type { ServerDetailResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { signedIn, testQueryClient, until } from '@/test/render'
import { serverDriverRow, serverRow } from '@/test/rows'
import { refusal, server, silent } from '@/test/server'

/*
 * A server's page (apps/admin/pages/server-detail): the route stays mounted from one server's address to another's, so
 * each server's page starts afresh — its connection's form shows that server, never the last one's values; a later read
 * that fails is said above the page, which stays as it was.
 */

const GERMANY = serverRow()
const NETHERLANDS = serverRow({ id: 2, name: 'هلند', base_url: 'https://nl.example.com:2053/panel', form: { ...GERMANY.form, name: 'هلند', base_url: 'https://nl.example.com:2053/panel' } })

/** Server 1's page, both servers read already, with a link to server 2's. */
function open() {
  const client = testQueryClient()
  for (const row of [GERMANY, NETHERLANDS]) {
    client.setQueryData<ServerDetailResponse>(queryKeys.server(row.id), { server: row, inbounds: [] })
    // The cards' own reads have nothing to say here.
    for (const card of ['test-account', 'grants', 'migrations']) server().on('GET', `/api/admin/servers/${row.id}/${card}`, silent)
  }
  client.setQueryData(queryKeys.serverDrivers, [serverDriverRow()])
  render(
    <>
      <Link to="/servers/2">سرور دوم</Link>
      <Routes>
        <Route path="servers/:id" element={<ServerDetailPage />} />
      </Routes>
    </>,
    { wrapper: signedIn({ client, at: '/servers/1' }).wrapper },
  )
  return client
}

describe('a server’s page whose later read fails', () => {
  it('stays as it was — the connection’s draft with it —, the failure said above it', async () => {
    const client = open()
    server().on('GET', '/api/admin/servers/1', refusal(500, 'خطای سرور.'))
    const name = await screen.findByLabelText<HTMLInputElement>(/نام سرور/)
    fireEvent.change(name, { target: { value: 'آلمان ۲' } })

    // The live updates read the server again, and the read fails.
    await act(() => client.invalidateQueries({ queryKey: queryKeys.server(1) }))

    expect(await screen.findByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('آلمان')
    expect(screen.getByLabelText<HTMLInputElement>(/نام سرور/).value).toBe('آلمان ۲')
  })
})

describe('another server’s page', () => {
  it('shows that server’s connection, not the last one’s', async () => {
    open()
    expect((await screen.findByLabelText<HTMLInputElement>(/نام سرور/)).value).toBe('آلمان')

    fireEvent.click(screen.getByRole('link', { name: 'سرور دوم' }))

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('هلند'))
    expect(screen.getByLabelText<HTMLInputElement>(/نام سرور/).value).toBe('هلند')
    expect(screen.getByLabelText<HTMLInputElement>(/آدرس پنل/).value).toBe(NETHERLANDS.base_url)
  })
})
