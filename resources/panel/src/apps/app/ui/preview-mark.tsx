import { Eye } from 'lucide-react'
import { inPreview } from '@/apps/app/telegram/webapp'

/*
 * «پیش‌نمایش»: in a panel's preview, a small pill says the app is seen as one of the shop's customers sees it. It sits
 * just under Telegram's header and controls, in the end corner — a screen's title starts at the other (RTL) —, 20px high
 * so it reaches no further than the top edge of the buttons there; it takes no tap (a tap reaches what is under it), and
 * is the page's own glass with the text's colour on it, which reads on either scheme over any shop colour.
 */

export function PreviewMark() {
  if (!inPreview()) return null

  return (
    <p className="glass text-micro text-text ring-separator pointer-events-none fixed end-2 top-[calc(var(--app-top)+2px)] z-50 inline-flex h-5 items-center gap-1 rounded-full px-2 leading-none font-semibold shadow-card ring-1">
      <Eye aria-hidden className="size-3 shrink-0" />
      پیش‌نمایش
    </p>
  )
}
