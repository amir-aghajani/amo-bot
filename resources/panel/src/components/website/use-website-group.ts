import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import type { SecretState, Website, WebsiteRequest, WebsiteResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import { trimmed, useForm } from '@/lib/use-form'
import type { SettingsGroup } from '@/lib/use-settings-group'

/** A secret the website keeps, as its field (SecretField) shows it: nothing of it comes back, so the field says what leaving it blank does. */
export function keptSecret(kept: boolean): SecretState {
  return { set: kept, hint: 'برای نگه‌داشتن مقدار فعلی خالی بگذارید' }
}

/**
 * One card of the website's settings as a form (a SectionCard's): its save PATCHes the card's own fields (`request`) —
 * the server keeps the rest as it is, whatever another card saves meanwhile —, and what is unsaved is what that PATCH
 * would say (a driver's fields out of sight, a secret left blank are none of it). What the server answers — the website
 * whole — goes into the page's read in place of it, where the other cards find it without a read, and the card starts
 * again from the website as it is now kept (`draftOf`): in the server's own form, a secret typed gone with the save. The
 * draft follows the card's own values only — another card's save, a new key, leave it as it is.
 */
export function useWebsiteGroup<T extends object>(website: Website, draftOf: (website: Website) => T, request: (values: T) => WebsiteRequest): SettingsGroup<T> {
  const queryClient = useQueryClient()
  const form = useForm(draftOf(website), { follow: true, reads: (values) => trimmed(request(values)) })

  const save = async (): Promise<boolean> => {
    const { values } = form
    const result = await form.submit(() => api.patch('/website', request(values)))
    if (result === undefined) return false
    queryClient.setQueryData<WebsiteResponse>(queryKeys.website, result)
    form.reset(draftOf(result.website))
    toast.success('تنظیمات وب‌سایت ذخیره شد')
    return true
  }

  return { ...form, save }
}
