import { useLayoutEffect, useState } from 'react'
import type { QueryKey } from '@tanstack/react-query'
import type { BotSettingsResponse, BotSettingsSaved } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'
import type { Form } from '@/lib/use-form'
import { useSettingsGroup } from '@/lib/use-settings-group'
import { sameData } from '@/lib/utils'

interface BotSettingsGroupOptions<T> {
  /** What else shows the group's values, read again once it is saved. */
  invalidates?: readonly QueryKey[]
  /** What the values come to as the server reads them, where that is not their text trimmed (a list kept as a set). */
  reads?: (values: T) => unknown
}

/** No field kept open. */
const NONE: ReadonlySet<never> = new Set()

/** The fields whose values are not the server's — a list (rows, ids) by what it holds, as each render drafts it anew. */
function changedFields<T extends object>(values: T, saved: T): (keyof T)[] {
  const changed: (keyof T)[] = []
  for (const field in values) if (!sameData(values[field], saved[field])) changed.push(field)
  return changed
}

/**
 * Which of a group's fields wait, held, while `waiting` (a program switched off): those as the server keeps them
 * (`saved`). One changed stays open — the save carries every field, the program on or not, and one the server refuses
 * must be there to fix —, typed back to the kept value too, so it is never disabled under the typist's focus: until the
 * program is switched on (nothing waits), or the card is saved or reverted (nothing typed since; the form's `touched`)
 * and the focus is out of the text field it was in — a save sent with Enter from one leaves it there.
 */
export function useHeldWhile<T extends object>(waiting: boolean, { values, touched }: Pick<Form<T>, 'values' | 'touched'>, saved: T): (field: keyof T) => boolean {
  const [open, setOpen] = useState<ReadonlySet<keyof T>>(NONE)

  // Adjusted during render: switched on, nothing is kept open; typed while it waits, a field off its kept value is.
  if (!waiting) {
    if (open.size > 0) setOpen(NONE)
  } else if (touched) {
    const opened = changedFields(values, saved).filter((field) => !open.has(field))
    if (opened.length > 0) setOpen(new Set([...open, ...opened]))
  }

  // Saved or reverted, nothing typed since: the fields kept open wait again — at once, or as the focus leaves the text
  // field it is in, so as not to drop it on the page.
  const settled = waiting && !touched && open.size > 0
  useLayoutEffect(() => {
    if (!settled) return
    const release = () => setOpen(NONE)
    const focused = document.activeElement
    if (!(focused instanceof HTMLInputElement || focused instanceof HTMLTextAreaElement)) {
      release()
      return
    }
    focused.addEventListener('blur', release, { once: true })
    return () => focused.removeEventListener('blur', release)
  }, [settled])

  return (field) => waiting && sameData(values[field], saved[field]) && !open.has(field)
}

/**
 * One bot-settings group as a card's form: the draft starts from `initial` and follows the server's copy; `save` PUTs
 * the group as typed (the server reads numbers the way an admin types them), and the answered settings go into the
 * page's cached copy.
 */
export function useBotSettingsGroup<T extends object>(initial: T, save: (values: T) => Promise<BotSettingsSaved>, { invalidates, reads }: BotSettingsGroupOptions<T> = {}) {
  return useSettingsGroup<T, BotSettingsResponse, BotSettingsSaved>({
    save,
    queryKey: queryKeys.botSettings,
    apply: (current, result) => current && { ...current, settings: result.settings },
    initial,
    saved: 'تنظیمات ربات ذخیره شد',
    invalidates,
    reads,
  })
}
