import { renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import type { AppInfo } from '@/lib/api-types'
import { useAppInfo } from '@/lib/app-info'
import { formatDate, setShopTimeZone } from '@/lib/format'
import { providers, until } from '@/test/render'
import { json, server } from '@/test/server'

/*
 * What the panel asks first (GET /api/app): the shop's name, whether it is installed — and its time zone, which every
 * date the panel shows reads in from then on, as the bot words them, whatever zone the browser is in (UTC here).
 */

afterEach(() => {
  setShopTimeZone(undefined)
})

describe('the shop as the panel learns it', () => {
  it('shows every date in the shop’s zone once it is known', async () => {
    server().on('GET', '/api/app', json({ name: 'فروشگاه امو', installed: true, timezone: 'Asia/Tehran' } satisfies AppInfo))
    const { result } = renderHook(() => useAppInfo(), { wrapper: providers().wrapper })

    await until(() => expect(result.current.data?.name).toBe('فروشگاه امو'))

    expect(formatDate('2026-10-05T21:00:00Z')).toBe('۱۴ مهر ۱۴۰۵، ۰:۳۰')
  })
})
