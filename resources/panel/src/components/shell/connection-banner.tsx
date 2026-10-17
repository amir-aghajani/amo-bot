import { useSyncExternalStore } from 'react'
import { onlineManager } from '@tanstack/react-query'
import { CloudOff, WifiOff } from 'lucide-react'
import { PageStrip } from '@/components/page-strip'

const subscribeOnline = (changed: () => void) => onlineManager.subscribe(changed)

/**
 * A quiet strip over the page while the panel cannot follow the shop: the browser is offline (react-query's
 * onlineManager — reads and the live poll wait meanwhile, and a change fails at once, saved nowhere, lib/query-client),
 * or the server is not answering the live poll (`unreachable`, useLiveUpdates). It goes by itself. Its live region is
 * always there, empty, so assistive tech announces the strip when it comes.
 */
export function ConnectionBanner({ unreachable }: { unreachable: boolean }) {
  const online = useSyncExternalStore(subscribeOnline, () => onlineManager.isOnline())
  const words = !online ? 'اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها ذخیره نمی‌شوند.' : unreachable ? 'سرور پاسخ نمی‌دهد؛ صفحه‌ها تا برگشتن ارتباط به‌روز نمی‌شوند.' : null

  return (
    <div role="status" className="sticky top-(--topbar-height) z-10">
      {words && (
        <PageStrip tone="warning" icon={online ? CloudOff : WifiOff}>
          {words}
        </PageStrip>
      )}
    </div>
  )
}
