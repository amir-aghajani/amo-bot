import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useForm, type Form } from '@/lib/use-form'

interface UseSettingsGroupOptions<T extends object, Cached, Result> {
  /** The group's save, written by the screen (`api.put(...)`): a secret is sent only when typed. */
  save: (values: T) => Promise<Result>
  /** The screen's query; the saved copy goes back into its cached data. */
  queryKey: QueryKey
  /** Fold the save's answer into the screen's cached data. */
  apply: (current: Cached | undefined, result: Result) => Cached | undefined
  /** The group's values as the server has them: the draft starts from them and follows them. */
  initial: T
  /** The toast once saved. */
  saved: string
  /** Other reads that show the group's values (the shop's name in the brand), refetched once it is saved. */
  invalidates?: readonly QueryKey[]
  /** The values as the server reads them, when that is not "every text trimmed" (useForm's `reads`). */
  reads?: (values: T) => unknown
}

/** A settings group's form with its save: what a settings card (SectionCard) is drawn from. */
export interface SettingsGroup<T extends object> extends Form<T> {
  /** Saves the group; true once saved. */
  save: () => Promise<boolean>
}

/**
 * One settings card: a form whose `save()` PUTs the group and puts the answer into the screen's cache, so every card on
 * the page sees the saved state without a read. The draft follows its own group's values only, so another card's save
 * leaves it alone.
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
