import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import type { Probe, ProbeResponse, ServerTestRequest } from '@/lib/api-types'
import { useProbe } from '@/lib/use-probe'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/**
 * A "test it" button beside a form (lib/use-probe): what the server said is kept until the next edit; its refusal too;
 * an answer about input that changed since is dropped.
 */

const PROBE: Probe = { ok: true, error: null, error_detail: null, status: null, inbounds: [], serves_subscriptions: true, subscription_probed: true }

/** A server's connection tried as its form stands. */
const draft = (base_url: string): ServerTestRequest => ({ driver: '3x-ui', name: 'آلمان', base_url, auth_mode: 'token', verify_tls: true, timeout: 30, is_active: true })

const probing = (body: ServerTestRequest) => () => api.post('/servers/test', body)

describe('a probe', () => {
  it('sends the draft and keeps what came back until the draft changes', async () => {
    server().on('POST', '/api/admin/servers/test', json({ probe: PROBE } satisfies ProbeResponse))
    const { result } = renderHook(() => useProbe(probing(draft('https://panel.example.com'))))

    await act(() => result.current.run())

    expect(result.current.result).toEqual({ probe: PROBE })
    expect(server().sent('POST', '/api/admin/servers/test')[0]?.body).toEqual(draft('https://panel.example.com'))

    act(() => result.current.clear())
    expect(result.current.result).toBeNull()
  })

  it('keeps a refusal, and hands it to the screen that shows it elsewhere', async () => {
    server().on('POST', '/api/admin/servers/test', refusal(422, 'آدرس پنل معتبر نیست.', { base_url: ['آدرس پنل معتبر نیست.'] }))
    const onFailure = vi.fn()
    const { result } = renderHook(() => useProbe(probing(draft('panel')), { onFailure }))

    await act(() => result.current.run())

    expect(result.current.failure?.field('base_url')).toBe('آدرس پنل معتبر نیست.')
    expect(onFailure).toHaveBeenCalledWith(result.current.failure)
    expect(result.current.busy).toBe(false)
  })

  it('drops an answer that comes after the draft changed — a refusal too, which it does not hand on', async () => {
    const answer = server().hold('POST', '/api/admin/servers/test')
    const onFailure = vi.fn()
    const { result } = renderHook(() => useProbe(probing(draft('https://old.example.com')), { onFailure }))

    let probed: Promise<ProbeResponse | undefined> | undefined
    act(() => {
      probed = result.current.run()
    })
    await until(() => expect(answer.waiting).toBe(1))
    act(() => result.current.clear())
    expect(result.current.busy).toBe(false)

    await act(async () => answer.answer(refusal(422, 'آدرس پنل معتبر نیست.', { base_url: ['آدرس پنل معتبر نیست.'] })))

    expect(await probed).toBeUndefined()
    expect(result.current.failure).toBeNull()
    expect(onFailure).not.toHaveBeenCalled()
  })

  it('keeps only the latest probe’s answer when two overlap', async () => {
    const answer = server().hold('POST', '/api/admin/servers/test')
    const { result, rerender } = renderHook(({ body }) => useProbe(probing(body)), { initialProps: { body: draft('https://first.example.com') } })

    act(() => void result.current.run())
    rerender({ body: draft('https://second.example.com') })
    act(() => void result.current.run())
    await until(() => expect(answer.waiting).toBe(2))

    await act(async () => answer.answer(json({ probe: { ...PROBE, ok: false } })))
    await act(async () => answer.answer(json({ probe: PROBE })))

    expect(result.current.result).toEqual({ probe: PROBE })
    expect(result.current.busy).toBe(false)
  })

  it('lets the panel’s own bug go on, to the net for those: it is no refusal of the server’s', async () => {
    const bug = new TypeError('x is undefined')
    const { result } = renderHook(() => useProbe(() => Promise.reject(bug)))

    await act(() => expect(result.current.run()).rejects.toBe(bug))

    expect(result.current.failure).toBeNull()
    expect(result.current.busy).toBe(false)
  })
})
