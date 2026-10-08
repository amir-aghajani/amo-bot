import { describe, expect, it } from 'vitest'
import { forgetKey, hasKey, installApi, keepKey, keyRefusal } from '@/apps/admin/install/install-api'
import { firstOpen, isDone } from '@/apps/admin/install/steps'
import { ApiError } from '@/lib/api'
import type { InstallStatus } from '@/lib/api-types'
import { json, server } from '@/test/server'

/*
 * The web installer (apps/admin/install): it opens on the first step the installation still needs, shows which are
 * done, and every request carries the install key the owner typed — the one the host's storage/install-key.txt holds.
 */

function status(ready: boolean, tables: boolean, admin: boolean): InstallStatus {
  return {
    requirements: [],
    ready,
    database: { driver: 'mysql', drivers: [], values: {}, connected: tables, tables, error: null },
    admin: { configured: admin, username: admin ? 'owner' : '' },
    site: { name: 'AmoBot', url: '' },
    telegram: { configured: false, username: '' },
  }
}

describe('the installer’s steps', () => {
  it('open on the first one the installation still needs', () => {
    expect(firstOpen(status(false, true, true))).toBe('requirements')
    expect(firstOpen(status(true, false, true))).toBe('database')
    expect(firstOpen(status(true, true, false))).toBe('admin')
    expect(firstOpen(status(true, true, true))).toBe('site')
  })

  it('are done as the installation stands — the site once the installer went past it, the finish never', () => {
    const almost = status(true, true, true)
    const steps = ['requirements', 'database', 'admin', 'site', 'finish'] as const
    expect(steps.map((step) => isDone(step, almost, 'site'))).toEqual([true, true, true, false, false])
    expect(steps.map((step) => isDone(step, almost, 'finish'))).toEqual([true, true, true, true, false])
    expect(isDone('database', status(true, false, true), 'database')).toBe(false)
  })
})

describe('the install key', () => {
  it('goes with every request, as the owner typed it (trimmed), from when it is typed', async () => {
    server().on('GET', '/api/install', json(status(true, true, true)))

    await installApi.get('')
    keepKey('  K7QX-M2PA-9DWE-HT3C \n')
    await installApi.get('')

    expect(
      server()
        .sent('GET', '/api/install')
        .map((request) => request.headers['x-install-key']),
    ).toEqual(['', 'K7QX-M2PA-9DWE-HT3C'])
    expect(hasKey()).toBe(true)
  })

  it('is kept for the tab only, and forgotten once the installation ends', () => {
    keepKey('K7QX-M2PA-9DWE-HT3C')
    expect(localStorage.length).toBe(0)

    forgetKey()

    expect(hasKey()).toBe(false)
  })

  it('refused is a 403 on `key`, in the server’s words; any other failure is not about the key', () => {
    expect(keyRefusal(new ApiError(403, 'Refused.', { key: ['کلید نصب درست نیست.'] }))).toBe('کلید نصب درست نیست.')
    expect(keyRefusal(new ApiError(422, 'Refused.', { key: ['x'] }))).toBeUndefined()
    expect(keyRefusal(new ApiError(0, 'ارتباط با سرور برقرار نشد.'))).toBeUndefined()
  })
})
