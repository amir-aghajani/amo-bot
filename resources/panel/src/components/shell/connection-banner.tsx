import { useSyncExternalStore } from 'react'
import { onlineManager } from '@tanstack/react-query'
import { CloudOff, WifiOff } from 'lucide-react'

const subscribeOnline = (changed: () => void) => onlineManager.subscribe(changed)

/**
 * A quiet strip over the page while the panel cannot follow the shop: the browser is offline (react-query's
 * onlineManager — reads, writes and the live poll wait meanwhile), or the server is not answering the live poll
 * (`unreachable`, useLiveUpdates). It goes by itself. Its live region is always there, empty, so assistive tech
 * announces the strip when it comes.
 */
export function ConnectionBanner({ unreachable }: { unreachable: boolean }) {
  const online = useSyncExternalStore(subscribeOnline, () => onlineManager.isOnline())
  const Icon = online ? CloudOff : WifiOff
  const words = !online ? 'اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها منتظر می‌مانند.' : unreachable ? 'سرور پاسخ نمی‌دهد؛ صفحه‌ها تا برگشتن ارتباط به‌روز نمی‌شوند.' : null

  return (
    <div role="status" className="sticky top-(--topbar-height) z-10">
      {words && (
        <p className="flex items-center justify-center gap-2 border-b border-warning-line/60 bg-warning-soft px-4 py-1.5 text-center text-footnote text-warning">
          <Icon className="size-3.5 shrink-0" aria-hidden />
          {words}
        </p>
      )}
    </div>
  )
}
