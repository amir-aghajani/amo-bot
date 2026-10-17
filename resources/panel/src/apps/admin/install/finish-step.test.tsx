import { QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, describe, expect, it } from 'vitest'
import { FinishStep } from '@/apps/admin/install/finish-step'
import { forgetKey, hasKey, keepKey } from '@/apps/admin/install/install-api'
import type { AppInfo, InstallFinished, InstallStatus } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { testQueryClient, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The installer's last step (apps/admin/install/finish-step): what the installation did, what the owner does next — the
 * bot's webhook and the cron, both from the panel, nothing to run on the host —, and the end: the key forgotten and the
 * panel on its sign-in; refused, the server's words say what is missing.
 */

const STATUS: InstallStatus = {
  requirements: [],
  ready: true,
  database: {
    driver: 'mysql',
    drivers: [{ key: 'mysql', label: 'MySQL / MariaDB', description: 'یک دیتابیس خالی بسازید.', notes: [], traits: { installable: true }, fields: [] }],
    values: {},
    connected: true,
    tables: true,
    error: null,
  },
  admin: { configured: true, username: 'owner' },
  site: { name: 'AmoBot', url: 'https://shop.example' },
  telegram: { configured: true, username: 'amo_bot' },
}

afterEach(() => forgetKey())

function open() {
  const client = testQueryClient()
  client.setQueryData<AppInfo>(queryKeys.app, { name: 'AmoBot', installed: false, timezone: 'UTC' })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/install']}>
        <Routes>
          <Route path="/install" element={<FinishStep status={STATUS} />} />
          <Route path="/login" element={<p>صفحه ورود</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return client
}

describe('the installer’s end', () => {
  it('says what comes next — the bot’s webhook and the cron, both from the panel — and names no command to run', () => {
    open()

    const next = screen.getByText('بعد از ورود:').closest('[class*="border-info-line"]')?.textContent ?? ''
    expect(next).toContain('«ثبت Webhook»')
    expect(next).toContain('«تنظیمات پنل › پیشرفته»')
    expect(document.body.textContent).not.toMatch(/bin\/console|php |curl|ssh/)
  })

  it('ends the installation: the key forgotten, the shop installed, the panel on its sign-in', async () => {
    server().on('POST', '/api/install/finish', json({ installed: true } satisfies InstallFinished))
    keepKey('K7QX-M2PA-9DWE-HT3C')
    const client = open()

    fireEvent.click(screen.getByRole('button', { name: 'پایان نصب و ورود به پنل' }))

    expect(await screen.findByText('صفحه ورود')).toBeTruthy()
    expect(hasKey()).toBe(false)
    expect(client.getQueryData<AppInfo>(queryKeys.app)?.installed).toBe(true)
  })

  it('refused, says what is missing and stays', async () => {
    const missing = 'ورود پنل هنوز تعیین نشده است.'
    server().on('POST', '/api/install/finish', refusal(422, missing))
    keepKey('K7QX-M2PA-9DWE-HT3C')
    open()

    fireEvent.click(screen.getByRole('button', { name: 'پایان نصب و ورود به پنل' }))

    await until(() => expect(screen.getByRole('alert').textContent).toContain(missing))
    expect(hasKey()).toBe(true)
    expect(screen.queryByText('صفحه ورود')).toBeNull()
  })
})
