import { act, renderHook } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { useServer } from '@/components/servers/use-server'
import type { Probe, ServerCheckResponse, ServerDetailResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { serverRow } from '@/test/rows'
import { json, server } from '@/test/server'

/*
 * A server's page (components/servers/use-server): «بررسی اتصال» that finds the panel out of reach says why in its
 * toast — the panel's diagnosis, as a refused inbounds sync does —, the status card showing it whole.
 */

const DOWN: Probe = {
  ok: false,
  error: 'پنل در زمان تعیین‌شده جواب نداد؛ آدرس و پورت را بررسی کنید.',
  error_detail: 'cURL error 28: Operation timed out',
  status: null,
  inbounds: null,
  serves_subscriptions: null,
  subscription_probed: false,
}

describe('checking a server', () => {
  it('says why the panel could not be reached', async () => {
    vi.spyOn(toast, 'error')
    server()
      .on('GET', '/api/admin/servers/1', json({ server: serverRow(), inbounds: [] } satisfies ServerDetailResponse))
      .on('POST', '/api/admin/servers/1/test', json({ server: serverRow({ last_error: DOWN.error }), inbounds: [], probe: DOWN } satisfies ServerCheckResponse))
    const { result } = renderHook(() => useServer(1, null), { wrapper: providers().wrapper })

    await act(() => result.current.check.mutateAsync())

    expect(toast.error).toHaveBeenCalledWith('اتصال برقرار نشد', { description: DOWN.error })
    expect(result.current.probe).toEqual(DOWN)
  })
})
