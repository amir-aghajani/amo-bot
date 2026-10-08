import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useForm, type Form } from '@/lib/use-form'

interface UseSettingsGroupOptions<T extends object, Cached, Result> {
  /** The group's save: its PUT of the draft as the screen writes it (lib/api types it by its address) — a secret only when typed. */
  save: (values: T) => Promise<Result>
  /** The screen's query, whose cached data the saved copy goes back into. */
  queryKey: QueryKey
  /** Fold what the PUT answered into the cached screen data (the whole response, or one part of it). */
  apply: (current: Cached | undefined, result: Result) => Cached | undefined
  /** The group's values as the server has them: the draft starts from them and follows them when they change. */
  initial: T
  /** The toast once it is saved. */
  saved: string
  /** Other reads the group's values show in, read again once it is saved — the shop's name in the brand, a page's rules (the save's `meta.invalidates`). */
  invalidates?: readonly QueryKey[]
  /** What the values come to as the server reads them, where that is not their text trimmed: the measure of unsaved (useForm's `reads`). */
  reads?: (values: T) => unknown
}

/** A settings group's form, with its save: what a settings card (SectionCard) is drawn from. */
export interface SettingsGroup<T extends object> extends Form<T> {
  /** PUTs the group; true once it is saved. */
  save: () => Promise<boolean>
}

/**
 * One settings group's card (env settings, bot settings, the agency's rules): a form whose `save()` PUTs the group and
 * hands the answer back to the screen's cache, so every card on the page sees the saved state without a read. The draft
 * follows the group's own values only — another card's save leaves it as it is.
 */
export function useSettingsGroup<T extends object, Cached, Result>({ save: send, queryKey, apply, initial, saved, invalidates, reads }: UseSettingsGroupOptions<T, Cached, Result>): SettingsGroup<T> {
  const queryClient = useQueryClient()
  const form = useForm(initial, { follow: true, reads })

  const save = async (): Promise<boolean> => {
    const result = await form.submit(() => send(form.values), { invalidates })
    if (result === undefined) return false
    queryClient.setQueryData<Cached>(queryKey, (current) => apply(current, result))
    toast.success(saved)
    return true
  }

  return { ...form, save }
}
