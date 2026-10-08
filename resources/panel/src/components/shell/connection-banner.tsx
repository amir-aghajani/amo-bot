import { CloudOff, WifiOff } from 'lucide-react'
import { useOnline } from '@/lib/use-online'

/**
 * A quiet strip over the page while the panel cannot follow the shop: the browser offline (its reads and changes wait
 * for the connection), or the server not answering the live poll (`unreachable`, useLiveUpdates) — the screens stay as
 * last read until it is back, and the strip goes by itself. Its region is always there, empty, so assistive tech hears
 * the strip as it comes.
 */
export function ConnectionBanner({ unreachable }: { unreachable: boolean }) {
  const online = useOnline()
  const Icon = online ? CloudOff : WifiOff
  const words = !online ? 'اتصال اینترنت قطع است؛ تا برگشتن آن صفحه‌ها به‌روز نمی‌شوند و تغییرها منتظر می‌مانند.' : unreachable ? 'سرور پاسخ نمی‌دهد؛ صفحه‌ها تا برگشتن ارتباط به‌روز نمی‌شوند.' : null

  return (
    <div role="status" className="sticky top-(--topbar-height) z-10 md:top-0">
      {words && (
        <p className="flex items-center justify-center gap-2 border-b border-warning-line/60 bg-warning-soft px-4 py-1.5 text-center text-footnote text-warning">
          <Icon className="size-3.5 shrink-0" aria-hidden />
          {words}
        </p>
      )}
    </div>
  )
}
