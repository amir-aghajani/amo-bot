import type { ReactNode } from 'react'
import { LoaderCircle } from 'lucide-react'
import { Outlet } from 'react-router'
import { ErrorState } from '@/components/error-state'
import { useAppInfo } from '@/lib/app-info'
import { MAIN_SHOP, shopHome } from '@/lib/config'

/**
 * The whole screen while the panel waits for the server: its first answers, or a page's chunk before the shell. Each
 * panel's index.html draws the same markup until the script takes over — keep the two alike.
 */
export function AppLoading({ label = 'در حال بارگذاری…' }: { label?: string }) {
  return (
    <div className="flex min-h-svh items-center justify-center gap-2 text-body text-muted-foreground" role="status">
      <LoaderCircle className="size-4 animate-spin" aria-hidden />
      {label}
    </div>
  )
}

/**
 * A panel's routes once it knows the shop (/api/app — asked first), or the screens while it is asked or when it cannot
 * be: what came instead, and a way to ask again.
 */
export function AppReady() {
  const info = useAppInfo()

  if (info.isPending) return <AppLoading />
  if (info.isError) return <ErrorState variant="screen" error={info.error} onRetry={() => void info.refetch()} retrying={info.isFetching} />
  return <Outlet />
}

/**
 * The routes under it while the shop's installation is `done` (or not yet, for the installer's): `otherwise` in their
 * place — the other side's address, or a word. Behind AppReady, which knows.
 */
export function Installation({ done, otherwise }: { done: boolean; otherwise: ReactNode }) {
  return useAppInfo().data?.installed === done ? <Outlet /> : otherwise
}

/**
 * The owner's tab at the address of a shop that is not there (`/admin/s/<id>/…` — a mistyped address, an old link): the
 * server answers its every request so (a 404 on `shop`), and the screen says it, with the way to the main shop.
 */
export function MissingShop({ failure }: { failure: unknown }) {
  return <ErrorState variant="screen" error={failure} title="این فروشگاه پیدا نشد" home={{ href: shopHome(MAIN_SHOP), label: 'رفتن به فروشگاه اصلی' }} />
}
