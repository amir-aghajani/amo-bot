import { useEffect, useSyncExternalStore } from 'react'
import { useShopName } from '@/lib/auth'
import { createListeners } from '@/lib/listeners'

/*
 * The browser tab's title, most specific part first: the subject a page has open (a server's name), where the admin is
 * (the section, the page, or a sign-in screen), then the shop's name — so two tabs in two shops can be told apart.
 */

const subjects: { name: string }[] = []
const changes = createListeners()

/** Set the tab's title to `<subject> · <where> · <shop>`; the shell and the screens outside it call this. */
export function useDocumentTitle(where: string): void {
  const subject = useSyncExternalStore(changes.subscribe, () => subjects.at(-1)?.name ?? null)
  const shopName = useShopName()

  useEffect(() => {
    document.title = [subject, where, shopName].filter(Boolean).join(' · ')
  }, [subject, where, shopName])
}

/** Put the subject a page has open (a server's name) first in the tab's title while the page is mounted. */
export function useTitleSubject(name: string | null | undefined): void {
  useEffect(() => {
    if (!name) return
    const subject = { name }
    subjects.push(subject)
    changes.notify()
    return () => {
      subjects.splice(subjects.indexOf(subject), 1)
      changes.notify()
    }
  }, [name])
}
