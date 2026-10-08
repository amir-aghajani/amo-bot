import { useEffect, useSyncExternalStore } from 'react'
import { useShopName } from '@/lib/auth'

/*
 * The tab's name, and so the history's: what is on screen, most particular first — the subject a page has open (a
 * server), where it is (a page's section, the page; a sign-in, the installer) —, then the name the panel goes by: the
 * shop it shows once signed in (two tabs in two shops say which is which), the shop's own before. The shell and the
 * pages outside it say where (`useDocumentTitle`); a page that opens one subject names it once it knows it
 * (`useTitleSubject`).
 */

const subjects: { name: string }[] = []
const listeners = new Set<() => void>()
const changed = () => listeners.forEach((listener) => listener())

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** The tab's name: the subject named on the screen (if any), `where`, the shop. */
export function useDocumentTitle(where: string): void {
  const subject = useSyncExternalStore(subscribe, () => subjects.at(-1)?.name ?? null)
  const shopName = useShopName()

  useEffect(() => {
    document.title = [subject, where, shopName].filter(Boolean).join(' · ')
  }, [subject, where, shopName])
}

/** What the page on screen has open (a server's name), first in the tab's name while it is mounted. */
export function useTitleSubject(name: string | null | undefined): void {
  useEffect(() => {
    if (!name) return
    const subject = { name }
    subjects.push(subject)
    changed()
    return () => {
      subjects.splice(subjects.indexOf(subject), 1)
      changed()
    }
  }, [name])
}
