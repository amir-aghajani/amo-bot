import type { ReactNode } from 'react'
import { renderHook } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { useAttempt } from '@/apps/app/parts/checkout/attempt'
import { ApiError } from '@/lib/api'
import { blockStorage } from '@/test/browser'

/*
 * An attempt's Idempotency-Key (parts/checkout/attempt): the same for the same request until the shop answers it — made
 * (a 2xx) or refused (a 4xx) —, kept through no answer and a server failure, and kept for the screen opened again (this
 * tab's storage), by the screen's address and the request.
 */

const RENEWAL = { method_id: 1, method: 'extend', expected_amount: '108000.00' }

/** The attempt of a screen at `at`, mounted afresh. */
function attemptAt(at = '/services/9/renew') {
  const wrapper = ({ children }: { children: ReactNode }) => <MemoryRouter initialEntries={[at]}>{children}</MemoryRouter>
  return renderHook(() => useAttempt(), { wrapper }).result.current
}

const offline = new ApiError(0, 'ارتباط برقرار نشد.', {}, { offline: true })
const failure = new ApiError(500, 'خطای سرور')
const refusal = new ApiError(422, 'این روش پرداخت در دسترس نیست.', { method_id: ['این روش پرداخت در دسترس نیست.'] })

describe('an attempt', () => {
  it('keeps its key through no answer and a server failure; a request changed is another attempt', () => {
    const attempt = attemptAt()
    const key = attempt.key(RENEWAL)

    attempt.failed(offline, RENEWAL)
    expect(attempt.key({ ...RENEWAL })).toBe(key)
    attempt.failed(failure, RENEWAL)
    expect(attempt.key(RENEWAL)).toBe(key)
    expect(attempt.key({ ...RENEWAL, method_id: 2 })).not.toBe(key)
  })

  it('outlives its screen: opened again, the same request goes with the same key', () => {
    const key = attemptAt().key(RENEWAL)
    attemptAt().failed(offline, RENEWAL)

    expect(attemptAt().key(RENEWAL)).toBe(key)
    // Another screen's request is another attempt, the same body notwithstanding.
    expect(attemptAt('/services/10/renew').key(RENEWAL)).not.toBe(key)
  })

  it('ends with a refusal, and with an answer', () => {
    const first = attemptAt().key(RENEWAL)
    attemptAt().failed(refusal, RENEWAL)
    const second = attemptAt().key(RENEWAL)
    expect(second).not.toBe(first)

    attemptAt().done(RENEWAL)
    expect(attemptAt().key(RENEWAL)).not.toBe(second)
  })

  it('a day on, the same request is a new attempt', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    const key = attemptAt().key(RENEWAL)
    vi.setSystemTime(Date.now() + 86_400_000)

    expect(attemptAt().key(RENEWAL)).not.toBe(key)
  })

  it('without storage, keeps its key while the screen is open', () => {
    blockStorage()
    const attempt = attemptAt()
    const key = attempt.key(RENEWAL)
    attempt.failed(offline, RENEWAL)

    expect(attempt.key(RENEWAL)).toBe(key)
    attempt.done(RENEWAL)
    expect(attempt.key(RENEWAL)).not.toBe(key)
  })
})
