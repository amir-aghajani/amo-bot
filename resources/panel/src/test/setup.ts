import { notifyManager } from '@tanstack/react-query'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach, expect, vi } from 'vitest'
import { server, startServer } from '@/test/server'

/*
 * Around every test: a fresh fake server in place of `fetch` before it; after it, nothing left behind (rendered trees
 * unmounted, spies restored, real timers, the address back at the owner's panel, storage emptied) and two checks —
 * every request was answered by the test, and the API description takes every request sent.
 */

// react-query notifies readers in batches after a timer; here after the microtasks instead — the same batches in the
// same order, without a timer's tick (15 ms on Windows) at every step.
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
