import { beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { isStaleBuildError, loadAhead, loadingAhead, reloadForNewBuild } from '@/lib/stale-build'
import { STORAGE_KEYS } from '@/lib/storage'
import { blockStorage } from '@/test/browser'

/** A tab opened before an upgrade asks for the old build's files, which are gone: it reloads once — never in a loop. */

describe('a stale build', () => {
  it('is recognised by the error messages each browser and Vite give for a page file that did not load', () => {
    const messages = [
      'Failed to fetch dynamically imported module: https://shop.example/admin/assets/plans-Bx1.js',
      'error loading dynamically imported module: https://shop.example/admin/assets/plans-Bx1.js',
      'Importing a module script failed.',
      'Unable to preload CSS for /admin/assets/index-Cq2.css',
      "'text/html' is not a valid JavaScript MIME type.",
    ]
    for (const message of messages) {
      expect(isStaleBuildError(new TypeError(message)), message).toBe(true)
    }
  })

  it('is not any other failure', () => {
    expect(isStaleBuildError(new Error('Cannot read properties of undefined'))).toBe(false)
    expect(isStaleBuildError(new TypeError('Failed to fetch'))).toBe(false)
    expect(isStaleBuildError('Failed to fetch dynamically imported module')).toBe(false)
    expect(isStaleBuildError(null)).toBe(false)
  })
})

describe('reloading for a new build', () => {
  let reload: MockInstance<() => void>

  beforeEach(() => {
    reload = vi.spyOn(window.location, 'reload').mockImplementation(() => undefined)
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-06T10:00:00Z'))
  })

  it('reloads the tab and remembers when, for the tab only', () => {
    expect(reloadForNewBuild()).toBe(true)

    expect(reload).toHaveBeenCalledTimes(1)
    expect(sessionStorage.getItem(STORAGE_KEYS.reloadedAt)).toBe(String(Date.now()))
    expect(localStorage.getItem(STORAGE_KEYS.reloadedAt)).toBeNull()
  })

  it('does not reload again within 30 seconds: the reload did not help', () => {
    reloadForNewBuild()
    vi.advanceTimersByTime(29_999)

    expect(reloadForNewBuild()).toBe(false)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('reloads again for a build that came out later', () => {
    reloadForNewBuild()
    vi.advanceTimersByTime(30_000)

    expect(reloadForNewBuild()).toBe(true)
    expect(reload).toHaveBeenCalledTimes(2)
  })

  it('reloads all the same where the browser keeps nothing — once: a page that is itself a reload of a moment ago does not reload again', () => {
    blockStorage()
    vi.spyOn(performance, 'now').mockReturnValue(5_000)

    expect(reloadForNewBuild()).toBe(true)
    expect(reload).toHaveBeenCalledTimes(1)

    // The page that reload brought, its files still missing.
    const reloaded = { name: '', entryType: 'navigation', startTime: 0, duration: 0, toJSON: () => ({}), type: 'reload' }
    vi.spyOn(performance, 'getEntriesByType').mockReturnValue([reloaded])
    expect(reloadForNewBuild()).toBe(false)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('does not reload while the browser is offline: the file is missing because the network is', () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false)

    expect(reloadForNewBuild()).toBe(false)
    expect(reload).not.toHaveBeenCalled()
    expect(sessionStorage.getItem(STORAGE_KEYS.reloadedAt)).toBeNull()
  })
})

describe('a load ahead of need', () => {
  it('is under way until it settles, and its failure says nothing', async () => {
    const gone = Promise.reject(new TypeError('Failed to fetch dynamically imported module: /admin/assets/users-Bx1.js'))
    loadAhead(() => gone)

    expect(loadingAhead()).toBe(true)
    await vi.waitFor(() => expect(loadingAhead()).toBe(false))
  })
})
