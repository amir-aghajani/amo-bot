import { toast } from 'sonner'
import { useApplyMiniApp } from '@/components/mini-app/mini-app-query'
import { api } from '@/lib/api'
import type { MiniAppSettings, MiniAppSettingsRequest } from '@/lib/api-types'
import { trimmed, useForm } from '@/lib/use-form'
import type { SettingsGroup } from '@/lib/use-settings-group'

/**
 * One mini-app-settings card as a form: its save PATCHes the card's own fields (`request`), and `dirty` is whatever that
 * PATCH would change. The answer — the app, and why the bot's menu button was not set when it was not — replaces the
 * page's read, and the card restarts from it (`draftOf`); another card's save leaves this draft alone.
 */
export function useMiniAppGroup<T extends object>(miniApp: MiniAppSettings, draftOf: (miniApp: MiniAppSettings) => T, request: (values: T) => MiniAppSettingsRequest): SettingsGroup<T> {
  const apply = useApplyMiniApp()
  const form = useForm(draftOf(miniApp), { follow: true, reads: (values) => trimmed(request(values)) })

  const save = async (): Promise<boolean> => {
    const { values } = form
    const result = await form.submit(() => api.patch('/mini-app', request(values)))
    if (result === undefined) return false
    apply(result)
    form.reset(draftOf(result.mini_app))
    toast.success('تنظیمات مینی‌اپ ذخیره شد')
    return true
  }

  return { ...form, save }
}
