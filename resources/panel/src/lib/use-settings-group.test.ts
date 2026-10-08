import { act, renderHook } from '@testing-library/react'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'
import { providers } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * A settings card (lib/use-settings-group): its save PUTs the group and folds the answer into the screen's cache, so
 * every card sees the saved state without a read; what else shows the values is read again; a refusal stays on the card.
 */

interface Screen {
  settings: Record<string, unknown>
}

const WALLET = { topup_min: '50000', topup_presets: '100000,200000' }

function card() {
  const { client, wrapper } = providers()
  client.setQueryData<Screen>(queryKeys.botSettings, { settings: { ...WALLET, enabled: 'yes' } })
  const invalidated = vi.spyOn(client, 'invalidateQueries')
  const hook = renderHook(
    ({ initial }) =>
      useSettingsGroup({
        save: (values: typeof WALLET) => api.put('/bot/settings/wallet', values),
        queryKey: queryKeys.botSettings,
        apply: (current: Screen | undefined, result) => current && { settings: { ...current.settings, ...result.settings } },
        initial,
        saved: 'تنظیمات کیف پول ذخیره شد',
        invalidates: [queryKeys.dashboards],
      }),
    { initialProps: { initial: WALLET }, wrapper },
  )
  return { ...hook, client, invalidated }
}

beforeEach(() => {
  vi.spyOn(toast, 'success')
})

describe('a settings card', () => {
  it('saves its group, into the screen’s cache, reading again what else shows it', async () => {
    server().on('PUT', '/api/admin/bot/settings/wallet', json({ settings: { topup_min: '60000', topup_presets: '100000,200000' } }))
    const { result, client, invalidated } = card()
    act(() => result.current.set('topup_min', '60000'))

    let saved: boolean | undefined
    await act(async () => {
      saved = await result.current.save()
    })

    expect(saved).toBe(true)
    expect(server().sent('PUT', '/api/admin/bot/settings/wallet')[0]?.body).toEqual({ ...WALLET, topup_min: '60000' })
    expect(client.getQueryData<Screen>(queryKeys.botSettings)?.settings).toEqual({ topup_min: '60000', topup_presets: '100000,200000', enabled: 'yes' })
    expect(invalidated.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([queryKeys.dashboards])
    expect(toast.success).toHaveBeenCalledWith('تنظیمات کیف پول ذخیره شد')
    expect(result.current.dirty).toBe(false)
  })

  it('keeps a refusal on the card and the screen’s cache as it was — nothing else read again, and no toast of the refusal', async () => {
    vi.spyOn(toast, 'error')
    server().on('PUT', '/api/admin/bot/settings/wallet', refusal(422, 'Refused.', { topup_min: ['حداقل شارژ باید بیشتر از صفر باشد.'] }))
    const { result, client, invalidated } = card()
    act(() => result.current.set('topup_min', '0'))

    let saved: boolean | undefined
    await act(async () => {
      saved = await result.current.save()
    })

    expect(saved).toBe(false)
    expect(result.current.error('topup_min')).toBe('حداقل شارژ باید بیشتر از صفر باشد.')
    expect(client.getQueryData<Screen>(queryKeys.botSettings)?.settings.topup_min).toBe('50000')
    expect(toast.success).not.toHaveBeenCalled()
    expect(toast.error).not.toHaveBeenCalled()
    expect(invalidated).not.toHaveBeenCalled()
  })

  it('keeps its draft when another card’s save hands it the same values again', () => {
    const { result, rerender } = card()
    act(() => result.current.set('topup_min', '70000'))

    rerender({ initial: { ...WALLET } })

    expect(result.current.values.topup_min).toBe('70000')
  })
})
