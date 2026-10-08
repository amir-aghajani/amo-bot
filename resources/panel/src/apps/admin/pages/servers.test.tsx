import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ServersPage } from '@/apps/admin/pages/servers'
import type { ServersResponse } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { serverRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/* The owner's servers (apps/admin/pages/servers): the page counts them once their list was read — never a zero it does not know. */

function open(answer = json({ servers: [serverRow()] } satisfies ServersResponse)) {
  server().on('GET', '/api/admin/servers', answer)
  render(<ServersPage />, { wrapper: providers().wrapper })
}

const title = () => screen.getByRole('heading', { level: 1 }).textContent

describe('the servers page', () => {
  it('counts the servers it read', async () => {
    open()

    await until(() => expect(title()).toBe('سرورها۱'))
  })

  it('counts nothing when its list could not be read', async () => {
    open(refusal(500, 'خطای سرور.'))

    expect(await screen.findByText('لیست سرورها بارگذاری نشد.')).toBeTruthy()
    expect(title()).toBe('سرورها')
  })
})
