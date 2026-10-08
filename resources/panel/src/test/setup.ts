import { notifyManager } from '@tanstack/react-query'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach, expect, vi } from 'vitest'
import { server, startServer } from '@/test/server'

/*
 * Around every test: a fresh fake server in place of `fetch` (src/test/server), and afterwards nothing left behind —
 * what was rendered unmounted, its spies undone, real timers, the address back at the owner's panel, the browser storage
 * empty (the stubbed globals go before the next test: `unstubGlobals`) — no request the test did not answer, and none
 * the API description does not take (src/test/contract).
 */

// react-query tells a cache's readers in a batch after a timer; in the tests, after the microtasks — the same batches in
// the same order, without waiting a timer's tick (15 ms on Windows) at every step.
notifyManager.setScheduler(queueMicrotask)

beforeEach(() => {
  vi.stubGlobal('fetch', startServer().fetch)
})

afterEach(() => {
  cleanup()
  // A test's spies and stubs (a storage it blocked) go before the clean-up below reaches for what they stand in for.
  vi.restoreAllMocks()
  vi.useRealTimers()
  window.history.replaceState(null, '', '/admin/')
  document.head.querySelectorAll('base').forEach((base) => base.remove())
  localStorage.clear()
  sessionStorage.clear()
  expect(
    server().unexpected.map((request) => `${request.method} ${request.path}`),
    'requests the test gave no answer for',
  ).toEqual([])
  expect(server().contractFailures(), 'requests the API description (resources/api/openapi.yaml) does not take').toEqual([])
})
