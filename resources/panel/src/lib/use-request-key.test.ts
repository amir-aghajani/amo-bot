import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useRequestKey } from '@/lib/use-request-key'

/*
 * The Idempotency-Key of a change a form sends (lib/use-request-key): the same while the same change goes again, a new
 * one for another change and after one went through — each part compared as it is, a picked file by itself.
 */

describe('a change’s key', () => {
  it('stays while the same change is sent again, and is another for another change', () => {
    const { result } = renderHook(() => useRequestKey())
    const first = result.current.keyFor(['add', '50000', 'هدیه'])

    expect(first).toMatch(/^[0-9a-f]{32}$/)
    expect(result.current.keyFor(['add', '50000', 'هدیه'])).toBe(first)
    const edited = result.current.keyFor(['add', '60000', 'هدیه'])
    expect(edited).not.toBe(first)
    expect(result.current.keyFor(['add', '50000', 'هدیه']), 'the key held is the last change sent').not.toBe(first)
  })

  it('is a new one once the change went through, the same change again included', () => {
    const { result, rerender } = renderHook(() => useRequestKey())
    const first = result.current.keyFor(['remove', '1.5', ''])

    result.current.done()
    rerender()

    expect(result.current.keyFor(['remove', '1.5', ''])).not.toBe(first)
  })

  it('tells two pictures of one name apart', () => {
    const { result } = renderHook(() => useRequestKey())
    const picture = new File(['a'], 'speed.png', { type: 'image/png' })
    const first = result.current.keyFor(['بررسی می‌کنیم.', picture])

    expect(result.current.keyFor(['بررسی می‌کنیم.', picture])).toBe(first)
    expect(result.current.keyFor(['بررسی می‌کنیم.', new File(['b'], 'speed.png', { type: 'image/png' })])).not.toBe(first)
  })
})
