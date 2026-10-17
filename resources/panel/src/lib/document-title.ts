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
    document.title = tabText([subject, where, shopName].filter(Boolean).join(' · '))
  }, [subject, where, shopName])
}

/** A Unicode isolate (lib/direction's `isolate`) and what it holds. */
const ISOLATED = /[\u{2066}-\u{2068}]([^\u{2066}-\u{2069}]*)\u{2069}/gu

/** A run whose first strong letter is a right-to-left one (Hebrew, Arabic, Persian), not a Latin one. */
const RTL_FIRST = /^[^A-Za-z\u{C0}-\u{24F}\u{590}-\u{8FF}\u{FB1D}-\u{FDFF}\u{FE70}-\u{FEFF}]*[\u{590}-\u{8FF}\u{FB1D}-\u{FDFF}\u{FE70}-\u{FEFF}]/u

/**
 * A title as the browser's tab and history can draw it: no isolates, which their text often lacks (a box, a gap) —
 * each run they set apart opened instead by the mark of its own direction (U+200F, U+200E), so «تیکت #12», its number
 * isolated, still reads «#12», not «12#».
 */
function tabText(title: string): string {
  return title.replace(ISOLATED, (_, run: string) => `${RTL_FIRST.test(run) ? '\u{200F}' : '\u{200E}'}${run}`).replace(/[\u{2066}-\u{2069}]/gu, '')
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
